<?php
// End-to-end test napojení na Fakturoid s NAPODOBENÝM API (nic se neposílá do skutečného účtu).
// wp eval-file /import/fakturoid.php --user=<admin> --skip-themes

putenv( 'SAM_FAKTUROID_ACCOUNT=test-ucet' );
putenv( 'SAM_FAKTUROID_CLIENT_ID=test-id' );
putenv( 'SAM_FAKTUROID_CLIENT_SECRET=test-secret' );

$GLOBALS['mails'] = array();
add_filter( 'pre_wp_mail', function( $r, $a ) { $GLOBALS['mails'][] = $a; return true; }, 0, 2 );

/* ---- napodobený Fakturoid ---- */
$GLOBALS['fk']      = array( 'calls' => array(), 'docs' => array(), 'subjects' => array(), 'fail_invoice' => false, 'next' => 9000 );
add_filter( 'pre_http_request', function( $pre, $args, $url ) {
	if ( false === strpos( $url, 'app.fakturoid.cz' ) ) {
		return $pre;
	}
	$fk   = &$GLOBALS['fk'];
	$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
	$path = preg_replace( '#^https://app\.fakturoid\.cz/api/v3/(accounts/[^/]+/)?#', '', strtok( $url, '?' ) );
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	$fk['calls'][] = array( $args['method'] ?? 'GET', $path, $body, $query );
	$json = function( $code, $data ) { return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => wp_json_encode( $data ), 'headers' => array(), 'cookies' => array() ); };

	if ( 'oauth/token' === $path ) {
		return $json( 200, array( 'access_token' => 'tok', 'expires_in' => 7200 ) );
	}
	if ( 'invoices.json' === $path && 'GET' === $args['method'] ) {
		return $json( 200, array_values( array_filter( $fk['docs'], function( $d ) use ( $query ) { return ( $query['custom_id'] ?? '' ) === $d['custom_id']; } ) ) );
	}
	if ( 'invoices.json' === $path ) {
		if ( $fk['fail_invoice'] ) {
			return $json( 422, array( 'errors' => array( 'lines' => array( 'nejde' ) ) ) );
		}
		$id    = ++$fk['next'];
		$total = 0;
		foreach ( $body['lines'] as $l ) { $total += round( $l['unit_price'] * 100 ) * $l['quantity']; }
		$doc = array_merge( $body, array( 'id' => $id, 'number' => '2026-T' . $id, 'total' => $total / 100, 'public_html_url' => 'https://app.fakturoid.cz/test-ucet/p/x' . $id, 'html_url' => 'https://app.fakturoid.cz/test-ucet/invoices/' . $id ) );
		$fk['docs'][ $id ] = $doc;
		return $json( 201, $doc );
	}
	if ( preg_match( '#^invoices/(\d+)/payments\.json$#', $path, $m ) ) {
		$fk['docs'][ $m[1] ]['paid'] = $body;
		return $json( 201, array( 'id' => 1 ) );
	}
	if ( preg_match( '#^invoices/(\d+)\.json$#', $path, $m ) ) {
		return isset( $fk['docs'][ $m[1] ] ) ? $json( 200, $fk['docs'][ $m[1] ] ) : $json( 200, array( 'id' => (int) $m[1], 'subject_id' => 4242 ) );
	}
	if ( 'subjects.json' === $path && 'GET' === $args['method'] ) {
		return $json( 200, array_values( array_filter( $fk['subjects'], function( $s ) use ( $query ) { return isset( $s['custom_id'] ) && ( $query['custom_id'] ?? '' ) === $s['custom_id']; } ) ) );
	}
	if ( 'subjects/search.json' === $path ) {
		return $json( 200, array_values( array_filter( $fk['subjects'], function( $s ) use ( $query ) { return ( $s['registration_no'] ?? '' ) === ( $query['query'] ?? '-' ); } ) ) );
	}
	if ( 'subjects.json' === $path ) {
		$id = ++$fk['next'];
		$fk['subjects'][ $id ] = array_merge( $body, array( 'id' => $id ) );
		return $json( 201, $fk['subjects'][ $id ] );
	}
	return $json( 404, array( 'error' => 'neznámé ' . $path ) );
}, PHP_INT_MAX, 3 );

