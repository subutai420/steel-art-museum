<?php
/**
 * Pevná platnost vstupenek, platnost převáděných starých vstupenek
 * a rozpoznání kódů ve skeneru (nový token i staré formáty).
 *
 * Spuštění: php tests/test-validity.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['sam_option'] = array();
function wp_timezone() { return new DateTimeZone( 'Europe/Prague' ); }
function get_option( $name, $default = false ) { return $GLOBALS['sam_option'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['sam_option'][ $name ] = $value; return true; }
function sanitize_email( $v ) { return trim( $v ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function absint( $v ) { return abs( (int) $v ); }
function add_action() {}
function register_rest_route() {}

require_once dirname( __DIR__ ) . '/includes/class-sam-tickets-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-sam-tickets-validity.php';
require_once dirname( __DIR__ ) . '/includes/class-sam-tickets-legacy.php';
require_once dirname( __DIR__ ) . '/includes/class-sam-tickets-rest.php';

function check( $label, $condition ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $label\n" );
		exit( 1 );
	}
}

// Výchozí platnost: 30. 6. 2027 do konce dne místního času.
$end = SAM_Tickets_Validity::calculate_valid_until();
check( 'default date', '2027-06-30 23:59:59' === $end->format( 'Y-m-d H:i:s' ) );
check( 'local timezone', 'Europe/Prague' === $end->getTimezone()->getName() );
check( 'gmt summer time', '2027-06-30 21:59:59' === SAM_Tickets_Validity::valid_until_gmt() );
check( 'gmt winter time', '2026-12-31 22:59:59' === SAM_Tickets_Validity::valid_until_gmt( '2026-12-31' ) );

// Změna v nastavení se projeví u nových vstupenek.
SAM_Tickets_Settings::update( array( 'valid_until' => '2027-12-31', 'report_email' => 'ucetni@example.com, info@steelartmuseum.eu' ) );
check( 'changed date', '2027-12-31 23:59:59' === SAM_Tickets_Validity::calculate_valid_until()->format( 'Y-m-d H:i:s' ) );
check( 'two recipients', array( 'ucetni@example.com', 'info@steelartmuseum.eu' ) === SAM_Tickets_Settings::report_recipients() );

// Neplatné datum se neuloží.
SAM_Tickets_Settings::update( array( 'valid_until' => '2027-02-30' ) );
check( 'invalid date rejected', '2027-12-31' === SAM_Tickets_Settings::valid_until_date() );
SAM_Tickets_Settings::update( array( 'report_email' => 'nesmysl' ) );
check( 'invalid email rejected', 2 === count( SAM_Tickets_Settings::report_recipients() ) );

// Platnost převáděných vstupenek ze starého systému.
check( 'old order expired', null === SAM_Tickets_Legacy::valid_until_for( 28450, '2026-05-20 10:00:00' ) );
check( 'first valid order', '2026-12-31' === SAM_Tickets_Legacy::valid_until_for( 28451, '2026-05-20 10:00:00' ) );
check( 'before template change', '2026-12-31' === SAM_Tickets_Legacy::valid_until_for( 32000, '2026-10-29 10:07:14' ) );
check( 'after template change', '2027-06-30' === SAM_Tickets_Legacy::valid_until_for( 32001, '2026-10-29 10:07:16' ) );

// Rozpoznání kódů ve skeneru.
$token = str_repeat( 'ab', 24 );
check( 'new token', array( 'type' => 'token', 'value' => $token ) === SAM_Tickets_REST::parse_code( $token ) );
check( 'new token uppercase', array( 'type' => 'token', 'value' => $token ) === SAM_Tickets_REST::parse_code( strtoupper( $token ) ) );
check( 'legacy public id', array( 'type' => 'legacy', 'value' => '6V510D98USJTAYCK99FBC' ) === SAM_Tickets_REST::parse_code( '488677182-30250-6V510D98USJTAYCK99FBC' ) );
check( 'legacy url', array( 'type' => 'legacy', 'value' => '6V510D98USJTAYCK99FBC' ) === SAM_Tickets_REST::parse_code( 'https://steelartmuseum.eu/wp-content/plugins/event-tickets-with-ticket-scanner/ticket/488677182-30250-6V510D98USJTAYCK99FBC' ) );
check( 'legacy display code', array( 'type' => 'legacy', 'value' => '6V510D98USJTAYCK99FBC' ) === SAM_Tickets_REST::parse_code( '6V5-10D-98U-SJT-AYC-K99-FBC' ) );
check( 'legacy lowercase', array( 'type' => 'legacy', 'value' => '6V510D98USJTAYCK99FBC' ) === SAM_Tickets_REST::parse_code( ' 6v510d98usjtayck99fbc ' ) );
check( 'garbage', null === SAM_Tickets_REST::parse_code( 'ahoj' ) );
check( 'empty', null === SAM_Tickets_REST::parse_code( '' ) );

echo "Validity and code parsing tests passed.\n";
