<?php
/**
 * Akce na vstupenky bez snippetů: WooCommerce → Akce.
 *
 * - Jedna akce najednou, zapíná se a vypíná v administraci, platí od–do (čas webu, Europe/Prague).
 * - Typy: „každá N-tá vstupenka zdarma“ (zdarma jsou vždy ty nejlevnější) a „sleva X %“.
 * - Volitelně se po dobu akce ruší online sleva (účtuje se běžná cena místo akční).
 * - Sleva se počítá v košíku WooCommerce virtuálním kupónem (sam-akce), takže sedí v objednávce,
 *   na faktuře i v refundacích. Kupón se v administraci nezakládá a zákazník ho nevidí jako kód.
 * - Jednorázové kódy (Magenta) jsou běžné kupóny WooCommerce; generují se tamtéž (sekce Kódy).
 *
 * Náhrada snippetu WPCode „[AKTIVNI] - Akce LISTOPAD“ (#32397). Dokud je snippet aktivní,
 * modul se sám vypne, aby se sleva nepočítala dvakrát.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Promo {
	const OPTION = 'sam_promo';
	const COUPON = 'sam-akce';

	/** Jednotlivé vstupenky, na které akce platí (CZ a EN produkty). Rodinné balíčky ne. */
	const TICKET_IDS = array( 1115, 1125, 1116, 1121, 1119, 1835, 1829, 1834, 1832, 1833 );

	/** Produkt Magenta Moments 1+1 (kódy MAGENTA-xxxxxx: 50 %, jednorázové). */
	const MAGENTA_PRODUCT = 21024;

	private static $enabled = true;
	private static $alloc;

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_seed' ) );
		add_action( 'init', array( __CLASS__, 'detect_snippet' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 60 );
		add_action( 'admin_post_sam_promo_save', array( __CLASS__, 'admin_save' ) );
		add_action( 'admin_post_sam_promo_codes', array( __CLASS__, 'admin_codes' ) );

		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'regular_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'no_sale_price' ), 99, 2 );
		add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'not_on_sale' ), 99, 2 );
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( __CLASS__, 'discount_amount' ), 10, 5 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'coupon_valid' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'sync_coupon' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'reset' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'reset' ) );
		add_filter( 'woocommerce_coupon_message', array( __CLASS__, 'coupon_message' ), 10, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( __CLASS__, 'coupon_html' ), 10, 3 );
	}

	/* ---------------- nastavení ---------------- */

	public static function defaults() {
		return array(
			'enabled'   => 0,
			'type'      => 'nth',
			'nth'       => 2,
			'percent'   => 10,
			'from'      => '',
			'to'        => '',
			'regular'   => 1,
			'label_cs'  => 'Akce: každá druhá vstupenka zdarma',
			'label_en'  => 'Special offer: every second ticket free',
			'banner_cs' => '',
			'banner_en' => '',
		);
	}

	public static function settings() {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	/** Při prvním spuštění převezme probíhající akci Black Friday ze snippetu (do 30. 11. 2026). */
	public static function maybe_seed() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}
		add_option(
			self::OPTION,
			array_merge(
				self::defaults(),
				array(
					'enabled'   => 1,
					'from'      => '2026-11-01 00:00',
					'to'        => '2026-11-30 23:59',
					'banner_cs' => 'Black Friday do 30. 11.: každá druhá vstupenka zdarma',
					'banner_en' => 'Black Friday until 30 Nov: every second ticket free',
				)
			),
			'',
			true
		);
	}

	/** Snippet WPCode se stejnou akcí ještě běží → modul nic nepočítá (jinak dvojí sleva). */
	public static function detect_snippet() {
		if ( function_exists( 'stm_akce_free_allocation' ) ) {
			self::$enabled = false;
		}
	}

	private static function time( $value ) {
		if ( '' === (string) $value ) {
			return null;
		}
		try {
			return new DateTimeImmutable( (string) $value, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** Akce je zapnutá a právě platí. */
	public static function active() {
		if ( ! self::$enabled ) {
			return false;
		}
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		$now  = time();
		$from = self::time( $s['from'] );
		$to   = self::time( $s['to'] );
		if ( $from && $now < $from->getTimestamp() ) {
			return false;
		}
		if ( $to && $now > $to->getTimestamp() + 59 ) {
			return false;
		}
		return 'percent' === $s['type'] ? (float) $s['percent'] > 0 : (int) $s['nth'] >= 2;
	}

	/** Konec akce (ISO 8601) pro odpočet, nebo ''. */
	public static function end_iso() {
		$to = self::time( self::settings()['to'] );
		return $to ? $to->setTime( (int) $to->format( 'H' ), (int) $to->format( 'i' ), 59 )->format( 'c' ) : '';
	}

	/** Údaje pro kalkulačku na webu. */
	public static function js() {
		$s = self::settings();
		return array(
			'type'    => 'percent' === $s['type'] ? 'percent' : 'nth',
			'nth'     => max( 2, (int) $s['nth'] ),
			'percent' => (float) $s['percent'],
		);
	}

	public static function text( $key, $lang ) {
		$s = self::settings();
		$v = trim( (string) ( $s[ $key . '_' . ( 'en' === $lang ? 'en' : 'cs' ) ] ?? '' ) );
		if ( '' === $v && 'banner' === $key ) {
			$v = self::text( 'label', $lang );
		}
		return $v;
	}

	/** Vysvětlení pravidla pod ceníkem. */
	public static function note( $lang ) {
		$p  = self::js();
		$en = 'en' === $lang;
		if ( 'percent' === $p['type'] ) {
			$pct = rtrim( rtrim( number_format( $p['percent'], 2, '.', '' ), '0' ), '.' );
			return $en ? sprintf( '%s %% off all tickets in your order.', $pct ) : sprintf( 'Sleva %s %% platí na všechny vstupenky v objednávce.', $pct );
		}
		if ( 2 === $p['nth'] ) {
			return $en ? 'Every second ticket in your order is free, always the cheapest ones.' : 'Zdarma je každá druhá vstupenka v objednávce, vždy ty nejlevnější.';
		}
		return $en ? sprintf( 'Every %d. ticket in your order is free, always the cheapest ones.', $p['nth'] ) : sprintf( 'Zdarma je každá %d. vstupenka v objednávce, vždy ty nejlevnější.', $p['nth'] );
	}

	public static function product_ids() {
		return array_map( 'intval', (array) apply_filters( 'sam_promo_product_ids', self::TICKET_IDS ) );
	}

	private static function is_ticket( $product ) {
		return $product && in_array( (int) $product->get_id(), self::product_ids(), true );
	}

	/* ---------------- ceny ---------------- */

	public static function regular_price( $price, $product ) {
		if ( self::is_ticket( $product ) && self::active() && ! empty( self::settings()['regular'] ) ) {
			$regular = $product->get_regular_price();
			if ( '' !== $regular ) {
				return $regular;
			}
		}
		return $price;
	}

	public static function no_sale_price( $sale, $product ) {
		return self::is_ticket( $product ) && self::active() && ! empty( self::settings()['regular'] ) ? '' : $sale;
	}

	public static function not_on_sale( $on_sale, $product ) {
		return self::is_ticket( $product ) && self::active() && ! empty( self::settings()['regular'] ) ? false : $on_sale;
	}

	/* ---------------- sleva v košíku ---------------- */

	public static function reset() {
		self::$alloc = null;
	}

	/**
	 * Rozpis košíku: počet vstupenek a kolik kusů je na kterém řádku zdarma.
	 *
	 * @return array{count:int, free:int, items:array<string,int>}
	 */
	public static function allocation() {
		$alloc = array( 'count' => 0, 'free' => 0, 'items' => array() );
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $alloc;
		}
		$ids   = self::product_ids();
		$units = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! in_array( (int) $item['product_id'], $ids, true ) ) {
				continue;
			}
			$qty             = (int) $item['quantity'];
			$price           = (float) $item['data']->get_price();
			$alloc['count'] += $qty;
			for ( $i = 0; $price > 0 && $i < $qty; $i++ ) {
				$units[] = array( 'key' => $key, 'price' => $price );
			}
		}
		if ( 'nth' === self::js()['type'] ) {
			usort( $units, static function ( $a, $b ) { return $a['price'] <=> $b['price']; } );
			$alloc['free'] = min( intdiv( $alloc['count'], self::js()['nth'] ), count( $units ) );
			for ( $i = 0; $i < $alloc['free']; $i++ ) {
				$k                    = $units[ $i ]['key'];
				$alloc['items'][ $k ] = ( $alloc['items'][ $k ] ?? 0 ) + 1;
			}
		}
		return $alloc;
	}

	public static function coupon_data( $data, $code ) {
		if ( self::COUPON !== wc_strtolower( (string) $code ) || ! self::active() ) {
			return $data;
		}
		return array(
			'discount_type'  => 'percent',
			'amount'         => 'percent' === self::js()['type'] ? self::js()['percent'] : 100,
			'product_ids'    => self::product_ids(),
			'individual_use' => false,
			'usage_limit'    => 0,
			'virtual'        => true,
		);
	}

	public static function discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! $coupon instanceof WC_Coupon || self::COUPON !== $coupon->get_code() ) {
			return $discount;
		}
		if ( 'percent' === self::js()['type'] ) {
			return $discount; // běžná procentní sleva WooCommerce
		}
		$alloc = self::$alloc ?? ( self::$alloc = self::allocation() );
		$free  = $alloc['items'][ $cart_item['key'] ?? '' ] ?? 0;
		$qty   = max( 1, (int) $cart_item['quantity'] );
		return $free ? $discounting_amount * $free / $qty : 0;
	}

	public static function coupon_valid( $valid, $coupon ) {
		if ( self::COUPON !== $coupon->get_code() ) {
			return $valid;
		}
		if ( ! self::active() ) {
			return false;
		}
		$alloc = self::allocation();
		return 'percent' === self::js()['type'] ? $alloc['count'] > 0 : $alloc['free'] > 0;
	}

	/** Kupón akce se přidá a odebere sám podle obsahu košíku. */
	public static function sync_coupon() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cart  = WC()->cart;
		$has   = $cart->has_discount( self::COUPON );
		$alloc = self::active() ? self::allocation() : array( 'count' => 0, 'free' => 0 );
		$want  = 'percent' === self::js()['type'] ? $alloc['count'] > 0 : $alloc['free'] > 0;
		foreach ( $cart->get_applied_coupons() as $code ) {
			$c = new WC_Coupon( $code );
			if ( $c->get_individual_use() ) {
				$want = false; // jednorázový kód (Magenta) se s akcí nekombinuje
			}
		}
		if ( $want && ! $has ) {
			$cart->apply_coupon( self::COUPON );
		} elseif ( ! $want && $has ) {
			$cart->remove_coupon( self::COUPON );
			$cart->calculate_totals();
		}
	}

	public static function coupon_message( $msg, $msg_code, $coupon ) {
		return $coupon instanceof WC_Coupon && self::COUPON === $coupon->get_code() ? '' : $msg;
	}

	public static function coupon_label( $label, $coupon ) {
		if ( self::COUPON !== $coupon->get_code() ) {
			return $label;
		}
		return self::text( 'label', apply_filters( 'wpml_current_language', 'cs' ) ) ?: $label;
	}

	public static function coupon_html( $html, $coupon, $amount_html ) {
		return self::COUPON === $coupon->get_code() ? $amount_html : $html;
	}

	/* ---------------- administrace ---------------- */

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', 'Akce', 'Akce', 'manage_woocommerce', 'sam-promo', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_page() {
		$s     = self::settings();
		$state = self::active() ? '<b style="color:#008a20">Akce teď platí.</b>' : ( empty( $s['enabled'] ) ? 'Akce je vypnutá.' : 'Akce je zapnutá, ale teď neplatí (mimo termín).' );
		if ( ! self::$enabled ) {
			$state = '<b style="color:#b32d2e">Běží snippet WPCode „Akce LISTOPAD“, tento modul je proto vypnutý. Snippet vypněte.</b>';
		}
		$codes = isset( $_GET['codes'] ) ? (int) $_GET['codes'] : -1; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1>Akce na vstupenky</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?><div class="notice notice-success"><p>Uloženo.</p></div><?php endif; ?>
			<?php if ( $codes >= 0 ) : ?><div class="notice notice-success"><p>Vytvořeno kódů: <?php echo (int) $codes; ?>. Najdete je v Marketing → Kupóny.</p></div><?php endif; ?>
			<p><?php echo wp_kses_post( $state ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sam_promo_save">
				<?php wp_nonce_field( 'sam_promo_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>Zapnuto</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> Akce běží v zadaném termínu</label></td></tr>
					<tr><th>Termín</th><td>
						od <input type="datetime-local" name="from" value="<?php echo esc_attr( str_replace( ' ', 'T', $s['from'] ) ); ?>">
						do <input type="datetime-local" name="to" value="<?php echo esc_attr( str_replace( ' ', 'T', $s['to'] ) ); ?>">
						<p class="description">Čas v Praze. Prázdné „od“ = hned, prázdné „do“ = bez konce. Konec se ukazuje i v odpočtu na webu.</p>
					</td></tr>
					<tr><th>Pravidlo</th><td>
						<label><input type="radio" name="type" value="nth" <?php checked( 'percent' !== $s['type'] ); ?>> Každá <input type="number" name="nth" min="2" max="10" value="<?php echo (int) $s['nth']; ?>" style="width:4em">. vstupenka zdarma (vždy ty nejlevnější)</label><br>
						<label><input type="radio" name="type" value="percent" <?php checked( 'percent' === $s['type'] ); ?>> Sleva <input type="number" name="percent" min="1" max="100" step="0.5" value="<?php echo esc_attr( $s['percent'] ); ?>" style="width:5em"> % na všechny vstupenky</label>
						<p class="description">Platí na jednotlivé vstupenky (dospělí, děti, studenti, senioři, ZTP), ne na rodinné balíčky a Magentu.</p>
					</td></tr>
					<tr><th>Ceny</th><td><label><input type="checkbox" name="regular" value="1" <?php checked( ! empty( $s['regular'] ) ); ?>> Po dobu akce zrušit online slevu (počítat z ceny na pokladně)</label></td></tr>
					<tr><th>Název slevy v košíku</th><td>
						<input type="text" class="regular-text" name="label_cs" value="<?php echo esc_attr( $s['label_cs'] ); ?>"> CZ<br>
						<input type="text" class="regular-text" name="label_en" value="<?php echo esc_attr( $s['label_en'] ); ?>"> EN
					</td></tr>
					<tr><th>Text na webu</th><td>
						<input type="text" class="large-text" name="banner_cs" value="<?php echo esc_attr( $s['banner_cs'] ); ?>"> CZ<br>
						<input type="text" class="large-text" name="banner_en" value="<?php echo esc_attr( $s['banner_en'] ); ?>"> EN
						<p class="description">Štítek u kalkulačky na úvodní stránce. Prázdné = název slevy.</p>
					</td></tr>
				</table>
				<?php submit_button( 'Uložit akci' ); ?>
			</form>

			<hr>
			<h2>Jednorázové kódy (Magenta)</h2>
			<p>Vytvoří kupóny WooCommerce: každý jde použít jednou, sleva platí jen na produkt Magenta Moments 1+1 a nekombinuje se s akcí.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sam_promo_codes">
				<?php wp_nonce_field( 'sam_promo_codes' ); ?>
				<p>
					Počet <input type="number" name="count" min="1" max="2000" value="100" style="width:6em">
					předpona <input type="text" name="prefix" value="MAGENTA-" style="width:9em">
					sleva <input type="number" name="amount" min="1" max="100" value="50" style="width:5em"> %
				</p>
				<?php submit_button( 'Vytvořit kódy', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function admin_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nemáte oprávnění.' );
		}
		check_admin_referer( 'sam_promo_save' );
		$p    = wp_unslash( $_POST );
		$time = static function ( $v ) {
			$v = trim( str_replace( 'T', ' ', (string) $v ) );
			return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v ) ? $v : '';
		};
		update_option(
			self::OPTION,
			array(
				'enabled'   => empty( $p['enabled'] ) ? 0 : 1,
				'type'      => 'percent' === ( $p['type'] ?? '' ) ? 'percent' : 'nth',
				'nth'       => min( 10, max( 2, (int) ( $p['nth'] ?? 2 ) ) ),
				'percent'   => min( 100, max( 0, round( (float) ( $p['percent'] ?? 0 ), 2 ) ) ),
				'from'      => $time( $p['from'] ?? '' ),
				'to'        => $time( $p['to'] ?? '' ),
				'regular'   => empty( $p['regular'] ) ? 0 : 1,
				'label_cs'  => sanitize_text_field( $p['label_cs'] ?? '' ),
				'label_en'  => sanitize_text_field( $p['label_en'] ?? '' ),
				'banner_cs' => sanitize_text_field( $p['banner_cs'] ?? '' ),
				'banner_en' => sanitize_text_field( $p['banner_en'] ?? '' ),
			)
		);
		do_action( 'sam_promo_saved' );
		wp_safe_redirect( admin_url( 'admin.php?page=sam-promo&saved=1' ) );
		exit;
	}

	/** Hromadné vytvoření jednorázových kódů (náhrada snippetu „Generovani kuponu“). */
	public static function admin_codes() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nemáte oprávnění.' );
		}
		check_admin_referer( 'sam_promo_codes' );
		$count  = min( 2000, max( 1, (int) ( $_POST['count'] ?? 0 ) ) );
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) wp_unslash( $_POST['prefix'] ?? 'MAGENTA-' ) ) );
		$amount = min( 100, max( 1, (int) ( $_POST['amount'] ?? 50 ) ) );
		$alpha  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // bez 0/O a 1/I
		$made   = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$code = '';
			for ( $j = 0; $j < 6; $j++ ) {
				$code .= $alpha[ random_int( 0, strlen( $alpha ) - 1 ) ];
			}
			$code = $prefix . $code;
			if ( wc_get_coupon_id_by_code( $code ) ) {
				continue;
			}
			$c = new WC_Coupon();
			$c->set_code( $code );
			$c->set_discount_type( 'percent' );
			$c->set_amount( $amount );
			$c->set_individual_use( true );
			$c->set_product_ids( array( self::MAGENTA_PRODUCT ) );
			$c->set_usage_limit( 1 );
			$c->save();
			++$made;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sam-promo&codes=' . $made ) );
		exit;
	}
}

SAM_Promo::init();