function ok( $label, $cond, $detail = '' ) { echo ( $cond ? 'OK   ' : 'FAIL ' ) . $label . ( '' !== $detail ? " – $detail" : '' ) . "\n"; }

$svc  = SAM_Fakturoid_Service::instance();
$svc->set_client( new SAM_Fakturoid_Client() );
$repo = new SAM_Tickets_Repository();
$GLOBALS['created'] = array();
// Zbytky z přerušeného běhu.
foreach ( wc_get_orders( array( 'billing_email' => 'test-fak@example.com', 'limit' => -1, 'return' => 'ids' ) ) as $oid ) { $GLOBALS['created'][] = $oid; }
delete_option( SAM_Fakturoid_Service::RETAIL_OPTION . '_' . md5( 'test-ucet' ) );
delete_transient( 'sam_fakturoid_token_' . md5( 'test-id' ) );

function paid_order( $lines, $invoice = false ) {
	$order = wc_create_order();
	foreach ( $lines as $pid => $qty ) { $order->add_product( wc_get_product( $pid ), $qty ); }
	$order->set_billing_email( 'test-fak@example.com' );
	$order->set_payment_method( 'sam_stripe' );
	$order->set_payment_method_title( 'Stripe – karta' );
	$order->update_meta_data( '_sam_language', 'cs' );
	$order->update_meta_data( '_sam_invoice_requested', $invoice ? 'yes' : 'no' );
	if ( $invoice ) {
		$order->set_billing_company( 'Test s.r.o.' );
		$order->set_billing_address_1( 'Celetná 15' );
		$order->set_billing_city( 'Praha' );
		$order->set_billing_postcode( '11000' );
		$order->set_billing_country( 'CZ' );
		$order->update_meta_data( '_billing_ic', '12345678' );
		$order->update_meta_data( '_billing_dic', 'CZ12345678' );
	}
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	$GLOBALS['created'][] = $order->get_id();
	$order->payment_complete( 'pi_test_' . $order->get_id() );
	return wc_get_order( $order->get_id() );
}

function last_doc() { $d = $GLOBALS['fk']['docs']; return end( $d ); }
function mail_for( $order ) { foreach ( array_reverse( $GLOBALS['mails'] ) as $m ) { if ( false !== strpos( $m['subject'], '#' . $order->get_order_number() ) || false !== strpos( $m['subject'], $order->get_order_number() ) ) { return $m; } } return null; }

echo "== 1. Chci fakturu: faktura hned, odkaz v e-mailu se vstupenkou\n";
$o1  = paid_order( array( 1115 => 2, 1125 => 1 ), true );
$doc = last_doc();
ok( 'faktura vystavena', (bool) $o1->get_meta( SAM_Fakturoid_Service::META_NUMBER ), $o1->get_meta( SAM_Fakturoid_Service::META_NUMBER ) );
ok( 'součet faktury = objednávka', $doc && abs( $doc['total'] - (float) $o1->get_total() ) < 0.01, ( $doc['total'] ?? '?' ) . ' / ' . $o1->get_total() );
ok( 'uhrazeno', ! empty( $doc['paid']['paid_on'] ) );
$subj = $GLOBALS['fk']['subjects'][ $doc['subject_id'] ] ?? array();
ok( 'odběratel = firma s IČO a DIČ', 'Test s.r.o.' === ( $subj['name'] ?? '' ) && '12345678' === ( $subj['registration_no'] ?? '' ) && 'CZ12345678' === ( $subj['vat_no'] ?? '' ) );
ok( 'DPH 12 %, ceny s DPH', 12 === $doc['lines'][0]['vat_rate'] && 'from_total_with_vat' === $doc['vat_price_mode'] );
$m = mail_for( $o1 );
ok( 'e-mail se vstupenkou obsahuje odkaz na fakturu', $m && false !== strpos( $m['message'], $o1->get_meta( SAM_Fakturoid_Service::META_URL ) ), $m ? $m['subject'] : 'bez e-mailu' );

