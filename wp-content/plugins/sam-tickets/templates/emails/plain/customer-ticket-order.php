<?php
/**
 * E-mail se vstupenkami – textová verze.
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
	include trailingslashit( WC()->plugin_path() ) . 'templates/emails/plain/customer-processing-order.php';
	return;
}

$copy        = SAM_Tickets_Documents::customer_email_copy( $order );
$first_name  = trim( (string) $order->get_billing_first_name() );
$name_part   = $first_name ? ' ' . $first_name : '';
$valid_until = $ticket ? get_date_from_gmt( $ticket->valid_until, 'j. n. Y' ) : '';

echo '= ' . wp_strip_all_tags( $email_heading ) . " =\n\n";
echo wp_strip_all_tags( sprintf( $copy['greeting'], $name_part ) ) . "\n\n";
echo wp_strip_all_tags( $copy['intro'] ) . "\n\n";
if ( $valid_until ) {
	echo wp_strip_all_tags( sprintf( $copy['validity'], $valid_until ) ) . "\n\n";
}
echo wp_strip_all_tags( $copy['delivery'] ) . "\n\n";
echo wp_strip_all_tags( $copy['summary'] ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

echo "\n" . wp_strip_all_tags( $copy['closing'] ) . "\n";
