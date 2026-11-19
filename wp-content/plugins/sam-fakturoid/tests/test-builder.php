<?php
/**
 * Řádky faktury, sazby DPH, opravný doklad a odběratel.
 * Spuštění: php tests/test-builder.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-sam-fakturoid-builder.php';

function check( $label, $condition ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $label\n" );
		exit( 1 );
	}
}

// Sazby DPH.
check( '12 %', 12 === SAM_Fakturoid_Builder::vat_rate( 349, 37.39 ) );
check( '21 %', 21 === SAM_Fakturoid_Builder::vat_rate( 121, 21 ) );
check( 'bez daně', 0 === SAM_Fakturoid_Builder::vat_rate( 100, 0 ) );
check( 'nulová položka', 0 === SAM_Fakturoid_Builder::vat_rate( 0, 0 ) );

// 2 dospělí za 698 Kč, jeden zdarma (akce) → 349 Kč za řádek, 174,50 Kč za kus.
$lines = SAM_Fakturoid_Builder::lines( array( array( 'name' => 'Dospělí', 'qty' => 2, 'total' => 349, 'tax' => 37.39 ) ) );
check( 'jeden řádek', 1 === count( $lines ) && '174.50' === $lines[0]['unit_price'] && 2 === $lines[0]['quantity'] && 12 === $lines[0]['vat_rate'] );

// 3 kusy za 100 Kč nejde dělit → 2 × 33,33 + 1 × 33,34.
$lines = SAM_Fakturoid_Builder::lines( array( array( 'name' => 'Děti', 'qty' => 3, 'total' => 100, 'tax' => 10.71 ) ) );
check( 'rozdělený řádek', 2 === count( $lines ) && 2 === $lines[0]['quantity'] && '33.33' === $lines[0]['unit_price'] && 1 === $lines[1]['quantity'] && '33.34' === $lines[1]['unit_price'] );
check( 'součet sedí', 100.0 === SAM_Fakturoid_Builder::lines_total( $lines ) );

// Faktura.
$inv = SAM_Fakturoid_Builder::invoice( array(
	'order_id' => 32500, 'number' => '32500', 'date' => '2026-11-19', 'currency' => 'czk', 'language' => 'en', 'subject_id' => 7,
	'items'    => array( array( 'name' => 'Adults', 'qty' => 1, 'total' => 349, 'tax' => 37.39 ) ), 'note' => 'x',
), 235201 );
check( 'custom_id', 'wc-32500' === $inv['custom_id'] );
check( 'VS', '32500' === $inv['variable_symbol'] );
check( 'měna a jazyk', 'CZK' === $inv['currency'] && 'en' === $inv['language'] );
check( 'ceny s DPH', 'from_total_with_vat' === $inv['vat_price_mode'] );
check( 'řada', 235201 === $inv['number_format_id'] );
check( 'karta, uhrazeno', 'card' === $inv['payment_method'] && 0 === $inv['due'] );
check( 'čeština', 'cz' === SAM_Fakturoid_Builder::invoice( array( 'order_id' => 1, 'number' => '1', 'date' => '2026-11-19', 'currency' => 'CZK', 'language' => 'cs', 'subject_id' => 1, 'items' => array() ) )['language'] );

// Opravný doklad: refundace 1 dospělého (položky WooCommerce refundace jsou záporné).
$cor = SAM_Fakturoid_Builder::correction( array(
	'refund_id' => 55, 'invoice_id' => 999, 'order_number' => '32500', 'date' => '2026-11-20', 'currency' => 'CZK', 'language' => 'cs', 'subject_id' => 7,
	'items'     => array( array( 'name' => 'Dospělí', 'qty' => -1, 'total' => -174.5, 'tax' => -18.7 ) ), 'amount' => 174.5, 'reason' => 'Nemoc',
) );
check( 'typ opravy', 'correction' === $cor['document_type'] && 999 === $cor['correction_id'] && 'wc-refund-55' === $cor['custom_id'] );
check( 'záporný počet', 1 === count( $cor['lines'] ) && -1 === $cor['lines'][0]['quantity'] && '174.50' === $cor['lines'][0]['unit_price'] );
check( 'součet opravy', -174.5 === SAM_Fakturoid_Builder::lines_total( $cor['lines'] ) );

// Refundace jen částkou → dorovnávací řádek.
$cor = SAM_Fakturoid_Builder::correction( array(
	'refund_id' => 56, 'invoice_id' => 999, 'order_number' => '1', 'date' => '2026-11-20', 'currency' => 'CZK', 'language' => 'cs', 'subject_id' => 7,
	'items'     => array(), 'amount' => 50, 'reason' => '',
) );
check( 'dorovnání', 1 === count( $cor['lines'] ) && -1 === $cor['lines'][0]['quantity'] && '50.00' === $cor['lines'][0]['unit_price'] && 'Vrácení platby' === $cor['lines'][0]['name'] );

// Odběratel.
$s = SAM_Fakturoid_Builder::subject( array( 'company' => 'Test s.r.o.', 'first_name' => 'Jan', 'last_name' => 'Novák', 'ic' => '12345678', 'dic' => 'CZ12345678', 'street' => 'Celetná 15', 'city' => 'Praha', 'zip' => '11000', 'country' => 'CZ', 'email' => 'a@b.cz' ) );
check( 'firma', 'Test s.r.o.' === $s['name'] && 'Jan Novák' === $s['full_name'] && '12345678' === $s['registration_no'] && 'CZ12345678' === $s['vat_no'] );
$s = SAM_Fakturoid_Builder::subject( array( 'first_name' => 'Jan', 'last_name' => 'Novák', 'street' => 'X', 'city' => 'Y', 'zip' => '1', 'country' => 'CZ' ) );
check( 'osoba', 'Jan Novák' === $s['name'] && ! isset( $s['full_name'] ) && ! isset( $s['registration_no'] ) );

echo "OK test-builder\n";
