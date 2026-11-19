<?php
/**
 * Spuštění: php wp-content/plugins/museum-of-bricks-tickets/tests/test-failed-payment.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'OBJECT', 'OBJECT' );

$GLOBALS['scheduled'] = array();
$GLOBALS['sent']      = array();
$GLOBALS['orders']    = array();

class WC_Order {
	public $id; public $status; public $meta; public $paid = false; public $method = 'sam_stripe'; public $notes = array(); public $created_ago = 600;
	public function __construct( $id, $status, $meta ) { $this->id = $id; $this->status = $status; $this->meta = $meta; }
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_status() { return $this->status; }
	public function is_paid() { return $this->paid; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() {}
	public function get_billing_email() { return 'zakaznik@example.com'; }
	public function get_billing_first_name() { return 'Jana'; }
	public function get_payment_method() { return $this->method; }
	public function get_date_created() { return new DateTimeImmutable( '@' . ( time() - $this->created_ago ) ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function update_status( $status, $note = '' ) { $this->status = $status; $this->notes[] = $note; }
}
class Mailer {
	public function wrap_message( $heading, $body ) { return '<h1>' . $heading . '</h1>' . $body; }
	public function send( $to, $subject, $body, $headers ) { $GLOBALS['sent'][] = array( $to, $subject, $body ); return true; }
}
function WC() { return new class() { public function mailer() { return new Mailer(); } }; }
function wc_get_order( $id ) { return isset( $GLOBALS['orders'][ $id ] ) ? $GLOBALS['orders'][ $id ] : false; }
function wp_next_scheduled( $hook, $args = array() ) { return isset( $GLOBALS['scheduled'][ $hook . json_encode( $args ) ] ); }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['scheduled'][ $hook . json_encode( $args ) ] = $time; }
function absint( $v ) { return abs( (int) $v ); }
function is_email( $v ) { return false !== strpos( $v, '@' ); }
function current_time( $t ) { return '2026-11-03 15:00:00'; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ); }
function esc_html( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
function esc_url( $v ) { return $v; }
function get_page_by_path() { return null; }
function apply_filters( $hook, $value ) { return $value; }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function home_url( $p ) { return 'https://example.test' . $p; }

require_once dirname( __DIR__ ) . '/includes/class-sam-tickets-failed-payment.php';

function check( $label, $condition ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $label\n" );
		exit( 1 );
	}
}

$handler = new SAM_Tickets_Failed_Payment();

// Nevstupenková objednávka: nic.
$GLOBALS['orders'][1] = new WC_Order( 1, 'failed', array() );
$handler->schedule_email( 1 );
check( 'non-ticket order not scheduled', ! $GLOBALS['scheduled'] );

// Vstupenková objednávka: naplánuje se jednou, odejde jednou.
$GLOBALS['orders'][2] = new WC_Order( 2, 'failed', array( '_sam_language' => 'cs' ) );
$handler->schedule_email( 2 );
$handler->schedule_email( 2 );
check( 'ticket order scheduled once', 1 === count( $GLOBALS['scheduled'] ) );
$handler->send_email( 2 );
$handler->send_email( 2 );
check( 'email sent exactly once', 1 === count( $GLOBALS['sent'] ) );
check( 'czech subject', 'Platba neproběhla – objednávka #2' === $GLOBALS['sent'][0][1] );
check( 'buy again link', false !== strpos( $GLOBALS['sent'][0][2], 'https://example.test/#vstupenky' ) );

// Zákazník mezitím zaplatil: e-mail neodejde.
$GLOBALS['orders'][3] = new WC_Order( 3, 'processing', array( '_sam_language' => 'cs' ) );
$GLOBALS['orders'][3]->paid = true;
$handler->send_email( 3 );
check( 'paid order gets no email', 1 === count( $GLOBALS['sent'] ) );

// Anglická objednávka.
$GLOBALS['orders'][4] = new WC_Order( 4, 'failed', array( '_sam_language' => 'en' ) );
$handler->send_email( 4 );
check( 'english subject', 'Payment not completed – order #4' === $GLOBALS['sent'][1][1] );

// WooCommerce chce zrušit nezaplacenou objednávku: místo toho „failed“.
$GLOBALS['orders'][5] = new WC_Order( 5, 'pending', array( '_sam_language' => 'cs' ) );
check( 'cancel prevented', false === $handler->fail_instead_of_cancel( true, $GLOBALS['orders'][5] ) );
check( 'marked failed', 'failed' === $GLOBALS['orders'][5]->status );
$other = new WC_Order( 6, 'pending', array() );
check( 'other orders still cancelled', true === $handler->fail_instead_of_cancel( true, $other ) );

// Stará objednávka (např. z doby před nasazením): nic se neplánuje ani neposílá.
$GLOBALS['orders'][7] = new WC_Order( 7, 'failed', array( '_sam_language' => 'cs' ) );
$GLOBALS['orders'][7]->created_ago = 3 * DAY_IN_SECONDS;
$scheduled_before = count( $GLOBALS['scheduled'] );
$handler->schedule_email( 7 );
$handler->send_email( 7 );
check( 'old order not scheduled', $scheduled_before === count( $GLOBALS['scheduled'] ) );
check( 'old order gets no email', 2 === count( $GLOBALS['sent'] ) );

// Core e-mail WooCommerce pro vstupenky vypnutý.
check( 'core failed email disabled', false === $handler->disable_core_customer_failed_email( true, $GLOBALS['orders'][2] ) );

echo "Failed payment tests passed.\n";
