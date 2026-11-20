<?php
// Test modulu Akce (mu-plugins/sam-core/promo.php) a Magenta kódů v košíku WooCommerce.
// wp eval-file /import/promo.php --user=<admin>
// Nastavení akce se po testu vrátí, vytvořený testovací kupón se smaže.

function ok( $label, $cond, $detail = '' ) { echo ( $cond ? 'OK   ' : 'FAIL ' ) . $label . ( '' !== $detail ? " – $detail" : '' ) . "\n"; }

$saved = get_option( SAM_Promo::OPTION );
wc_load_cart();
WC()->session->set_customer_session_cookie( true );

function cart( array $lines, array $promo = null, $coupon = '' ) {
	if ( null !== $promo ) {
		update_option( SAM_Promo::OPTION, array_merge( SAM_Promo::defaults(), $promo ) );
	}
	WC()->cart->empty_cart();
	WC()->cart->remove_coupons();
	foreach ( $lines as $pid => $qty ) {
		WC()->cart->add_to_cart( $pid, $qty );
	}
	if ( $coupon ) {
		WC()->cart->apply_coupon( $coupon );
	}
	SAM_Promo::sync_coupon();
	WC()->cart->calculate_totals();
	SAM_Promo::sync_coupon();
	WC()->cart->calculate_totals();
	return (float) WC()->cart->get_total( 'edit' );
}
$price = function ( $id, $regular ) { $p = wc_get_product( $id ); return (float) ( $regular ? $p->get_regular_price() : $p->get_price() ); };

$bf = array( 'enabled' => 1, 'type' => 'nth', 'nth' => 2, 'from' => '2020-01-01 00:00', 'to' => '2099-01-01 00:00', 'regular' => 1 );

echo "== Každá druhá zdarma (běžné ceny)\n";
$t = cart( array( 1115 => 2, 1125 => 1, 1121 => 1 ), $bf );
$exp = 2 * $price( 1115, true ) + $price( 1125, true ) + $price( 1121, true ) - $price( 1125, true ) - $price( 1121, true );
ok( '2× dospělý + dítě + senior: zdarma dítě a senior', abs( $t - $exp ) < 0.01, "$t / $exp" );
ok( 'kupón sam-akce v košíku', WC()->cart->has_discount( 'sam-akce' ) );
$t = cart( array( 1115 => 1 ), $bf );
ok( '1 vstupenka: bez slevy, běžná cena', abs( $t - $price( 1115, true ) ) < 0.01 && ! WC()->cart->has_discount( 'sam-akce' ), (string) $t );
$t = cart( array( 1115 => 3 ), $bf );
ok( '3 dospělí: 1 zdarma', abs( $t - 2 * $price( 1115, true ) ) < 0.01, (string) $t );
$t = cart( array( 1124 => 1, 1115 => 1 ), $bf );
ok( 'rodinná se nepočítá (1 vstupenka + rodina)', ! WC()->cart->has_discount( 'sam-akce' ), (string) $t );

echo "== Každá třetí zdarma\n";
$t = cart( array( 1115 => 3, 1125 => 3 ), array_merge( $bf, array( 'nth' => 3 ) ) );
$exp = 3 * $price( 1115, true ) + 3 * $price( 1125, true ) - 2 * $price( 1125, true );
ok( '6 vstupenek: 2 nejlevnější zdarma', abs( $t - $exp ) < 0.01, "$t / $exp" );

echo "== Sleva 10 % z online cen\n";
$t = cart( array( 1115 => 2 ), array_merge( $bf, array( 'type' => 'percent', 'percent' => 10, 'regular' => 0 ) ) );
$exp = round( 2 * $price( 1115, false ) * 0.9, 2 );
ok( '2× dospělý −10 %', abs( $t - $exp ) < 0.02, "$t / $exp" );

echo "== Mimo termín / vypnuto\n";
$t = cart( array( 1115 => 2 ), array_merge( $bf, array( 'from' => '2099-01-01 00:00' ) ) );
ok( 'akce ještě nezačala: online cena, bez slevy', abs( $t - 2 * $price( 1115, false ) ) < 0.01 && ! WC()->cart->has_discount( 'sam-akce' ), (string) $t );
$t = cart( array( 1115 => 2 ), array_merge( $bf, array( 'enabled' => 0 ) ) );
ok( 'vypnutá akce: online cena', abs( $t - 2 * $price( 1115, false ) ) < 0.01, (string) $t );

echo "== Magenta\n";
$code = 'MAGENTA-TEST' . wp_rand( 100, 999 );
$c = new WC_Coupon();
$c->set_code( $code ); $c->set_discount_type( 'percent' ); $c->set_amount( 50 ); $c->set_individual_use( true );
$c->set_product_ids( array( SAM_Promo::MAGENTA_PRODUCT ) ); $c->set_usage_limit( 1 ); $c->save();
$t = cart( array( SAM_Promo::MAGENTA_PRODUCT => 1, 1115 => 1 ), $bf, $code );
$exp = $price( SAM_Promo::MAGENTA_PRODUCT, false ) / 2 + $price( 1115, true );
ok( 'Magenta 1+1 −50 % + dospělý', abs( $t - $exp ) < 0.01, "$t / $exp" );
$t = cart( array( SAM_Promo::MAGENTA_PRODUCT => 1, 1115 => 2 ), $bf, $code );
ok( 'kód Magenta se s akcí nekombinuje', ! WC()->cart->has_discount( 'sam-akce' ) && WC()->cart->has_discount( wc_strtolower( $code ) ), implode( ',', WC()->cart->get_applied_coupons() ) );
wp_delete_post( $c->get_id(), true );

echo "== Kalkulačka (data pro web)\n";
update_option( SAM_Promo::OPTION, array_merge( SAM_Promo::defaults(), $bf ) );
$o = sam_ticket_offer( 'cs', true );
ok( 'Magenta řádek v kalkulačce, max 1', 'magenta' === $o['rows'][0]['k'] && 1 === $o['rows'][0]['m'] );
ok( 'pravidlo akce předané do JS', $o['promo'] && 'nth' === $o['rule']['type'] && 2 === $o['rule']['nth'] );
$o = sam_ticket_offer( 'en' );
ok( 'EN kalkulačka v EUR', 'EUR' === $o['currency'] && count( $o['rows'] ) >= 5 );

WC()->cart->empty_cart();
WC()->cart->remove_coupons();
update_option( SAM_Promo::OPTION, $saved );
echo "== Nastavení vráceno\n";
