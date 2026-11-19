<?php
/**
 * Plugin Name: Steel Art Museum – Stripe
 * Description: Platby kartou, Apple Pay a Google Pay přes Stripe (Payment Element), potvrzení webhookem a refundace napojené na vstupenky. Refundace umí i objednávky zaplacené starým pluginem Stripe.
 * Version: 0.1.0
 * Author: Steel Art Museum
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Text Domain: sam-stripe
 */

defined( 'ABSPATH' ) || exit;

define( 'SAM_STRIPE_VERSION', '0.1.0' );
define( 'SAM_STRIPE_FILE', __FILE__ );
define( 'SAM_STRIPE_DIR', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SAM_STRIPE_FILE, true );
		}
	}
);

require_once SAM_STRIPE_DIR . 'includes/class-sam-stripe-config.php';
require_once SAM_STRIPE_DIR . 'includes/class-sam-stripe-client.php';
require_once SAM_STRIPE_DIR . 'includes/class-sam-stripe-webhook.php';

add_action(
	'plugins_loaded',
	static function() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		require_once SAM_STRIPE_DIR . 'includes/class-sam-stripe-refunds.php';
		require_once SAM_STRIPE_DIR . 'includes/class-sam-stripe-gateway.php';
		require_once SAM_STRIPE_DIR . 'includes/class-sam-stripe-legacy-gateway.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function( $gateways ) {
				$gateways[] = 'SAM_Stripe_Gateway';
				foreach ( SAM_Stripe_Legacy_Gateway::legacy_ids() as $id ) {
					$gateways[] = new SAM_Stripe_Legacy_Gateway( $id );
				}
				return $gateways;
			}
		);

		// Vstupenky: refundace těchto objednávek musí jít přes bránu (vrací peníze).
		add_filter(
			'sam_tickets_online_gateways',
			static function( $gateways ) {
				return array_values( array_unique( array_merge( (array) $gateways, array( SAM_Stripe_Gateway::ID ), SAM_Stripe_Legacy_Gateway::legacy_ids() ) ) );
			}
		);

		( new SAM_Stripe_Webhook() )->register_hooks();
		SAM_Stripe_Gateway::register_static_hooks();
	},
	20
);
