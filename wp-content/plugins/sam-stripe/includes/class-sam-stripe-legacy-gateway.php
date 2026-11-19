<?php

defined( 'ABSPATH' ) || exit;

/**
 * Objednávky zaplacené starým pluginem „Payment Plugins for Stripe“ (stripe_cc,
 * stripe_googlepay…). Po jeho vypnutí by u nich WooCommerce neuměl refundovat
 * přes bránu. Tato třída převezme jejich ID platebních metod jen pro refundace:
 * na pokladně se nikdy nezobrazí a nemá vlastní nastavení (nečte ani nepřepisuje
 * volby starého pluginu).
 *
 * Refunduje se přes ID platby v objednávce (u starých objednávek ch_…).
 * Platí jen pro účet Stripe, ke kterému patří klíče v SAM_STRIPE_SECRET_KEY.
 */
final class SAM_Stripe_Legacy_Gateway extends WC_Payment_Gateway {

	public static function legacy_ids() {
		// Bez kolize se starým pluginem, dokud je aktivní (na ostrém webu do přechodu).
		if ( class_exists( 'WC_Stripe_Manager' ) || defined( 'WC_STRIPE_PLUGIN_FILE_PATH' ) ) {
			return array();
		}
		return array( 'stripe_cc', 'stripe_googlepay', 'stripe_applepay', 'stripe_payment_request', 'stripe_link_checkout' );
	}

	public function __construct( $id = 'stripe_cc' ) {
		$this->id                 = sanitize_key( $id );
		$this->method_title       = 'Stripe – starý plugin (' . $this->id . ', jen refundace)';
		$this->method_description = 'Pouze pro refundace objednávek zaplacených dřívějším pluginem Stripe. Na pokladně se nezobrazuje.';
		$this->title              = 'Stripe';
		$this->has_fields         = false;
		$this->supports           = array( 'refunds' );
		$this->enabled            = 'no';
		$this->settings           = array( 'enabled' => 'no' );
	}

	public function init_settings() {
		$this->settings = array( 'enabled' => 'no' );
	}

	public function process_admin_options() {
		return false;
	}

	public function admin_options() {
		echo '<p>' . esc_html( $this->method_description ) . '</p>';
	}

	public function is_available() {
		return false;
	}

	public function can_refund_order( $order ) {
		return $order instanceof WC_Order && $order->get_transaction_id() && SAM_Stripe_Config::is_configured();
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return ( new SAM_Stripe_Refunds() )->process( $this, $order_id, $amount, $reason );
	}
}
