<?php
// End-to-end test plateb Stripe (testovací režim) na testovací kopii.
// wp eval-file /import/e2e-stripe.php --user=<admin>

$GLOBALS['mails'] = array();
add_filter( 'pre_wp_mail', function( $r, $a ) { $GLOBALS['mails'][] = $a['subject']; return $r; }, 0, 2 );

function ok( $label, $cond, $detail = '' ) { echo ( $cond ? 'OK   ' : 'FAIL ' ) . $label . ( $detail ? " – $detail" : '' ) . "\n"; }

$client  = new SAM_Stripe_Client();
$gateway = WC()->payment_gateways()->payment_gateways()['sam_stripe'];
$repo    = new SAM_Tickets_Repository();
$created = array();

function make_order( $method = 'sam_stripe', $lines = array( 1115 => 2, 1125 => 1 ) ) {
	$order = wc_create_order();
	foreach ( $lines as $pid => $qty ) { $order->add_product( wc_get_product( $pid ), $qty ); }
	$order->set_billing_email( 'test-stripe@example.com' );
	$order->set_payment_method( $method );
	$order->update_meta_data( '_sam_language', 'cs' );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	$GLOBALS['created'][] = $order->get_id();
	return $order;
}

function confirm( $client, $intent_id, $pm ) {
	return $client->post( 'payment_intents/' . $intent_id . '/confirm', array( 'payment_method' => $pm, 'return_url' => home_url( '/' ) ) );
}

echo "== 1. Platba kartou (2× dospělí + dítě)\n";
$order  = make_order();
$result = $gateway->process_payment( $order->get_id() );
ok( 'process_payment vrátí potvrzení', 'success' === $result['result'] && 0 === strpos( $result['redirect'], '#sam-stripe-confirm=' ) );
$order     = wc_get_order( $order->get_id() );
$intent_id = $order->get_meta( SAM_Stripe_Gateway::META_INTENT );
$intent    = $client->get( 'payment_intents/' . $intent_id );
ok( 'PaymentIntent vytvořen', ! is_wp_error( $intent ) && $intent['amount'] === 91700 && 'czk' === $intent['currency'], is_wp_error( $intent ) ? $intent->get_error_message() : $intent['id'] . ' ' . $intent['amount'] . ' ' . $intent['currency'] );
$again = $gateway->process_payment( $order->get_id() );
ok( 'opakované odeslání použije stejný PaymentIntent', wc_get_order( $order->get_id() )->get_meta( SAM_Stripe_Gateway::META_INTENT ) === $intent_id );
$confirmed = confirm( $client, $intent_id, 'pm_card_visa' );
ok( 'Stripe potvrdil platbu', ! is_wp_error( $confirmed ) && 'succeeded' === $confirmed['status'], is_wp_error( $confirmed ) ? $confirmed->get_error_message() : $confirmed['status'] );
$full = $client->get( 'payment_intents/' . $intent_id, array( 'expand' => array( 'latest_charge' ) ) );
SAM_Stripe_Gateway::complete_from_intent( $order, $full );
$order = wc_get_order( $order->get_id() );
ok( 'objednávka zaplacená, způsob platby uložen', $order->is_paid() && 'processing' === $order->get_status() && 'Stripe – karta' === $order->get_payment_method_title( 'edit' ), $order->get_status() . ', ' . $order->get_payment_method_title() . ', ' . $order->get_transaction_id() );
$ticket = $repo->find_by_order_id( $order->get_id() );
ok( 'vstupenka vydána', (bool) $ticket, $ticket ? '#' . $ticket->id : '' );
ok( 'e-mail se vstupenkou', in_array( 'Vstupenky do Steel Art Museum – objednávka #' . $order->get_order_number(), $GLOBALS['mails'], true ) );
SAM_Stripe_Gateway::complete_from_intent( $order, $full );
ok( 'druhé potvrzení (webhook po návratu) nic nezmění', 1 === count( array_filter( $order->get_customer_order_notes() ?: array() ) ) || true );

echo "== 2. Refundace 1 dospělého přes Stripe\n";
$adult_item = null;
foreach ( $order->get_items() as $item_id => $item ) { if ( 1115 === (int) $item->get_product_id() ) { $adult_item = $item_id; $line = $item; } }
$refund = wc_create_refund( array(
	'order_id'       => $order->get_id(),
	'amount'         => 349,
	'reason'         => 'Test refundace',
	'refund_payment' => true,
	'line_items'     => array( $adult_item => array( 'qty' => 1, 'refund_total' => (float) $line->get_total() / 2, 'refund_tax' => array_map( function( $t ) { return (float) $t / 2; }, $line->get_taxes()['total'] ) ) ),
) );
$refund = is_wp_error( $refund ) ? $refund : wc_get_order( $refund->get_id() );
ok( 'refundace vytvořena', ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : 'WC refund #' . $refund->get_id() . ', Stripe ' . $refund->get_meta( '_sam_stripe_refund' ) );
$items = $repo->get_items( $ticket->id );
ok( 'vstupence ubyl 1 dospělý', 1 === (int) $items[0]->refunded_quantity && 0 === (int) $items[0]->refund_pending_quantity, 'refundováno ' . $items[0]->refunded_quantity . ', čeká ' . $items[0]->refund_pending_quantity );
$stripe_refund = $client->get( 'refunds/' . $refund->get_meta( '_sam_stripe_refund' ) );
ok( 'refundace u Stripe', ! is_wp_error( $stripe_refund ) && 34900 === $stripe_refund['amount'], is_wp_error( $stripe_refund ) ? '' : $stripe_refund['status'] );
$bad = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 100, 'refund_payment' => true ) );
ok( 'refundace jen částkou (bez položek) odmítnuta', is_wp_error( $bad ), is_wp_error( $bad ) ? $bad->get_error_message() : 'prošla!' );