echo "== 2. Bez faktury: na pozadí, Koncový zákazník, bez odkazu\n";
$calls = count( $GLOBALS['fk']['calls'] );
$o2    = paid_order( array( 1115 => 1 ) );
ok( 'při platbě se Fakturoid nevolá', count( $GLOBALS['fk']['calls'] ) === $calls );
ok( 'naplánováno na pozadí', (bool) wp_next_scheduled( SAM_Fakturoid_Service::CREATE_HOOK, array( $o2->get_id(), 0 ) ) );
wp_clear_scheduled_hook( SAM_Fakturoid_Service::CREATE_HOOK, array( $o2->get_id(), 0 ) );
$svc->create_invoice( $o2->get_id(), 0 );
$o2  = wc_get_order( $o2->get_id() );
$doc = last_doc();
ok( 'faktura vystavena', (bool) $o2->get_meta( SAM_Fakturoid_Service::META_NUMBER ) );
ok( 'odběratel Koncový zákazník', 'Koncový zákazník' === ( $GLOBALS['fk']['subjects'][ $doc['subject_id'] ]['name'] ?? '' ) );
$m = mail_for( $o2 );
ok( 'e-mail bez odkazu na fakturu', $m && false === strpos( $m['message'], 'fakturoid' ) );
$o2b = paid_order( array( 1125 => 1 ) );
wp_clear_scheduled_hook( SAM_Fakturoid_Service::CREATE_HOOK, array( $o2b->get_id(), 0 ) );
$before = count( $GLOBALS['fk']['subjects'] );
$svc->create_invoice( $o2b->get_id(), 0 );
ok( 'Koncový zákazník se znovu nezakládá', count( $GLOBALS['fk']['subjects'] ) === $before );

echo "== 3. Opakované spuštění nevystaví druhou fakturu\n";
$docs = count( $GLOBALS['fk']['docs'] );
$svc->create_invoice( $o1->get_id(), 0 );
$o1->delete_meta_data( SAM_Fakturoid_Service::META_ID ); $o1->save(); // jako by se odpověď ztratila
$svc->create_invoice( $o1->get_id(), 0 );
ok( 'žádná další faktura', count( $GLOBALS['fk']['docs'] ) === $docs );

echo "== 4. Refundace 1 dospělého → opravný doklad\n";
add_filter( 'sam_tickets_online_gateways', '__return_empty_array', 99 ); // ruční refundace bez Stripe (jen pro test)
$o1 = wc_get_order( $o1->get_id() );
foreach ( $o1->get_items() as $item_id => $item ) { if ( 1115 === (int) $item->get_product_id() ) { $adult = $item_id; $line = $item; } }
$refund = wc_create_refund( array(
	'order_id'   => $o1->get_id(), 'amount' => (float) $line->get_total() / 2 + (float) $line->get_total_tax() / 2, 'reason' => 'Test',
	'line_items' => array( $adult => array( 'qty' => 1, 'refund_total' => (float) $line->get_total() / 2, 'refund_tax' => array_map( function( $t ) { return (float) $t / 2; }, $line->get_taxes()['total'] ) ) ),
) );
ok( 'refundace vytvořena', ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : '' );
ok( 'opravný doklad naplánován', (bool) wp_next_scheduled( SAM_Fakturoid_Service::CORRECTION_HOOK, array( $refund->get_id(), 0 ) ) );
wp_clear_scheduled_hook( SAM_Fakturoid_Service::CORRECTION_HOOK, array( $refund->get_id(), 0 ) );
$svc->create_correction( $refund->get_id(), 0 );
$cor = last_doc();
ok( 'opravný doklad k faktuře', 'correction' === ( $cor['document_type'] ?? '' ) && (int) $o1->get_meta( SAM_Fakturoid_Service::META_ID ) === (int) $cor['correction_id'] );
ok( 'částka = refundace', abs( abs( $cor['total'] ) - (float) $refund->get_amount() ) < 0.01, $cor['total'] . ' / ' . $refund->get_amount() );
ok( 'uložen u refundace', (bool) wc_get_order( $refund->get_id() )->get_meta( SAM_Fakturoid_Service::META_NUMBER ) );

