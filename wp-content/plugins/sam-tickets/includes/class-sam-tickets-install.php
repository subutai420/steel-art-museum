<?php

defined( 'ABSPATH' ) || exit;

final class SAM_Tickets_Install {
	const DB_VERSION = '1';

	public static function activate() {
		self::create_tables();
		self::create_roles();
		update_option( 'sam_tickets_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'sam_tickets_db_version' ) ) {
			self::activate();
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tickets         = $wpdb->prefix . 'sam_tickets';
		$items           = $wpdb->prefix . 'sam_ticket_items';
		$redemptions     = $wpdb->prefix . 'sam_ticket_redemptions';
		$events          = $wpdb->prefix . 'sam_ticket_events';
		$legacy          = $wpdb->prefix . 'sam_ticket_legacy_codes';

		dbDelta(
			"CREATE TABLE {$tickets} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned DEFAULT NULL,
				token char(48) NOT NULL,
				customer_email varchar(190) NOT NULL DEFAULT '',
				language varchar(10) NOT NULL DEFAULT 'cs',
				currency char(3) NOT NULL DEFAULT 'CZK',
				source varchar(32) NOT NULL DEFAULT 'woocommerce',
				status varchar(20) NOT NULL DEFAULT 'valid',
				valid_from datetime NOT NULL,
				valid_until datetime NOT NULL,
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				UNIQUE KEY order_id (order_id),
				KEY status_valid (status,valid_until),
				KEY valid_until (valid_until)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				order_item_id bigint(20) unsigned DEFAULT NULL,
				product_id bigint(20) unsigned DEFAULT NULL,
				label varchar(190) NOT NULL,
				unit_type varchar(20) NOT NULL DEFAULT 'single',
				unit_price decimal(12,2) NOT NULL DEFAULT 0,
				purchased_quantity int(10) unsigned NOT NULL DEFAULT 0,
				redeemed_quantity int(10) unsigned NOT NULL DEFAULT 0,
				refunded_quantity int(10) unsigned NOT NULL DEFAULT 0,
				refund_pending_quantity int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY ticket_id (ticket_id),
				KEY product_id (product_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$redemptions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				item_id bigint(20) unsigned NOT NULL,
				quantity int(10) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				redeemed_at datetime NOT NULL,
				note varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY ticket_id (ticket_id),
				KEY item_id (item_id),
				KEY redeemed_at (redeemed_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ticket_id bigint(20) unsigned NOT NULL,
				event_type varchar(32) NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				event_key varchar(190) DEFAULT NULL,
				context longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_key),
				KEY ticket_id (ticket_id),
				KEY event_date (event_type,created_at)
			) {$charset_collate};"
		);

		// Kódy ze starého pluginu (Event Tickets with Ticket Scanner). Jeden starý kód
		// = jedna osoba; všechny kódy objednávky vedou na jednu novou vstupenku.
		dbDelta(
			"CREATE TABLE {$legacy} (
				code varchar(64) NOT NULL,
				ticket_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned DEFAULT NULL,
				imported_at datetime NOT NULL,
				PRIMARY KEY  (code),
				KEY ticket_id (ticket_id),
				KEY order_id (order_id)
			) {$charset_collate};"
		);
	}

	private static function create_roles() {
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );

		foreach ( array( 'sam_scan_tickets', 'sam_manage_tickets' ) as $capability ) {
			if ( $administrator ) {
				$administrator->add_cap( $capability );
			}
			if ( $shop_manager ) {
				$shop_manager->add_cap( $capability );
			}
		}

		add_role(
			'sam_cashier',
			'Pokladní',
			array(
				'read'             => true,
				'sam_scan_tickets' => true,
			)
		);
	}
}
