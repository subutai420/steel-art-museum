<?php

defined( 'ABSPATH' ) || exit;

/**
 * wp sam-fakturoid check              – ověří přihlášení do Fakturoidu (jen čtení)
 * wp sam-fakturoid invoice <order_id> – vystaví fakturu k objednávce (když ještě není)
 * wp sam-fakturoid status <order_id>  – co objednávka ve Fakturoidu má
 * wp sam-fakturoid preview <order_id> – ukáže data faktury, nic neodesílá
 * wp sam-fakturoid audit [--since=2026-01-01] [--send] – objednávky bez faktury / refundace bez opravného dokladu
 */
class SAM_Fakturoid_CLI {
	public function check() {
		$account = ( new SAM_Fakturoid_Client() )->get( 'account.json' );
		if ( is_wp_error( $account ) ) {
			WP_CLI::error( $account->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Účet %s (%s), plátce DPH: %s', $account['subdomain'] ?? '?', $account['name'] ?? '?', ! empty( $account['vat_mode'] ) ? $account['vat_mode'] : '?' ) );
	}

	/**
	 * Které zaplacené objednávky nemají fakturu a refundace opravný doklad.
	 *
	 * [--since=<datum>]
	 * : Od kdy (např. 2026-01-01). Výchozí: posledních 60 dní, nejdřív od spuštění nového webu.
	 *
	 * [--send]
	 * : Naplánovat nové pokusy a poslat upozornění (jinak jen výpis).
	 *
	 * @param array $args       Argumenty.
	 * @param array $assoc_args Volby.
	 */
	public function audit( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['send'] ) ) {
			$found = SAM_Fakturoid_Watchdog::run();
		} else {
			$found = SAM_Fakturoid_Watchdog::find( isset( $assoc_args['since'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $assoc_args['since'] ) ) : '' );
		}
		foreach ( $found['invoices'] as $o ) {
			$paid = $o->get_date_paid();
			WP_CLI::line( sprintf( 'bez faktury: #%d  %s  %s %s  %s', $o->get_id(), $paid ? $paid->date( 'Y-m-d H:i' ) : '-', $o->get_total(), $o->get_currency(), $o->get_payment_method() ) );
		}
		foreach ( $found['corrections'] as $r ) {
			WP_CLI::line( sprintf( 'bez opravného dokladu: refundace #%d k objednávce #%d  %s', $r->get_id(), $r->get_parent_id(), $r->get_amount() ) );
		}
		WP_CLI::success( sprintf( 'Bez faktury %d, bez opravného dokladu %d.', count( $found['invoices'] ), count( $found['corrections'] ) ) );
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
