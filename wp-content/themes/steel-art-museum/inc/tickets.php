<?php
/**
 * Kalkulačka vstupenek na domovské stránce.
 *
 * Ceny a sleva se počítají v košíku WooCommerce (akce je zatím snippet WPCode „Akce LISTOPAD“,
 * ve fázi 4 vlastní modul). Kalkulačka jen ukazuje stejný výpočet předem:
 *  - běží-li akce (snippet aktivní a nevypršel): běžné ceny a každá druhá vstupenka zdarma (nejlevnější),
 *  - jinak: online cena (akční cena produktu, tj. sleva 20 %) proti ceně na pokladně.
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
	if ( ! function_exists( 'stm_akce_free_allocation' ) || ! function_exists( 'stm_akce_end_iso' ) ) {
		return false;
	}
	return time() < strtotime( stm_akce_end_iso() );
}

function sam_promo_end_iso() {
	return function_exists( 'stm_akce_end_iso' ) ? stm_akce_end_iso() : '';
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

function sam_ticket_offer( $lang ) {
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

	$en = 'en' === $lang;
	return array(
		'lang'     => $lang,
		'rows'     => $rows,
		'promo'    => $promo,
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
			'bar'     => 'Black Friday: every second ticket free',
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
			'bar'     => 'Black Friday: každá druhá vstupenka zdarma',
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

	WC()->cart->empty_cart();
	$added = 0;
	foreach ( $items as $product_id => $qty ) {
		$product_id = (int) $product_id;
		$qty        = min( 50, max( 0, (int) $qty ) );
		if ( $qty > 0 && in_array( $product_id, $allowed, true ) && WC()->cart->add_to_cart( $product_id, $qty ) ) {
			$added += $qty;
		}
	}
	if ( 0 === $added ) {
		wp_send_json_error( array( 'message' => 'empty' ), 400 );
	}

	WC()->cart->calculate_totals();
	if ( function_exists( 'stm_akce_sync_coupon' ) ) {
		stm_akce_sync_coupon();
	}
	wc_clear_notices();
	if ( WC()->session && ! WC()->session->has_session() ) {
		WC()->session->set_customer_session_cookie( true );
	}

	$checkout = get_permalink( (int) apply_filters( 'wpml_object_id', wc_get_page_id( 'checkout' ), 'page', true, $lang ) );
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
