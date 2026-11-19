<?php
// End-to-end test vstupenek na testovací kopii. Spouští se: wp eval-file /import/e2e-tickets.php --user=<admin>
// E-maily zachytí před pojistkou (ta je pak zablokuje) a uloží do /import/e2e-mail-N.*

$GLOBALS['e2e_mail'] = 0;
add_filter(
	'pre_wp_mail',
	function( $return, $atts ) {
		$n = ++$GLOBALS['e2e_mail'];
		file_put_contents( "/import/e2e-mail-$n.html", "TO: " . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . "\nSUBJECT: {$atts['subject']}\n\n" . $atts['message'] );
		foreach ( (array) $atts['attachments'] as $i => $file ) {
			if ( is_readable( $file ) ) {
				copy( $file, "/import/e2e-mail-$n-att$i." . pathinfo( $file, PATHINFO_EXTENSION ) );
			}
		}
		echo "  [mail $n] {$atts['subject']} → " . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . ' (' . count( (array) $atts['attachments'] ) . " příloh)\n";
		return $return;
	},
	0,
	2
);

function e2e_check( $label, $ok, $detail = '' ) {
	echo ( $ok ? 'OK   ' : 'FAIL ' ) . $label . ( $detail ? " – $detail" : '' ) . "\n";
}

