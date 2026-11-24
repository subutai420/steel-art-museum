<?php
/**
 * Kalkulačka vstupenek na domovské stránce.
 *
 * Ceny a sleva se počítají v košíku WooCommerce (modul Akce, mu-plugins/sam-core/promo.php).
 * Kalkulačka jen ukazuje stejný výpočet předem:
 *  - běží-li akce: pravidlo akce (každá N-tá zdarma / sleva %), případně z běžných cen,
 *  - jinak: online cena (akční cena produktu, tj. sleva 20 %) proti ceně na pokladně.
 * Shortcody [smart_ticket_calculator_cz_magenta] a [smart_ticket_calculator_en_magenta] (stránka
 * Magenta Moments) vykreslí stejnou kalkulačku s řádkem Magenta Moments 1+1.
 */

defined( 'ABSPATH' ) || exit;

/** Jednotlivé vstupenky v pořadí kalkulačky (ID českých produktů). */
function sam_ticket_rows() {
	return array(
		'adult'   => array( 'id' => 1115, 'cs' => array( 'Dospělí', 'Standardní vstupné' ), 'en' => array( 'Adults', 'Standard admission' ) ),
		'child'   => array( 'id' => 1125, 'cs' => array( 'Děti', '3–15 let' ), 'en' => array( 'Children', '3–15 years' ) ),
		'student' => array( 'id' => 1116, 'cs' => array( 'Studenti', 'S platným průkazem' ), 'en' => array( 'Students', 'With valid ID' ) ),
		'senior'  => array( 'id' => 1121, 'cs' => array( 'Senioři', 'Nad 65 let' ), 'en' => array( 'Seniors', 'Over 65' ) ),
		'ztp'     => array( 'id' => 1119, 'cs' => array( 'ZTP', 'Osoby s hendikepem' ), 'en' => array( 'Disabled', 'ZTP / ZTP-P card' ) ),
	);
}

function sam_promo_active() {
	return class_exists( 'SAM_Promo' ) && SAM_Promo::active();
}

function sam_promo_end_iso() {
	return class_exists( 'SAM_Promo' ) ? SAM_Promo::end_iso() : '';
}

/** Text akce na webu (štítek u kalkulačky). */
function sam_promo_banner( $lang ) {
	return class_exists( 'SAM_Promo' ) ? SAM_Promo::text( 'banner', $lang ) : '';
}

function sam_promo_note( $lang ) {
	return class_exists( 'SAM_Promo' ) ? SAM_Promo::note( $lang ) : '';
}

function sam_tickets_valid_until_text( $lang ) {
	$date = class_exists( 'SAM_Tickets_Settings' ) ? SAM_Tickets_Settings::get( 'valid_until' ) : '2027-06-30';
	$ts   = strtotime( (string) $date ) ?: strtotime( '2027-06-30' );
	return 'en' === $lang ? gmdate( 'j F Y', $ts ) : gmdate( 'j. n. Y', $ts );
}

/** ID produktu v daném jazyce (WPML). */
function sam_ticket_product_id( $cs_id, $lang ) {
	if ( 'cs' === $lang ) {
		return (int) $cs_id;
	}
	return (int) apply_filters( 'wpml_object_id', $cs_id, 'product', true, $lang );
}

/**
 * Ceny jedné vstupenky: [online cena, cena na pokladně]. EN je v eurech
 * (ruční ceny WCML jsou uložené u českého originálu v *_EUR metadatech).
 */
function sam_ticket_prices( $cs_id, $lang, $promo ) {
	if ( 'en' === $lang ) {
		$regular = (float) get_post_meta( $cs_id, '_regular_price_EUR', true );
		$price   = (float) get_post_meta( $cs_id, '_price_EUR', true );
		$price   = $promo || $price <= 0 ? $regular : $price;
		return array( $price, $regular );
	}
	$product = wc_get_product( $cs_id );
	if ( ! $product ) {
		return array( 0.0, 0.0 );
	}
	$regular = (float) $product->get_regular_price();
	$price   = $promo ? $regular : (float) $product->get_price();
	return array( $price ?: $regular, $regular );
}