echo "== 5. Chyba API → nový pokus a odkaz zvlášť\n";
$GLOBALS['fk']['fail_invoice'] = true;
$o5 = paid_order( array( 1115 => 1 ), true );
ok( 'faktura není', ! $o5->get_meta( SAM_Fakturoid_Service::META_NUMBER ) );
ok( 'chyba uložena', (bool) $o5->get_meta( SAM_Fakturoid_Service::META_ERROR ), $o5->get_meta( SAM_Fakturoid_Service::META_ERROR ) );
ok( 'další pokus naplánován', (bool) wp_next_scheduled( SAM_Fakturoid_Service::CREATE_HOOK, array( $o5->get_id(), 1 ) ) );
$m = mail_for( $o5 );
ok( 'vstupenka odešla i bez faktury', (bool) $m );
$GLOBALS['fk']['fail_invoice'] = false;
wp_clear_scheduled_hook( SAM_Fakturoid_Service::CREATE_HOOK, array( $o5->get_id(), 1 ) );
$mails = count( $GLOBALS['mails'] );
$svc->create_invoice( $o5->get_id(), 1 );
$o5 = wc_get_order( $o5->get_id() );
ok( 'druhý pokus fakturu vystavil', (bool) $o5->get_meta( SAM_Fakturoid_Service::META_NUMBER ) );
$link = $GLOBALS['mails'][ $mails ] ?? null;
ok( 'zákazník dostal odkaz zvlášť', $link && false !== strpos( $link['message'], $o5->get_meta( SAM_Fakturoid_Service::META_URL ) ), $link ? $link['subject'] : 'nic' );

echo "== 6. Bez faktury\n";
$o6 = wc_create_order(); $o6->add_product( wc_get_product( 1115 ), 1 ); $o6->update_meta_data( '_sam_no_invoice', 'yes' ); $o6->calculate_totals(); $o6->save();
$GLOBALS['created'][] = $o6->get_id();
ok( 'ruční vstupenka', 'bez faktury (ruční vstupenka)' === $svc->skip_reason( $o6 ) );
$o7 = wc_create_order(); $o7->update_meta_data( 'fakturoid_invoice_id', '123' ); $o7->save(); $GLOBALS['created'][] = $o7->get_id();
ok( 'stará faktura', 'faktura ze starého pluginu' === $svc->skip_reason( $o7 ) );

echo "== Úklid\n";
global $wpdb;
foreach ( $GLOBALS['created'] as $oid ) {
	$t = $repo->find_by_order_id( $oid );
	if ( $t ) {
		foreach ( array( 'sam_ticket_items', 'sam_ticket_events', 'sam_ticket_redemptions' ) as $tb ) { $wpdb->delete( $wpdb->prefix . $tb, array( 'ticket_id' => $t->id ) ); }
		$wpdb->delete( $wpdb->prefix . 'sam_tickets', array( 'id' => $t->id ) );
	}
	wp_clear_scheduled_hook( SAM_Fakturoid_Service::CREATE_HOOK, array( $oid, 0 ) );
	$o = wc_get_order( $oid );
	if ( $o ) { foreach ( $o->get_refunds() as $r ) { $r->delete( true ); } $o->delete( true ); }
}
delete_option( SAM_Fakturoid_Service::RETAIL_OPTION . '_' . md5( 'test-ucet' ) );
echo 'smazáno ' . count( $GLOBALS['created'] ) . " testovacích objednávek, Fakturoid volán " . count( $GLOBALS['fk']['calls'] ) . "× (napodobený)\n";
