<?php
/**
 * Plugin Name: Steel Art Museum – Fakturoid
 * Description: Faktura ve Fakturoidu ke každé zaplacené objednávce (API v3), opravný doklad při refundaci. Odkaz na fakturu dostane zákazník jen při „Chci fakturu“.
 * Version: 0.1.0
 * Author: Steel Art Museum
 * Requires at least: 6.8
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'SAM_FAKTUROID_VERSION', '0.1.0' );
define( 'SAM_FAKTUROID_FILE', __FILE__ );
define( 'SAM_FAKTUROID_DIR', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SAM_FAKTUROID_FILE, true );
		}
	}
);

require_once SAM_FAKTUROID_DIR . 'includes/class-sam-fakturoid-config.php';
require_once SAM_FAKTUROID_DIR . 'includes/class-sam-fakturoid-client.php';
require_once SAM_FAKTUROID_DIR . 'includes/class-sam-fakturoid-builder.php';

add_action(
	'plugins_loaded',
	static function() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		require_once SAM_FAKTUROID_DIR . 'includes/class-sam-fakturoid-service.php';
		SAM_Fakturoid_Service::instance()->init();
		require_once SAM_FAKTUROID_DIR . 'includes/class-sam-fakturoid-watchdog.php';
		SAM_Fakturoid_Watchdog::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SAM_FAKTUROID_DIR . 'includes/class-sam-fakturoid-cli.php';
			WP_CLI::add_command( 'sam-fakturoid', 'SAM_Fakturoid_CLI' );
		}
	},
	20
);
