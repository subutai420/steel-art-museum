<?php
/**
 * Pokladna Steel Art Museum: jeden sloupec – souhrn, e-mail (+ faktura), platba.
 * Pole a logika: mu-plugins/sam-core/checkout.php.
 *
 * Vychází z WooCommerce templates/checkout/form-checkout.php
 *
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

$sam_en = function_exists( 'sam_lang' ) && 'en' === sam_lang();
$sam_t  = $sam_en
	? array( 'title' => 'Checkout', 'order' => 'Your tickets', 'contact' => 'Where to send the tickets', 'pay' => 'Payment' )
	: array( 'title' => 'Pokladna', 'order' => 'Vaše vstupenky', 'contact' => 'Kam poslat vstupenky', 'pay' => 'Platba' );

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}
?>
<form name="checkout" method="post" class="checkout woocommerce-checkout sam-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr( $sam_t['title'] ); ?>">

	<h1 class="display sam-co-title"><?php echo esc_html( $sam_t['title'] ); ?></h1>

	<section class="sam-co-step sam-co-summary">
		<h2><?php echo esc_html( $sam_t['order'] ); ?></h2>
		<?php woocommerce_order_review(); ?>
	</section>

	<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>
	<section class="sam-co-step sam-co-contact" id="customer_details">
		<h2><?php echo esc_html( $sam_t['contact'] ); ?></h2>
		<div class="woocommerce-billing-fields__field-wrapper">
			<?php
			foreach ( $checkout->get_checkout_fields( 'billing' ) as $key => $field ) {
				woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
			}
			?>
		</div>
	</section>
	<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

	<section class="sam-co-step sam-co-pay">
		<h2><?php echo esc_html( $sam_t['pay'] ); ?></h2>
		<div id="order_review" class="woocommerce-checkout-review-order">
			<?php woocommerce_checkout_payment(); ?>
		</div>
	</section>

</form>
<?php
do_action( 'woocommerce_after_checkout_form', $checkout );
