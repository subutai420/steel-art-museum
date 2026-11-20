<?php
// E-maily podle jazyka objednávky: vstupenky a refundace v CZ i EN (nic se neodesílá).
// wp eval-file /import/emails-en.php --user=<admin>

function ok( $label, $cond, $detail = '' ) { echo ( $cond ? 'OK   ' : 'FAIL ' ) . $label . ( '' !== $detail ? " – $detail" : '' ) . "\n"; }
$GLOBALS['mails'] = array();
add_filter( 'pre_wp_mail', function ( $r, $a ) { $GLOBALS['mails'][] = $a; return true; }, 0, 2 );
add_filter( 'sam_tickets_online_gateways', '__return_empty_array', 99 );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) { return new WP_Error( 'blocked', 'test' ); }, 0, 3 );

$created = array();
foreach ( array( 'cs' => 1115, 'en' => 1835 ) as $lang => $pid ) {
	do_action( 'wpml_switch_language', $lang );
	$GLOBALS['mails'] = array();
	$o = wc_create_order();
	$o->add_product( wc_get_product( $pid ), 1 );
	$o->set_billing_email( 'test-mail@example.com' );
	$o->set_currency( 'en' === $lang ? 'EUR' : 'CZK' );
	$o->set_payment_method( 'sam_stripe' );
	$o->update_meta_data( '_sam_language', $lang );
	$o->update_meta_data( 'wpml_language', $lang );
	$o->update_meta_data( '_sam_invoice_requested', 'no' );
	$o->calculate_totals();
	$o->set_status( 'pending' );
	$o->save();
	$o->payment_complete( 'pi_test_mail_' . $o->get_id() );
	$created[] = $o->get_id();
	$m = null;
	foreach ( $GLOBALS['mails'] as $x ) { if ( 'test-mail@example.com' === $x['to'] || array( 'test-mail@example.com' ) === $x['to'] ) { $m = $x; } }
	$want = 'en' === $lang ? 'Steel Art Museum tickets' : 'Vstupenky do Steel Art Museum';
	ok( "$lang: e-mail se vstupenkou", $m && false !== strpos( $m['subject'], $want ), $m ? $m['subject'] : 'žádný' );
	ok( "$lang: QR kód v e-mailu", $m && false !== stripos( $m['message'], 'qr' ) );

	$GLOBALS['mails'] = array();
	$o = wc_get_order( $o->get_id() );
	$li = array();
	foreach ( $o->get_items() as $iid => $it ) { $li[ $iid ] = array( 'qty' => 1, 'refund_total' => (float) $it->get_total(), 'refund_tax' => $it->get_taxes()['total'] ); }
	$r = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => (float) $o->get_total(), 'reason' => 'test', 'line_items' => $li ) );
	$m = null;
	foreach ( $GLOBALS['mails'] as $x ) { if ( false !== strpos( implode( ',', (array) $x['to'] ), 'test-mail@' ) ) { $m = $x; } }
	ok( "$lang: e-mail o refundaci", (bool) $m, $m ? $m['subject'] : ( is_wp_error( $r ) ? $r->get_error_message() : 'žádný' ) );
	if ( $m && 'en' === $lang ) {
		ok( 'en: refundace anglicky', ! preg_match( '/vrácen|objednávk/iu', $m['subject'] ), $m['subject'] );
	}
}

// Úklid
global $wpdb;
$repo = new SAM_Tickets_Repository();
foreach ( $created as $oid ) {
	$t = $repo->find_by_order_id( $oid );
	if ( $t ) {
		foreach ( array( 'sam_ticket_items', 'sam_ticket_events', 'sam_ticket_redemptions' ) as $tb ) { $wpdb->delete( $wpdb->prefix . $tb, array( 'ticket_id' => $t->id ) ); }
		$wpdb->delete( $wpdb->prefix . 'sam_tickets', array( 'id' => $t->id ) );
	}
	$o = wc_get_order( $oid );
	foreach ( $o->get_refunds() as $r ) { $r->delete( true ); }
	$o->delete( true );
}
echo "== Úklid hotový\n";