function e2e_rest( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/sam-tickets/v1/' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

$repository = new SAM_Tickets_Repository();
$service    = new SAM_Tickets_Service( $repository );

echo "== 1. Ruční vstupenka (2× dospělí, 1× dítě, 1× rodinné 2+1)\n";
$order = wc_create_order( array( 'created_via' => 'sam-manual-ticket' ) );
foreach ( array( 1115 => 2, 1125 => 1, 1124 => 1 ) as $product_id => $qty ) {
	$order->add_product( wc_get_product( $product_id ), $qty );
}
$order->set_billing_email( 'test-vstupenky@example.com' );
$order->update_meta_data( '_sam_ticket_source', 'gift' );
$order->update_meta_data( '_sam_language', 'cs' );
$order->update_meta_data( '_sam_no_invoice', 'yes' );
$order->calculate_totals();
$order->set_payment_method( 'sam_manual' );
$order->set_payment_method_title( 'Ruční vystavení vstupenky' );
$order->set_date_paid( time() );
$order->set_status( 'processing' ); // spustí vydání vstupenky i e-mail
$order->save();

$ticket = $repository->find_by_order_id( $order->get_id() );
e2e_check( 'vstupenka vydána', (bool) $ticket, $ticket ? "#{$ticket->id}, objednávka #{$order->get_id()}, platí do " . get_date_from_gmt( $ticket->valid_until, 'j. n. Y H:i' ) : '' );
$items = $ticket ? $repository->get_items( $ticket->id ) : array();
e2e_check( 'položky', 3 === count( $items ), implode( ', ', array_map( function( $i ) { return "{$i->purchased_quantity}× {$i->label} ({$i->unit_type}, {$i->unit_price} Kč)"; }, $items ) ) );
$pdf = (string) wc_get_order( $order->get_id() )->get_meta( '_sam_ticket_pdf_path', true );
e2e_check( 'PDF existuje', $pdf && is_readable( $pdf ), $pdf ? basename( $pdf ) . ' ' . round( filesize( $pdf ) / 1024 ) . ' kB' : '' );
if ( $pdf ) {
	copy( $pdf, '/import/e2e-ticket.pdf' );
}
e2e_check( 'e-mail se vstupenkou odeslán', $GLOBALS['e2e_mail'] >= 1 );

echo "== 2. Skener – nová vstupenka\n";
list( $status, $data ) = e2e_rest( 'GET', 'lookup', array( 'token' => $ticket->token ) );
e2e_check( 'lookup 200', 200 === $status, ( $data['status'] ?? '' ) . ', položek ' . count( $data['items'] ?? array() ) );
$adult = $data['items'][0]['id'];
list( $status, $data ) = e2e_rest( 'POST', 'redeem', array( 'token' => strtoupper( $ticket->token ), 'items' => array( array( 'id' => $adult, 'quantity' => 1 ) ), 'operationId' => 'e2e-op-1' ) );
e2e_check( 'uplatnění 1 dospělého', 200 === $status && 'partially_redeemed' === $data['status'], $data['status'] ?? json_encode( $data ) );
list( $status, $data ) = e2e_rest( 'POST', 'redeem', array( 'token' => $ticket->token, 'items' => array( array( 'id' => $adult, 'quantity' => 1 ) ), 'operationId' => 'e2e-op-1' ) );
e2e_check( 'opakovaný požadavek neodečte znovu', 200 === $status && 1 === $data['items'][0]['redeemed'], 'uplatněno ' . ( $data['items'][0]['redeemed'] ?? '?' ) );
list( $status, $data ) = e2e_rest( 'POST', 'redeem', array( 'token' => $ticket->token, 'items' => array( array( 'id' => $adult, 'quantity' => 5 ) ), 'operationId' => 'e2e-op-2' ) );
e2e_check( 'víc kusů, než zbývá, odmítnuto', 409 === $status, $data['message'] ?? '' );

echo "== 3. Skener – starý kód (objednávka 30250)\n";
global $wpdb;
$legacy = $wpdb->get_results( "SELECT code, redeemed FROM {$wpdb->prefix}saso_eventtickets_codes WHERE order_id = 30250 ORDER BY redeemed" );
foreach ( $legacy as $row ) {
	$display = implode( '-', str_split( $row->code, 3 ) );
	list( $status, $data ) = e2e_rest( 'GET', 'lookup', array( 'token' => '488677182-30250-' . $row->code ) );
	e2e_check( "starý kód {$display} (" . ( $row->redeemed ? 'uplatněný' : 'neuplatněný' ) . ')', 200 === $status, ( $data['status'] ?? '' ) . ', zbývá ' . array_sum( array_column( $data['items'] ?? array(), 'remaining' ) ) . ' z ' . array_sum( array_column( $data['items'] ?? array(), 'purchased' ) ) . ', platí do ' . ( $data['validUntil'] ?? '' ) );
	list( $status2 ) = e2e_rest( 'GET', 'lookup', array( 'token' => $display ) );
	e2e_check( '  i zobrazený tvar s pomlčkami', 200 === $status2 );
}
list( $status, $data ) = e2e_rest( 'GET', 'lookup', array( 'token' => 'NEEXISTUJICIKOD123456' ) );
e2e_check( 'neznámý kód → 404', 404 === $status, $data['message'] ?? '' );
$expired_code = $wpdb->get_var( "SELECT code FROM {$wpdb->prefix}saso_eventtickets_codes WHERE order_id < 28000 AND redeemed = 0 LIMIT 1" );
list( $status, $data ) = e2e_rest( 'GET', 'lookup', array( 'token' => $expired_code ) );
e2e_check( 'propadlý starý kód → 404', 404 === $status, $data['message'] ?? '' );

echo "== 4. Propadnutí a report\n";
$wpdb->update( $wpdb->prefix . 'sam_tickets', array( 'valid_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $ticket->id ) );
list( $status, $data ) = e2e_rest( 'GET', 'lookup', array( 'token' => $ticket->token ) );
e2e_check( 'po platnosti skener hlásí prošlou', 'expired' === ( $data['status'] ?? '' ) && false === $data['canRedeem'] );
$expired = $repository->expire_overdue();
e2e_check( 'denní úloha zneplatnila 1 vstupenku', 1 === count( $expired ) && (int) $expired[0]->id === (int) $ticket->id, count( $expired ) . ' zneplatněno' );
SAM_Tickets_Reports::send_expired_report( $expired );
e2e_check( 'druhé spuštění nic nezneplatní', 0 === count( $repository->expire_overdue() ) );

echo "== 5. Měsíční report neuplatněných\n";
$count = SAM_Tickets_Reports::send_unredeemed_report( $repository, true );
e2e_check( 'report odeslán', ! is_wp_error( $count ), is_wp_error( $count ) ? $count->get_error_message() : "$count vstupenek" );

echo "== Úklid testovací objednávky\n";
$wpdb->delete( $wpdb->prefix . 'sam_ticket_items', array( 'ticket_id' => $ticket->id ) );
$wpdb->delete( $wpdb->prefix . 'sam_ticket_events', array( 'ticket_id' => $ticket->id ) );
$wpdb->delete( $wpdb->prefix . 'sam_ticket_redemptions', array( 'ticket_id' => $ticket->id ) );
$wpdb->delete( $wpdb->prefix . 'sam_tickets', array( 'id' => $ticket->id ) );
wc_get_order( $order->get_id() )->delete( true );
echo "hotovo\n";
