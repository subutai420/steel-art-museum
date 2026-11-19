<?php
/**
 * Podpis webhooku, převod částek a kódování požadavků Stripe.
 * Spuštění: php tests/test-stripe.php
 */

define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}

require_once dirname( __DIR__ ) . '/includes/class-sam-stripe-config.php';
require_once dirname( __DIR__ ) . '/includes/class-sam-stripe-client.php';
require_once dirname( __DIR__ ) . '/includes/class-sam-stripe-webhook.php';

function check( $label, $condition ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $label\n" );
		exit( 1 );
	}
}

// Převod částek na nejmenší jednotky.
check( 'CZK', 34900 === SAM_Stripe_Config::to_minor( 349, 'CZK' ) );
check( 'CZK decimals', 69800 === SAM_Stripe_Config::to_minor( '698.00', 'czk' ) );
check( 'EUR rounding', 1250 === SAM_Stripe_Config::to_minor( 12.4999999, 'EUR' ) );
check( 'float noise', 1569 * 100 === SAM_Stripe_Config::to_minor( 1569.0000001, 'CZK' ) );
check( 'zero-decimal JPY', 500 === SAM_Stripe_Config::to_minor( 500, 'JPY' ) );

// Form-encoding ve stylu Stripe.
$encoded = SAM_Stripe_Client::encode(
	array(
		'amount'                    => 34900,
		'automatic_payment_methods' => array( 'enabled' => true ),
		'metadata'                  => array( 'order_id' => 12, 'note' => 'Děti & dospělí' ),
		'expand'                    => array( 'latest_charge' ),
		'skip'                      => null,
	)
);
check( 'encode', 'amount=34900&automatic_payment_methods%5Benabled%5D=true&metadata%5Border_id%5D=12&metadata%5Bnote%5D=D%C4%9Bti%20%26%20dosp%C4%9Bl%C3%AD&expand%5B0%5D=latest_charge' === $encoded );

// Podpis webhooku.
$secret  = 'whsec_test_secret';
$payload = '{"id":"evt_1","type":"payment_intent.succeeded"}';
$now     = 1795000000;
$sig     = hash_hmac( 'sha256', $now . '.' . $payload, $secret );
check( 'valid signature', SAM_Stripe_Webhook::verify_signature( $payload, "t=$now,v1=$sig", $secret, $now + 10 ) );
check( 'second v1 entry', SAM_Stripe_Webhook::verify_signature( $payload, "t=$now,v1=deadbeef,v1=$sig,v0=old", $secret, $now ) );
check( 'tampered payload', ! SAM_Stripe_Webhook::verify_signature( $payload . ' ', "t=$now,v1=$sig", $secret, $now ) );
check( 'wrong secret', ! SAM_Stripe_Webhook::verify_signature( $payload, "t=$now,v1=$sig", 'whsec_other', $now ) );
check( 'too old', ! SAM_Stripe_Webhook::verify_signature( $payload, "t=$now,v1=$sig", $secret, $now + 301 ) );
check( 'missing header', ! SAM_Stripe_Webhook::verify_signature( $payload, '', $secret, $now ) );
check( 'missing secret', ! SAM_Stripe_Webhook::verify_signature( $payload, "t=$now,v1=$sig", '', $now ) );

echo "Stripe tests passed.\n";
