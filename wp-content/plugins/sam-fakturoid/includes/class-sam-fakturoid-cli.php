<?php

defined( 'ABSPATH' ) || exit;

/**
 * wp sam-fakturoid check              – ověří přihlášení do Fakturoidu (jen čtení)
 * wp sam-fakturoid invoice <order_id> – vystaví fakturu k objednávce (když ještě není)
 * wp sam-fakturoid status <order_id>  – co objednávka ve Fakturoidu má
 * wp sam-fakturoid preview <order_id> – ukáže data faktury, nic neodesílá
 */
class SAM_Fakturoid_CLI {
	public function check() {
		$account = ( new SAM_Fakturoid_Client() )->get( 'account.json' );
		if ( is_wp_error( $account ) ) {
			WP_CLI::error( $account->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Účet %s (%s), plátce DPH: %s', $account['subdomain'] ?? '?', $account['name'] ?? '?', ! empty( $account['vat_mode'] ) ? $account['vat_mode'] : '?' ) );
	}

	public function invoice( $args ) {
		$result = SAM_Fakturoid_Service::instance()->create_invoice( absint( $args[0] ), count( SAM_Fakturoid_Service::RETRY_MINUTES ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( null === $result ) {
			$order = wc_get_order( absint( $args[0] ) );
			WP_CLI::warning( 'Nic se nevystavilo: ' . ( $order ? SAM_Fakturoid_Service::instance()->skip_reason( $order ) : 'objednávka neexistuje' ) );
			return;
		}
		WP_CLI::success( 'Faktura ' . ( $result['number'] ?? $result['id'] ) );
	}

	public function status( $args ) {
		$order = wc_get_order( absint( $args[0] ) );
		if ( ! $order ) {
			WP_CLI::error( 'Objednávka neexistuje.' );
		}
		foreach ( array( '_sam_invoice_requested', SAM_Fakturoid_Service::META_NUMBER, SAM_Fakturoid_Service::META_URL, SAM_Fakturoid_Service::META_ERROR, SAM_Fakturoid_Service::META_LINK_SENT, 'fakturoid_invoice_main_id' ) as $key ) {
			WP_CLI::line( $key . ': ' . $order->get_meta( $key ) );
		}
		WP_CLI::line( 'důvod bez faktury: ' . SAM_Fakturoid_Service::instance()->skip_reason( $order ) );
	}

	public function preview( $args ) {
		$order = wc_get_order( absint( $args[0] ) );
		if ( ! $order ) {
			WP_CLI::error( 'Objednávka neexistuje.' );
		}
		$data = SAM_Fakturoid_Service::instance()->order_data( $order, 0 );
		WP_CLI::line( wp_json_encode( SAM_Fakturoid_Builder::invoice( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}
}
