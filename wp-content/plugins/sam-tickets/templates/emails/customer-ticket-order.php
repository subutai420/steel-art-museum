<?php
/**
 * E-mail se vstupenkami (nahrazuje šablonu „Objednávka se zpracovává“).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

// Vstupenku hledáme v databázi: e-mail může dostat objekt objednávky z doby
// před jejím vydáním (meta _sam_ticket_id v něm ještě není).
$ticket = ( new SAM_Tickets_Repository() )->find_by_order_id( $order->get_id() );
if ( ! $ticket ) {
	include trailingslashit( WC()->plugin_path() ) . 'templates/emails/customer-processing-order.php';
	return;
}

$copy        = SAM_Tickets_Documents::customer_email_copy( $order );
$first_name  = trim( (string) $order->get_billing_first_name() );
$name_part   = $first_name ? ' ' . $first_name : '';
$valid_until = get_date_from_gmt( $ticket->valid_until, 'j. n. Y' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<div class="email-introduction">
	<p><?php echo esc_html( sprintf( $copy['greeting'], $name_part ) ); ?></p>
	<p><?php echo esc_html( $copy['intro'] ); ?></p>
	<?php if ( $valid_until ) : ?>
		<p><?php echo esc_html( sprintf( $copy['validity'], $valid_until ) ); ?></p>
	<?php endif; ?>
	<p><?php echo esc_html( $copy['delivery'] ); ?></p>
	<p><strong><?php echo esc_html( $copy['summary'] ); ?></strong></p>
</div>
<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
?>
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
	<tr><td class="email-additional-content"><p><?php echo esc_html( $copy['closing'] ); ?></p></td></tr>
</table>
<?php do_action( 'woocommerce_email_footer', $email ); ?>
