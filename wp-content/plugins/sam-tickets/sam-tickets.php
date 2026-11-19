<?php
/**
 * Plugin Name: Steel Art Museum – vstupenky
 * Description: Vydávání, evidence a uplatňování vstupenek Steel Art Museum nad objednávkami WooCommerce: QR a PDF, skener pro pokladní, ruční vstupenky, refundace, platnost a reporty. Vychází z pluginu vstupenek Museum of Bricks.
 * Version: 0.1.0
 * Author: Steel Art Museum
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Text Domain: sam-tickets
 */

defined( 'ABSPATH' ) || exit;

define( 'SAM_TICKETS_VERSION', '0.1.0' );
define( 'SAM_TICKETS_FILE', __FILE__ );
define( 'SAM_TICKETS_DIR', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SAM_TICKETS_FILE, true );
		}
	}
);

if ( is_readable( SAM_TICKETS_DIR . 'vendor/autoload.php' ) ) {
	require_once SAM_TICKETS_DIR . 'vendor/autoload.php';
}

require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-install.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-settings.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-repository.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-validity.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-service.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-refunds.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-documents.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-failed-payment.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-reports.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-legacy.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-admin.php';
require_once SAM_TICKETS_DIR . 'includes/class-sam-tickets-rest.php';

register_activation_hook( __FILE__, array( 'SAM_Tickets_Install', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function() {
		SAM_Tickets_Failed_Payment::unschedule();
		SAM_Tickets_Reports::unschedule();
	}
);

add_action(
	'plugins_loaded',
	static function() {
		SAM_Tickets_Install::maybe_upgrade();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function() {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>Steel Art Museum – vstupenky:</strong> plugin vyžaduje aktivní WooCommerce.</p></div>';
					}
				}
			);
			return;
		}

		$repository = new SAM_Tickets_Repository();
		$service    = new SAM_Tickets_Service( $repository );
		$documents  = new SAM_Tickets_Documents( $repository );
		$legacy     = new SAM_Tickets_Legacy( $repository );

		$service->register_hooks();
		( new SAM_Tickets_Refunds( $repository ) )->register_hooks();
		$documents->register_hooks();
		( new SAM_Tickets_Failed_Payment() )->register_hooks();
		( new SAM_Tickets_Reports( $repository ) )->register_hooks();
		( new SAM_Tickets_REST( $repository ) )->register_hooks();
		$legacy->register_hooks();

		if ( is_admin() ) {
			( new SAM_Tickets_Admin( $repository, $service, $legacy ) )->register_hooks();
		}
	}
);