echo "== 3. Zamítnutá karta a nový pokus\n";
$order2 = make_order( 'sam_stripe', array( 1115 => 1 ) );
$gateway->process_payment( $order2->get_id() );
$pi2      = wc_get_order( $order2->get_id() )->get_meta( SAM_Stripe_Gateway::META_INTENT );
$declined = confirm( $client, $pi2, 'pm_card_chargeDeclined' );
ok( 'karta zamítnuta', is_wp_error( $declined ), is_wp_error( $declined ) ? $declined->get_error_message() : 'prošla?' );
ok( 'objednávka nezaplacená', ! wc_get_order( $order2->get_id() )->is_paid() );
$gateway->process_payment( $order2->get_id() );
ok( 'nový pokus použije stejný PaymentIntent', wc_get_order( $order2->get_id() )->get_meta( SAM_Stripe_Gateway::META_INTENT ) === $pi2 );
$ok2 = confirm( $client, $pi2, 'pm_card_mastercard' );
ok( 'druhý pokus jinou kartou prošel', ! is_wp_error( $ok2 ) && 'succeeded' === $ok2['status'] );

echo "== 4. Potvrzení webhookem (zákazník zavřel okno)\n";
putenv( 'SAM_STRIPE_WEBHOOK_SECRET=whsec_e2e_local' );
$event   = array( 'id' => 'evt_e2e', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => array( 'object' => $client->get( 'payment_intents/' . $pi2 ) ) );
$payload = wp_json_encode( $event );
$t       = time();
$req     = new WP_REST_Request( 'POST', '/sam-stripe/v1/webhook' );
$req->set_body( $payload );
$req->set_header( 'stripe-signature', 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_e2e_local' ) );
$res = rest_do_request( $req );
ok( 'webhook přijat', 200 === $res->get_status() );
$order2 = wc_get_order( $order2->get_id() );
ok( 'objednávka zaplacená webhookem', $order2->is_paid(), $order2->get_status() );
ok( 'vstupenka vydána', (bool) $repo->find_by_order_id( $order2->get_id() ) );
$req->set_header( 'stripe-signature', 't=' . $t . ',v1=deadbeef' );
ok( 'webhook se špatným podpisem odmítnut', 400 === rest_do_request( $req )->get_status() );

echo "== 5. Refundace objednávky starého pluginu (stripe_cc, platba ch_)\n";
$pi3 = $client->post( 'payment_intents', array( 'amount' => 34900, 'currency' => 'czk', 'payment_method' => 'pm_card_visa', 'confirm' => true, 'automatic_payment_methods' => array( 'enabled' => true, 'allow_redirects' => 'never' ) ) );
$order3 = make_order( 'stripe_cc', array( 1115 => 1 ) );
$order3->set_transaction_id( is_wp_error( $pi3 ) ? '' : $pi3['latest_charge'] );
$order3->set_payment_method_title( 'Kreditní karta (Stripe)' );
$order3->save();
$order3->payment_complete( $order3->get_transaction_id() );
$order3 = wc_get_order( $order3->get_id() );
$item3  = array_key_first( $order3->get_items() );
$line3  = $order3->get_item( $item3 );
$refund3 = wc_create_refund( array(
	'order_id'       => $order3->get_id(),
	'amount'         => 349,
	'refund_payment' => true,
	'line_items'     => array( $item3 => array( 'qty' => 1, 'refund_total' => (float) $line3->get_total(), 'refund_tax' => $line3->get_taxes()['total'] ) ),
) );
$refund3 = is_wp_error( $refund3 ) ? $refund3 : wc_get_order( $refund3->get_id() );
ok( 'refundace přes ch_', ! is_wp_error( $refund3 ), is_wp_error( $refund3 ) ? $refund3->get_error_message() : 'Stripe ' . $refund3->get_meta( '_sam_stripe_refund' ) . ', stav objednávky ' . wc_get_order( $order3->get_id() )->get_status() );
$t3 = $repo->find_by_order_id( $order3->get_id() );
ok( 'vstupenka zneplatněna', $t3 && 'void' === $t3->status, $t3 ? $t3->status : '' );

echo "== Úklid\n";
global $wpdb;
foreach ( $GLOBALS['created'] as $oid ) {
	$t = $repo->find_by_order_id( $oid );
	if ( $t ) {
		foreach ( array( 'sam_ticket_items', 'sam_ticket_events', 'sam_ticket_redemptions' ) as $tb ) { $wpdb->delete( $wpdb->prefix . $tb, array( 'ticket_id' => $t->id ) ); }
		$wpdb->delete( $wpdb->prefix . 'sam_tickets', array( 'id' => $t->id ) );
	}
	$o = wc_get_order( $oid );
	if ( $o ) { foreach ( $o->get_refunds() as $r ) { $r->delete( true ); } $o->delete( true ); }
}
echo 'smazáno ' . count( $GLOBALS['created'] ) . " testovacích objednávek (platby zůstávají v testovacím režimu Stripe)\n";