function sam_ticket_offer( $lang, $magenta = false ) {
	$promo = sam_promo_active();
	$rows  = array();
	if ( function_exists( 'wc_get_product' ) ) {
		foreach ( sam_ticket_rows() as $key => $row ) {
			list( $price, $regular ) = sam_ticket_prices( $row['id'], $lang, $promo );
			if ( $price <= 0 ) {
				continue;
			}
			$rows[] = array(
				'k'  => $key,
				'id' => sam_ticket_product_id( $row['id'], $lang ),
				'n'  => $row[ $lang ][0],
				'd'  => $row[ $lang ][1],
				'p'  => $price,
				'r'  => $regular,
			);
		}
	}
	if ( $magenta ) {
		$m = sam_magenta_row( $lang );
		if ( $m ) {
			array_unshift( $rows, $m );
		}
	}

	$en = 'en' === $lang;
	return array(
		'lang'     => $lang,
		'rows'     => $rows,
		'promo'    => $promo,
		'rule'     => class_exists( 'SAM_Promo' ) ? SAM_Promo::js() : array( 'type' => 'nth', 'nth' => 2, 'percent' => 0 ),
		'start'    => $magenta ? array( 'magenta' => 1 ) : array( 'adult' => 2 ),
		'end'      => $promo ? sam_promo_end_iso() : '',
		'currency' => $en ? 'EUR' : 'CZK',
		'locale'   => $en ? 'en-GB' : 'cs-CZ',
		'ajax'     => admin_url( 'admin-ajax.php' . ( $en ? '?lang=en' : '' ) ),
		'max'      => 10,
		't'        => $en ? array(
			'empty'   => 'Add who is coming with you.',
			'one'     => 'Add one more person and one ticket will be free.',
			'more'    => 'Add one more person and get another ticket free.',
			'save'    => '%n tickets, you save %s.',
			'n1'      => '%n tickets',
			'groups'  => ' For groups over 10 we have a tailored offer.',
			'online'  => '%n tickets, %s cheaper than at the door.',
			'online1' => '%n ticket, %s cheaper than at the door.',
			'minus'   => 'Remove: ',
			'plus'    => 'Add: ',
			'count'   => 'Count: ',
			'bar'     => sam_promo_banner( 'en' ),
			'bar0'    => 'Tickets online',
			'busy'    => 'Just a moment…',
			'door'    => 'at the door',
			'err'     => 'Something went wrong. Please try again.',
			'tickets' => array( 'ticket', 'tickets', 'tickets' ),
		) : array(
			'empty'   => 'Přidejte, kdo s vámi půjde.',
			'one'     => 'Přidejte ještě jednu osobu a jedna vstupenka bude zdarma.',
			'more'    => 'Přidejte ještě jednu osobu a další vstupenka bude zdarma.',
			'save'    => '%n vstupenky, ušetříte %s.',
			'n1'      => '%n vstupenky',
			'groups'  => ' Pro skupiny nad 10 osob máme i nabídku na míru.',
			'online'  => '%n vstupenky, o %s levněji než na pokladně.',
			'online1' => '%n vstupenka, o %s levněji než na pokladně.',
			'minus'   => 'Ubrat: ',
			'plus'    => 'Přidat: ',
			'count'   => 'Počet: ',
			'bar'     => sam_promo_banner( 'cs' ),
			'bar0'    => 'Vstupenky online',
			'busy'    => 'Moment…',
			'door'    => 'na pokladně',
			'err'     => 'Něco se nepovedlo. Zkuste to prosím znovu.',
			'tickets' => array( 'vstupenka', 'vstupenky', 'vstupenek' ),
		),
	);
}

/**
 * Vloží vstupenky z kalkulačky do košíku a vrátí adresu pokladny.
 * Bez nonce záměrně: stránka je ve statické cache a akce mění jen košík návštěvníka.
 */
function sam_ajax_add_tickets() {
	$lang  = isset( $_REQUEST['lang'] ) && 'en' === $_REQUEST['lang'] ? 'en' : 'cs';
	$items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'cart' ), 500 );
	}
	if ( 'en' === $lang ) {
		do_action( 'wpml_switch_language', 'en' );
		if ( WC()->session ) {
			WC()->session->set( 'client_currency', 'EUR' );
		}
	}

	$allowed = array();
	foreach ( sam_ticket_rows() as $row ) {
		$allowed[] = sam_ticket_product_id( $row['id'], $lang );
	}
	$magenta   = sam_ticket_product_id( sam_magenta_id(), $lang );
	$allowed[] = $magenta;

	WC()->cart->empty_cart();
	$added = 0;
	foreach ( $items as $product_id => $qty ) {
		$product_id = (int) $product_id;
		$qty        = min( $product_id === $magenta ? 1 : 50, max( 0, (int) $qty ) );
		if ( $qty > 0 && in_array( $product_id, $allowed, true ) && WC()->cart->add_to_cart( $product_id, $qty ) ) {
			$added += $qty;
		}
	}
	if ( 0 === $added ) {
		wp_send_json_error( array( 'message' => 'empty' ), 400 );
	}

	WC()->cart->calculate_totals();
	if ( class_exists( 'SAM_Promo' ) ) {
		SAM_Promo::sync_coupon();
	}
	if ( function_exists( 'stm_akce_sync_coupon' ) ) {
		stm_akce_sync_coupon();
	}
	wc_clear_notices();
	if ( WC()->session && ! WC()->session->has_session() ) {
		WC()->session->set_customer_session_cookie( true );
	}
	do_action( 'sam_tickets_added_to_cart' ); // měření (mu-plugins/sam-core/meta.php)

	$checkout =get_permalink( (int) apply_filters( 'wpml_object_id', wc_get_page_id( 'checkout' ), 'page', true, $lang ) );
	wp_send_json_success( array( 'redirect' => $checkout ?: wc_get_checkout_url() ) );
}
add_action( 'wp_ajax_sam_add_tickets', 'sam_ajax_add_tickets' );
add_action( 'wp_ajax_nopriv_sam_add_tickets', 'sam_ajax_add_tickets' );

