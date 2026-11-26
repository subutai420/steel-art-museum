<?php
/**
 * Přehled tržeb v administraci (menu „Tržby“), jen pro přihlášené s oprávněním manage_woocommerce.
 *
 * - Počítá se přímo z objednávek WooCommerce (HPOS): zaplacené objednávky podle data vytvoření,
 *   vratky se odečítají v den, kdy byly provedeny. Částky jsou včetně DPH.
 * - Objednávky jsou v Kč i v EUR. Součet „≈ Kč“ přepočítává eura aktuálním kurzem ČNB
 *   (načítá se nejvýš jednou za 12 hodin, při výpadku se použije poslední známý kurz).
 * - Jde jen o prodej přes web; prodej na pokladně muzea tu není.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Sales {
	const SLUG        = 'sam-sales';
	const CAP         = 'manage_woocommerce';
	const RATE_OPTION = 'sam_sales_eur_rate';
	const RATE_CACHE  = 'sam_sales_eur_rate_fresh';
	const PAID        = array( 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-uplatneno' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_menu_page( 'Přehled tržeb', 'Tržby', self::CAP, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-chart-bar', 55.4 );
	}

	/* ---------------- data ---------------- */

	/** Kurz EUR → Kč podle ČNB. Vrací array( kurz, datum nebo '' ). */
	private static function rate() {
		$stored = get_option( self::RATE_OPTION );
		$stored = is_array( $stored ) && ! empty( $stored['rate'] ) ? $stored : array( 'rate' => 24.5, 'date' => '' );
		if ( get_transient( self::RATE_CACHE ) ) {
			return $stored;
		}
		set_transient( self::RATE_CACHE, 1, 12 * HOUR_IN_SECONDS );
		$res = wp_remote_get( 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt', array( 'timeout' => 4 ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$body = wp_remote_retrieve_body( $res );
			if ( preg_match( '/\|EUR\|(\d+,\d+)/', $body, $m ) ) {
				$rate = (float) str_replace( ',', '.', $m[1] );
				if ( $rate > 15 && $rate < 40 ) {
					$stored = array(
						'rate' => $rate,
						'date' => preg_match( '/^(\d{2}\.\d{2}\.\d{4})/', $body, $d ) ? $d[1] : '',
					);
					update_option( self::RATE_OPTION, $stored, false );
				}
			}
		}
		return $stored;
	}

	private static function paid_sql() {
		return "'" . implode( "','", self::PAID ) . "'";
	}

	/** Zaplacené objednávky a vratky v daném rozmezí (časové značky, konec není zahrnut). */
	private static function rows( $from_ts, $to_ts ) {
		global $wpdb;
		$paid = self::paid_sql();
		$sql  = "SELECT o.id, o.type, o.currency, o.total_amount AS total, o.tax_amount AS tax, o.date_created_gmt AS d,
				MAX(l.meta_value) AS lang, MAX(s.meta_value) AS src
			FROM {$wpdb->prefix}wc_orders o
			LEFT JOIN {$wpdb->prefix}wc_orders_meta l ON l.order_id = o.id AND l.meta_key = 'wpml_language'
			LEFT JOIN {$wpdb->prefix}wc_orders_meta s ON s.order_id = o.id AND s.meta_key = '_wc_order_attribution_utm_source'
			WHERE o.date_created_gmt >= %s AND o.date_created_gmt < %s
				AND ( ( o.type = 'shop_order' AND o.status IN ( $paid ) ) OR o.type = 'shop_order_refund' )
			GROUP BY o.id";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, gmdate( 'Y-m-d H:i:s', $from_ts ), gmdate( 'Y-m-d H:i:s', $to_ts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $r ) {
			$r->ts    = strtotime( $r->d . ' UTC' );
			$r->total = (float) $r->total;
			$r->tax   = (float) $r->tax;
		}
		return $rows;
	}

	/** Prodané položky podle typu vstupenky (před vratkami). */
	private static function items( $from_ts, $to_ts ) {
		global $wpdb;
		$paid = self::paid_sql();
		$p    = $wpdb->prefix;
		$sql  = "SELECT pm.meta_value AS pid, o.currency, MAX(oi.order_item_name) AS name,
				SUM(q.meta_value) AS qty, SUM(lt.meta_value + IFNULL(tx.meta_value, 0)) AS total
			FROM {$p}woocommerce_order_items oi
			JOIN {$p}wc_orders o ON o.id = oi.order_id
			JOIN {$p}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
			JOIN {$p}woocommerce_order_itemmeta q ON q.order_item_id = oi.order_item_id AND q.meta_key = '_qty'
			JOIN {$p}woocommerce_order_itemmeta lt ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
			LEFT JOIN {$p}woocommerce_order_itemmeta tx ON tx.order_item_id = oi.order_item_id AND tx.meta_key = '_line_tax'
			WHERE oi.order_item_type = 'line_item' AND o.type = 'shop_order' AND o.status IN ( $paid )
				AND o.date_created_gmt >= %s AND o.date_created_gmt < %s
			GROUP BY pm.meta_value, o.currency";
		return $wpdb->get_results( $wpdb->prepare( $sql, gmdate( 'Y-m-d H:i:s', $from_ts ), gmdate( 'Y-m-d H:i:s', $to_ts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function blank() {
		return array( 'czk' => 0.0, 'eur' => 0.0, 'tax_czk' => 0.0, 'tax_eur' => 0.0, 'orders' => 0, 'ref_czk' => 0.0, 'ref_eur' => 0.0, 'ref_n' => 0 );
	}

	private static function add( array &$a, $r ) {
		$c = 'EUR' === $r->currency ? 'eur' : 'czk';
		$a[ $c ]          += $r->total;
		$a[ 'tax_' . $c ] += $r->tax;
		if ( 'shop_order' === $r->type ) {
			++$a['orders'];
		} else {
			$a[ 'ref_' . $c ] += -$r->total;
			++$a['ref_n'];
		}
	}

	private static function sum( array $rows, $from_ts, $to_ts ) {
		$a = self::blank();
		foreach ( $rows as $r ) {
			if ( $r->ts >= $from_ts && $r->ts < $to_ts ) {
				self::add( $a, $r );
			}
		}
		return $a;
	}

	/* ---------------- období ---------------- */

	private static function ranges() {
		return array(
			'today'      => 'Dnes',
			'yesterday'  => 'Včera',
			'7d'         => '7 dní',
			'30d'        => '30 dní',
			'month'      => 'Tento měsíc',
			'last_month' => 'Minulý měsíc',
			'year'       => 'Tento rok',
			'last_year'  => 'Minulý rok',
		);
	}

	/** Vrací array( klíč, od, do, předchozí od, předchozí do ) – DateTimeImmutable, „do“ je první den po období. */
	private static function period() {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$end   = $today->modify( '+1 day' );
		$key   = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$re    = '/^\d{4}-\d{2}-\d{2}$/';

		if ( 'custom' === $key && preg_match( $re, $from ) && preg_match( $re, $to ) ) {
			$a = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $tz );
			$b = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $tz );
			if ( $a && $b ) {
				if ( $b < $a ) {
					list( $a, $b ) = array( $b, $a );
				}
				$b    = $b->modify( '+1 day' );
				$days = (int) $a->diff( $b )->days;
				return array( 'custom', $a, $b, $a->modify( "-$days days" ), $a );
			}
		}
		switch ( $key ) {
			case 'today':
				return array( $key, $today, $end, $today->modify( '-1 day' ), $today );
			case 'yesterday':
				return array( $key, $today->modify( '-1 day' ), $today, $today->modify( '-2 days' ), $today->modify( '-1 day' ) );
			case '7d':
				return array( $key, $end->modify( '-7 days' ), $end, $end->modify( '-14 days' ), $end->modify( '-7 days' ) );
			case '30d':
				return array( $key, $end->modify( '-30 days' ), $end, $end->modify( '-60 days' ), $end->modify( '-30 days' ) );
			case 'last_month':
				$a = $today->modify( 'first day of last month' );
				return array( $key, $a, $today->modify( 'first day of this month' ), $a->modify( 'first day of last month' ), $a );
			case 'year':
				$a = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				return array( $key, $a, $end, $a->modify( '-1 year' ), $end->modify( '-1 year' ) );
			case 'last_year':
				$b = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				return array( $key, $b->modify( '-1 year' ), $b, $b->modify( '-2 years' ), $b->modify( '-1 year' ) );
			default:
				// Tento měsíc do dneška; srovnání se stejně dlouhým začátkem minulého měsíce.
				$a    = $today->modify( 'first day of this month' );
				$pa   = $a->modify( 'first day of last month' );
				$days = (int) $a->diff( $end )->days;
				$pb   = min( $pa->modify( "+$days days" ), $a );
				return array( 'month', $a, $end, $pa, $pb );
		}
	}

	/* ---------------- formátování ---------------- */

	private static function kc( $v ) {
		return number_format( round( $v ), 0, ',', "\u{a0}" ) . "\u{a0}Kč";
	}

	private static function eur( $v ) {
		return number_format( $v, abs( $v - round( $v ) ) < 0.005 ? 0 : 2, ',', "\u{a0}" ) . "\u{a0}€";
	}

	private static function num( $v ) {
		return number_format( $v, 0, ',', "\u{a0}" );
	}

	private static function delta( $now, $prev ) {
		if ( $prev <= 0 ) {
			return '<span class="ss-delta ss-flat">bez srovnání</span>';
		}
		$pct = ( $now - $prev ) / $prev * 100;
		if ( abs( $pct ) < 0.5 ) {
			return '<span class="ss-delta ss-flat">beze změny</span>';
		}
		$up = $pct > 0;
		return '<span class="ss-delta ' . ( $up ? 'ss-up' : 'ss-down' ) . '">' . ( $up ? '▲ +' : '▼ −' ) . esc_html( number_format( abs( $pct ), abs( $pct ) < 10 ? 1 : 0, ',', "\u{a0}" ) ) . "\u{a0}%</span>";
	}

	private static function source_label( $src ) {
		$s = strtolower( trim( (string) $src ) );
		if ( '' === $s ) {
			return 'Neznámý';
		}
		$map = array(
			'(direct)'  => 'Přímý vstup',
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'google'    => 'Google',
			'seznam'    => 'Seznam',
			'mapy.'     => 'Mapy.com',
			'bing'      => 'Bing',
			'print'     => 'Tisk (QR)',
		);
		if ( 'fb' === $s ) {
			return 'Facebook';
		}
		if ( 'ig' === $s ) {
			return 'Instagram';
		}
		foreach ( $map as $needle => $label ) {
			if ( false !== strpos( $s, $needle ) ) {
				return $label;
			}
		}
		return $s;
	}

	/* ---------------- stránka ---------------- */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nemáte oprávnění zobrazit tuto stránku.' );
		}
		list( $key, $from, $to, $pfrom, $pto ) = self::period();
		$tz        = wp_timezone();
		$today     = new DateTimeImmutable( 'today', $tz );
		$tomorrow  = $today->modify( '+1 day' );
		$year_from = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
		$rate_info = self::rate();
		$rate      = (float) $rate_info['rate'];
		$total     = function ( array $a ) use ( $rate ) {
			return $a['czk'] + $a['eur'] * $rate;
		};

		$load_from = min( $year_from, $pfrom, $from )->getTimestamp();
		$rows      = self::rows( $load_from, $tomorrow->getTimestamp() );

		$cur  = self::sum( $rows, $from->getTimestamp(), $to->getTimestamp() );
		$prev = self::sum( $rows, $pfrom->getTimestamp(), $pto->getTimestamp() );

		$fixed = array(
			'Dnes'        => array( $today, $tomorrow ),
			'Včera'       => array( $today->modify( '-1 day' ), $today ),
			'Tento měsíc' => array( $today->modify( 'first day of this month' ), $tomorrow ),
			'Tento rok'   => array( $year_from, $tomorrow ),
		);

		$items      = self::items( $from->getTimestamp(), $to->getTimestamp() );
		$prev_items = self::items( $pfrom->getTimestamp(), $pto->getTimestamp() );
		$qty        = array_sum( wp_list_pluck( $items, 'qty' ) );
		$prev_qty   = array_sum( wp_list_pluck( $prev_items, 'qty' ) );

		$gross      = $total( $cur ) + $cur['ref_czk'] + $cur['ref_eur'] * $rate;
		$prev_gross = $total( $prev ) + $prev['ref_czk'] + $prev['ref_eur'] * $rate;
		$aov        = $cur['orders'] ? $gross / $cur['orders'] : 0;
		$prev_aov   = $prev['orders'] ? $prev_gross / $prev['orders'] : 0;
		$refunds    = $cur['ref_czk'] + $cur['ref_eur'] * $rate;
		$net_no_tax = $total( $cur ) - ( $cur['tax_czk'] + $cur['tax_eur'] * $rate );

		$days      = (int) $from->diff( $to )->days;
		$last_day  = $to->modify( '-1 day' );
		$range_txt = 1 === $days ? wp_date( 'j. n. Y', $from->getTimestamp() ) : wp_date( 'j. n. Y', $from->getTimestamp() ) . ' – ' . wp_date( 'j. n. Y', $last_day->getTimestamp() );
		$prev_txt  = wp_date( 'j. n. Y', $pfrom->getTimestamp() ) . ( (int) $pfrom->diff( $pto )->days > 1 ? ' – ' . wp_date( 'j. n. Y', $pto->modify( '-1 day' )->getTimestamp() ) : '' );
		$base_url  = admin_url( 'admin.php?page=' . self::SLUG );

		self::styles();
		echo '<div class="wrap sam-sales"><h1>Přehled tržeb</h1>';
		echo '<p class="ss-note">Online prodej vstupenek přes web, částky včetně DPH. Eura jsou do součtů přepočtena kurzem ČNB ' . esc_html( number_format( $rate, 3, ',', '' ) ) . "\u{a0}Kč/€" . ( $rate_info['date'] ? ' (' . esc_html( $rate_info['date'] ) . ')' : '' ) . '.</p>';

		// Pevný přehled.
		echo '<div class="ss-strip">';
		foreach ( $fixed as $label => $span ) {
			$a = self::sum( $rows, $span[0]->getTimestamp(), $span[1]->getTimestamp() );
			echo '<div class="ss-card"><div class="ss-label">' . esc_html( $label ) . '</div><div class="ss-big">' . esc_html( self::kc( $total( $a ) ) ) . '</div><div class="ss-sub">' . esc_html( self::num( $a['orders'] ) ) . ' obj.</div></div>';
		}
		echo '</div>';

		// Filtr období – jeden řádek nad grafem.
		echo '<div class="ss-filter"><div class="ss-tabs">';
		foreach ( self::ranges() as $k => $label ) {
			echo '<a class="' . ( $k === $key ? 'on' : '' ) . '" href="' . esc_url( add_query_arg( 'range', $k, $base_url ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div><form method="get" class="ss-custom' . ( 'custom' === $key ? ' on' : '' ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="range" value="custom">'
			. '<label>Od <input type="date" name="from" value="' . esc_attr( $from->format( 'Y-m-d' ) ) . '" max="' . esc_attr( $today->format( 'Y-m-d' ) ) . '"></label>'
			. '<label>Do <input type="date" name="to" value="' . esc_attr( min( $last_day, $today )->format( 'Y-m-d' ) ) . '" max="' . esc_attr( $today->format( 'Y-m-d' ) ) . '"></label>'
			. '<button class="button">Zobrazit</button></form></div>';

		echo '<h2 class="ss-h">' . esc_html( $range_txt ) . ' <span>srovnání s obdobím ' . esc_html( $prev_txt ) . '</span></h2>';

		// Ukazatele za období.
		echo '<div class="ss-kpis">';
		$kpi = function ( $label, $value, $delta, $sub ) {
			echo '<div class="ss-card"><div class="ss-label">' . esc_html( $label ) . '</div><div class="ss-big">' . esc_html( $value ) . '</div><div class="ss-sub">' . $delta . ( $sub ? ' <span>' . esc_html( $sub ) . '</span>' : '' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};
		$kpi( 'Tržby po vratkách', self::kc( $total( $cur ) ), self::delta( $total( $cur ), $total( $prev ) ), self::kc( $cur['czk'] ) . ' + ' . self::eur( $cur['eur'] ) );
		$kpi( 'Objednávky', self::num( $cur['orders'] ), self::delta( $cur['orders'], $prev['orders'] ), 'dříve ' . self::num( $prev['orders'] ) );
		$kpi( 'Průměrná objednávka', self::kc( $aov ), self::delta( $aov, $prev_aov ), 'dříve ' . self::kc( $prev_aov ) );
		$kpi( 'Prodané vstupenky', self::num( $qty ) . "\u{a0}ks", self::delta( $qty, $prev_qty ), 'rodinná = 1 ks' );
		$kpi( 'Vratky', self::kc( $refunds ), '', $cur['ref_n'] . '× v období' );
		$kpi( 'Tržby bez DPH', self::kc( $net_no_tax ), '', 'DPH ' . self::kc( $total( $cur ) - $net_no_tax ) );
		echo '</div>';

		self::chart( $rows, $from, $to, $days, $rate );

		// Tabulky.
		echo '<div class="ss-tables">';

		// Typy vstupenek – překlady produktů (WPML) se slučují pod český originál.
		$types = array();
		foreach ( $items as $it ) {
			$pid  = (int) $it->pid;
			$orig = (int) apply_filters( 'wpml_object_id', $pid, 'product', true, 'cs' );
			$name = $orig && 'product' === get_post_type( $orig ) ? get_post_field( 'post_title', $orig ) : html_entity_decode( (string) $it->name, ENT_QUOTES, 'UTF-8' );
			$k    = $orig && 'product' === get_post_type( $orig ) ? 'p' . $orig : 'n' . $name;
			if ( ! isset( $types[ $k ] ) ) {
				$types[ $k ] = array( 'name' => $name, 'qty' => 0, 'total' => 0.0 );
			}
			$types[ $k ]['qty']   += (float) $it->qty;
			$types[ $k ]['total'] += (float) $it->total * ( 'EUR' === $it->currency ? $rate : 1 );
		}
		self::table( 'Typy vstupenek', 'před vratkami', $types, array( 'Vstupenka', 'Kusů', 'Tržby' ) );

		$langs   = array();
		$sources = array();
		foreach ( $rows as $r ) {
			if ( 'shop_order' !== $r->type || $r->ts < $from->getTimestamp() || $r->ts >= $to->getTimestamp() ) {
				continue;
			}
			$value = $r->total * ( 'EUR' === $r->currency ? $rate : 1 );
			$lang  = array( 'cs' => 'Čeština', 'en' => 'Angličtina', 'de' => 'Němčina' );
			$l     = ( $lang[ (string) $r->lang ] ?? ( $r->lang ? strtoupper( $r->lang ) : 'Neznámý' ) ) . ' · ' . ( 'EUR' === $r->currency ? 'EUR' : 'Kč' );
			$s     = self::source_label( $r->src );
			foreach ( array( array( &$langs, $l ), array( &$sources, $s ) ) as $pair ) {
				if ( ! isset( $pair[0][ $pair[1] ] ) ) {
					$pair[0][ $pair[1] ] = array( 'name' => $pair[1], 'qty' => 0, 'total' => 0.0 );
				}
				++$pair[0][ $pair[1] ]['qty'];
				$pair[0][ $pair[1] ]['total'] += $value;
			}
		}
		self::table( 'Zdroj návštěvy', 'poslední zdroj před nákupem', $sources, array( 'Zdroj', 'Objednávek', 'Tržby' ), 9 );
		self::table( 'Jazyk a měna', 'před vratkami', $langs, array( 'Jazyk · měna', 'Objednávek', 'Tržby' ) );
		echo '</div></div>';
	}

	private static function table( $title, $hint, array $data, array $head, $limit = 0 ) {
		uasort(
			$data,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		if ( $limit && count( $data ) > $limit ) {
			$rest  = array_slice( $data, $limit - 1 );
			$data  = array_slice( $data, 0, $limit - 1 );
			$other = array( 'name' => 'Ostatní (' . count( $rest ) . ')', 'qty' => 0, 'total' => 0.0 );
			foreach ( $rest as $r ) {
				$other['qty']   += $r['qty'];
				$other['total'] += $r['total'];
			}
			$data[] = $other;
		}
		$sum = array_sum( wp_list_pluck( $data, 'total' ) );
		echo '<div class="ss-card ss-table"><h3>' . esc_html( $title ) . ' <span>' . esc_html( $hint ) . '</span></h3>';
		if ( ! $data ) {
			echo '<p class="ss-empty">V tomto období nejsou žádné objednávky.</p></div>';
			return;
		}
		echo '<table><thead><tr><th>' . esc_html( $head[0] ) . '</th><th class="r">' . esc_html( $head[1] ) . '</th><th class="r">' . esc_html( $head[2] ) . '</th><th class="r">Podíl</th></tr></thead><tbody>';
		foreach ( $data as $row ) {
			$share = $sum > 0 ? $row['total'] / $sum * 100 : 0;
			echo '<tr><td>' . esc_html( $row['name'] ) . '<i class="ss-share" style="width:' . esc_attr( round( $share, 1 ) ) . '%"></i></td><td class="r">' . esc_html( self::num( $row['qty'] ) ) . '</td><td class="r">' . esc_html( self::kc( $row['total'] ) ) . '</td><td class="r">' . esc_html( number_format( $share, 1, ',', '' ) ) . "\u{a0}%</td></tr>";
		}
		echo '</tbody></table></div>';
	}

	/** Sloupcový graf tržeb po dnech, týdnech nebo měsících; Kč a EUR (v přepočtu) nad sebou. */
	private static function chart( array $rows, DateTimeImmutable $from, DateTimeImmutable $to, $days, $rate ) {
		$mode   = $days <= 62 ? 'day' : ( $days <= 190 ? 'week' : 'month' );
		$bucket = function ( DateTimeImmutable $d ) use ( $mode ) {
			if ( 'week' === $mode ) {
				return $d->modify( 'monday this week' )->format( 'Y-m-d' );
			}
			return $d->format( 'month' === $mode ? 'Y-m' : 'Y-m-d' );
		};
		$tz      = wp_timezone();
		$buckets = array();
		for ( $d = $from; $d < $to; $d = $d->modify( '+1 day' ) ) {
			$k = $bucket( $d );
			if ( ! isset( $buckets[ $k ] ) ) {
				$buckets[ $k ] = self::blank() + array( 'start' => $d );
			}
		}
		foreach ( $rows as $r ) {
			if ( $r->ts < $from->getTimestamp() || $r->ts >= $to->getTimestamp() ) {
				continue;
			}
			$k = $bucket( ( new DateTimeImmutable( '@' . $r->ts ) )->setTimezone( $tz ) );
			if ( isset( $buckets[ $k ] ) ) {
				self::add( $buckets[ $k ], $r );
			}
		}

		$max = 0;
		foreach ( $buckets as $b ) {
			$max = max( $max, max( 0, $b['czk'] ) + max( 0, $b['eur'] ) * $rate );
		}
		// „Hezký“ krok osy: 1, 2 nebo 5 × mocnina deseti, zhruba čtyři dílky.
		$step = 1;
		if ( $max > 0 ) {
			$raw  = $max / 4;
			$pow  = pow( 10, floor( log10( $raw ) ) );
			$step = $pow * ( $raw / $pow <= 1 ? 1 : ( $raw / $pow <= 2 ? 2 : ( $raw / $pow <= 5 ? 5 : 10 ) ) );
		}
		$top   = max( $step, ceil( $max / $step ) * $step );
		$n     = count( $buckets );
		$every = max( 1, (int) ceil( $n / 12 ) );
		$unit  = array( 'day' => 'po dnech', 'week' => 'po týdnech', 'month' => 'po měsících' );

		echo '<div class="ss-card ss-chartcard"><h3>Tržby po vratkách <span>' . esc_html( $unit[ $mode ] ) . ', ≈ Kč</span></h3>';
		echo '<div class="ss-legend"><span><i class="c"></i>Objednávky v Kč</span><span><i class="e"></i>Objednávky v EUR (přepočet)</span></div>';
		echo '<div class="ss-chart"><div class="ss-plot">';
		for ( $v = 0; $v <= $top + 0.001; $v += $step ) {
			echo '<div class="ss-grid" style="bottom:' . esc_attr( round( $v / $top * 100, 3 ) ) . '%"><span>' . esc_html( $v >= 1000 ? number_format( $v / 1000, fmod( $v, 1000 ) ? 1 : 0, ',', '' ) . "\u{a0}tis." : self::num( $v ) ) . '</span></div>';
		}
		echo '<div class="ss-cols">';
		$table = '';
		$i     = 0;
		foreach ( $buckets as $k => $b ) {
			$start = $b['start'];
			if ( 'day' === $mode ) {
				$label = wp_date( 'j. n.', $start->getTimestamp() );
				$full  = wp_date( 'l j. n. Y', $start->getTimestamp() );
			} elseif ( 'week' === $mode ) {
				$label = wp_date( 'j. n.', $start->getTimestamp() );
				$full  = 'Týden od ' . wp_date( 'j. n. Y', $start->getTimestamp() );
			} else {
				$label = wp_date( 'n/Y', $start->getTimestamp() );
				$full  = wp_date( 'F Y', $start->getTimestamp() );
			}
			$czk = max( 0, $b['czk'] );
			$eur = max( 0, $b['eur'] ) * $rate;
			$sum = $b['czk'] + $b['eur'] * $rate;
			echo '<div class="ss-col" tabindex="0" data-t="' . esc_attr( $full ) . '" data-s="' . esc_attr( self::kc( $sum ) ) . '" data-c="' . esc_attr( self::kc( $b['czk'] ) ) . '" data-e="' . esc_attr( self::eur( $b['eur'] ) ) . '" data-o="' . esc_attr( self::num( $b['orders'] ) ) . '">'
				. '<div class="ss-stack" style="height:' . esc_attr( round( ( $czk + $eur ) / $top * 100, 3 ) ) . '%">'
				. ( $eur > 0 ? '<i class="e" style="flex:' . esc_attr( round( $eur, 2 ) ) . '"></i>' : '' )
				. ( $czk > 0 ? '<i class="c" style="flex:' . esc_attr( round( $czk, 2 ) ) . '"></i>' : '' )
				. '</div><span class="ss-x">' . ( 0 === $i % $every ? esc_html( $label ) : '' ) . '</span></div>';
			$table .= '<tr><td>' . esc_html( $full ) . '</td><td class="r">' . esc_html( self::num( $b['orders'] ) ) . '</td><td class="r">' . esc_html( self::kc( $b['czk'] ) ) . '</td><td class="r">' . esc_html( self::eur( $b['eur'] ) ) . '</td><td class="r">' . esc_html( self::kc( $sum ) ) . '</td></tr>';
			++$i;
		}
		echo '</div></div><div class="ss-tip" hidden></div></div>';
		echo '<details class="ss-details"><summary>Zobrazit jako tabulku</summary><table><thead><tr><th>Období</th><th class="r">Objednávek</th><th class="r">Kč</th><th class="r">EUR</th><th class="r">Celkem ≈ Kč</th></tr></thead><tbody>' . $table . '</tbody></table></details></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
<script>
(function(){
	var chart=document.querySelector('.sam-sales .ss-chart'); if(!chart){return;}
	var tip=chart.querySelector('.ss-tip');
	function row(k,v){var d=document.createElement('div');var a=document.createElement('span');a.textContent=k;var b=document.createElement('b');b.textContent=v;d.appendChild(a);d.appendChild(b);return d;}
	function show(col){
		tip.textContent='';
		var h=document.createElement('strong');h.textContent=col.dataset.t;tip.appendChild(h);
		tip.appendChild(row('Celkem',col.dataset.s));tip.appendChild(row('V Kč',col.dataset.c));tip.appendChild(row('V EUR',col.dataset.e));tip.appendChild(row('Objednávek',col.dataset.o));
		tip.hidden=false;
		var c=chart.getBoundingClientRect(),r=col.getBoundingClientRect(),x=r.left-c.left+r.width/2;
		var w=tip.offsetWidth;x=Math.max(w/2,Math.min(c.width-w/2,x));
		tip.style.left=x+'px';
	}
	chart.querySelectorAll('.ss-col').forEach(function(col){
		col.addEventListener('mouseenter',function(){show(col);});
		col.addEventListener('focus',function(){show(col);});
		col.addEventListener('mouseleave',function(){tip.hidden=true;});
		col.addEventListener('blur',function(){tip.hidden=true;});
	});
})();
</script>
		<?php
	}

	private static function styles() {
		?>
<style>
.sam-sales{--ss-c:#2a78d6;--ss-e:#eb6834;--ss-ink:#0b0b0b;--ss-ink2:#52514e;--ss-line:#e4e3de;--ss-surface:#fcfcfb;max-width:1280px;color:var(--ss-ink)}
.sam-sales .ss-note{color:var(--ss-ink2);margin:4px 0 16px}
.sam-sales .ss-card{background:var(--ss-surface);border:1px solid var(--ss-line);border-radius:8px;padding:16px 18px}
.sam-sales .ss-strip,.sam-sales .ss-kpis{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}
.sam-sales .ss-label{font-size:12px;color:var(--ss-ink2);text-transform:uppercase;letter-spacing:.04em}
.sam-sales .ss-big{font-size:26px;line-height:1.25;font-weight:600;margin-top:4px;font-variant-numeric:tabular-nums;white-space:nowrap}
.sam-sales .ss-strip .ss-big{font-size:22px}
.sam-sales .ss-sub{font-size:12px;color:var(--ss-ink2);margin-top:4px;min-height:17px}
.sam-sales .ss-delta{font-weight:600}
.sam-sales .ss-up{color:#087a08}.sam-sales .ss-down{color:#b42323}.sam-sales .ss-flat{font-weight:400}
.sam-sales .ss-filter{display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;margin:22px 0 4px}
.sam-sales .ss-tabs{display:flex;flex-wrap:wrap;gap:4px}
.sam-sales .ss-tabs a{padding:6px 12px;border-radius:999px;text-decoration:none;color:var(--ss-ink2);border:1px solid transparent}
.sam-sales .ss-tabs a:hover{border-color:var(--ss-line);background:var(--ss-surface)}
.sam-sales .ss-tabs a.on{background:var(--ss-ink);color:#fff}
.sam-sales .ss-custom{display:flex;flex-wrap:wrap;gap:8px;align-items:center;color:var(--ss-ink2)}
.sam-sales .ss-custom.on input{border-color:var(--ss-ink)}
.sam-sales .ss-h{font-size:18px;margin:14px 0 12px}
.sam-sales .ss-h span,.sam-sales h3 span{font-size:12px;font-weight:400;color:var(--ss-ink2);margin-left:6px}
.sam-sales h3{margin:0 0 10px;font-size:14px}
.sam-sales .ss-chartcard{margin-top:12px}
.sam-sales .ss-legend{display:flex;flex-wrap:wrap;gap:16px;font-size:12px;color:var(--ss-ink2);margin-bottom:10px}
.sam-sales .ss-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:-1px}
.sam-sales i.c{background:var(--ss-c)}.sam-sales i.e{background:var(--ss-e)}
.sam-sales .ss-chart{position:relative;padding:8px 0 22px 52px}
.sam-sales .ss-plot{position:relative;height:240px}
.sam-sales .ss-grid{position:absolute;left:0;right:0;border-top:1px solid var(--ss-line);height:0}
.sam-sales .ss-grid span{position:absolute;right:100%;margin-right:8px;top:-8px;font-size:11px;color:var(--ss-ink2);white-space:nowrap;font-variant-numeric:tabular-nums}
.sam-sales .ss-cols{position:absolute;inset:0;display:flex;align-items:stretch}
.sam-sales .ss-col{flex:1 1 0;min-width:0;position:relative;display:flex;justify-content:center;align-items:flex-end;outline:0}
.sam-sales .ss-col:hover,.sam-sales .ss-col:focus{background:rgba(11,11,11,.05)}
.sam-sales .ss-stack{width:min(64%,28px);min-width:3px;display:flex;flex-direction:column;gap:2px}
.sam-sales .ss-stack i{display:block;min-height:1px}
.sam-sales .ss-stack i:first-child{border-radius:4px 4px 0 0}
.sam-sales .ss-x{position:absolute;top:100%;margin-top:6px;font-size:11px;color:var(--ss-ink2);white-space:nowrap}
.sam-sales .ss-tip{position:absolute;top:0;transform:translateX(-50%);background:var(--ss-ink);color:#fff;border-radius:6px;padding:8px 10px;font-size:12px;pointer-events:none;z-index:5;min-width:170px}
.sam-sales .ss-tip strong{display:block;margin-bottom:4px}
.sam-sales .ss-tip div{display:flex;justify-content:space-between;gap:16px}
.sam-sales .ss-tip span{color:#c9c8c2}
.sam-sales .ss-details{margin-top:10px;color:var(--ss-ink2)}
.sam-sales .ss-details summary{cursor:pointer}
.sam-sales table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
.sam-sales th{text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--ss-ink2);padding:6px 8px;border-bottom:1px solid var(--ss-line)}
.sam-sales td{padding:7px 8px;border-bottom:1px solid var(--ss-line);position:relative;color:var(--ss-ink)}
.sam-sales tr:last-child td{border-bottom:0}
.sam-sales .r{text-align:right;white-space:nowrap}
.sam-sales .ss-share{position:absolute;left:8px;bottom:2px;height:2px;border-radius:2px;background:var(--ss-c);max-width:calc(100% - 16px)}
.sam-sales .ss-tables{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));margin-top:12px;align-items:start}
.sam-sales .ss-empty{color:var(--ss-ink2);margin:0}
</style>
		<?php
	}
}

SAM_Sales::init();