/** Ceny na pokladně pro tabulku na domovské stránce (běžné ceny, ve správné měně). */
function sam_box_office_prices( $lang ) {
	$ids = array(
		'adult'   => 1115,
		'reduced' => 1116,
		'child'   => 1125,
		'fam21'   => 1124,
		'fam22'   => 1123,
		'fam23'   => 1122,
		'group'   => 1120,
	);
	$out = array();
	foreach ( $ids as $key => $id ) {
		if ( 'en' === $lang ) {
			$value = (float) get_post_meta( $id, '_regular_price_EUR', true );
		} else {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			$value   = $product ? (float) $product->get_regular_price() : 0;
		}
		$out[ $key ] = $value > 0 ? sam_money( $value, $lang ) : '';
	}
	return $out;
}

function sam_money( $value, $lang ) {
	if ( 'en' === $lang ) {
		return '€' . ( floor( $value ) == $value ? number_format( $value, 0 ) : number_format( $value, 2 ) );
	}
	return number_format( $value, 0, ',', "\u{00A0}" ) . "\u{00A0}Kč";
}

/* ---------------- Magenta Moments ---------------- */

function sam_magenta_id() {
	return class_exists( 'SAM_Promo' ) ? SAM_Promo::MAGENTA_PRODUCT : 21024;
}

/** Řádek kalkulačky „Magenta Moments 1+1“ (max. 1 ks, s kódem MAGENTA sleva 50 %). */
function sam_magenta_row( $lang ) {
	$cs_id = sam_magenta_id();
	if ( 'en' === $lang ) {
		$price = (float) get_post_meta( $cs_id, '_price_EUR', true );
		if ( $price <= 0 ) {
			$price = (float) get_post_meta( $cs_id, '_regular_price_EUR', true );
		}
	} else {
		$product = wc_get_product( $cs_id );
		$price   = $product && $product->is_purchasable() ? (float) $product->get_price() : 0;
	}
	if ( $price <= 0 ) {
		return null;
	}
	$en = 'en' === $lang;
	return array(
		'k' => 'magenta',
		'id' => sam_ticket_product_id( $cs_id, $lang ),
		'n' => 'Magenta Moments 1+1',
		'd' => $en ? '2 adult tickets, 50 % off with your MAGENTA code at checkout' : '2 vstupenky pro dospělé, s kódem MAGENTA v pokladně sleva 50 %',
		'p' => $price,
		'r' => $price,
		'm' => 1,
	);
}

/** Kalkulačka vložená do obsahu stránky (Magenta Moments, náhrada snippetů WPCode). */
function sam_embed_calculator( $lang, $magenta = false ) {
	$offer              = sam_ticket_offer( $lang, $magenta );
	$offer['boxOffice'] = true;
	list( $url, $ver )  = sam_asset( 'js/home.js' );
	wp_enqueue_script( 'sam-home', $url, array(), $ver, array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_add_inline_script( 'sam-home', 'window.SAM_TICKETS=' . wp_json_encode( $offer ) . ';', 'before' );
	$en = 'en' === $lang;
	ob_start();
	?>
	<div class="b-tk b-tk--embed" id="vstupenky">
		<div class="b-tk-calc">
			<ul class="rows" id="rows"></ul>
			<div class="sum">
				<p class="msg" id="msg" aria-live="polite"></p>
				<p class="total"><small><?php echo esc_html( $en ? 'Total' : 'Celkem' ); ?></small><span id="was" class="was"></span><span id="total">–</span></p>
				<button class="b-btn b-btn--buy" id="buy" type="button" disabled><?php echo esc_html( $en ? 'Continue to checkout' : 'Pokračovat k platbě' ); ?></button>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_action( 'init', function () {
	$tags = array(
		'smart_ticket_calculator_cz_magenta' => array( 'cs', true ),
		'smart_ticket_calculator_en_magenta' => array( 'en', true ),
		'smart_ticket_calculator'            => array( 'cs', false ),
		'smart_ticket_calculator_en'         => array( 'en', false ),
	);
	foreach ( $tags as $tag => $cfg ) {
		if ( ! shortcode_exists( $tag ) ) {
			add_shortcode( $tag, function () use ( $cfg ) {
				return sam_embed_calculator( $cfg[0], $cfg[1] );
			} );
		}
	}
}, 20 );
