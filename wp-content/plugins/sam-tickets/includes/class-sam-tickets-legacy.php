<?php

defined( 'ABSPATH' ) || exit;

/**
 * Převod dosud platných vstupenek ze starého pluginu „Event Tickets with Ticket
 * Scanner“ (tabulka {prefix}saso_eventtickets_codes).
 *
 * Starý systém má jeden kód na osobu. Nový má jednu vstupenku na objednávku
 * s položkami a počty kusů. Import proto pro každou objednávku vytvoří jednu
 * vstupenku (zdroj „legacy“), spočítá zakoupené a uplatněné kusy po řádcích
 * objednávky a všechny staré kódy objednávky uloží do sam_ticket_legacy_codes,
 * aby skener našel vstupenku podle kteréhokoli z nich.
 *
 * Platnost starý systém neukládal, byla jen textem v šabloně PDF:
 * - objednávky do 28450: platnost do 30. 6. 2026 a dřív (dnes propadlé, nepřevádí se),
 * - objednávky od 28451, vytvořené před 29. 10. 2026 10:07: do 31. 12. 2026,
 * - vytvořené později: do 30. 6. 2027 (změna šablony podle historie nastavení pluginu).
 *
 * Import je opakovatelný: objednávky, které už vstupenku mají, přeskočí.
 * Spouští se z administrace (Vstupenky → Nastavení a reporty) nebo
 * příkazem `wp sam-tickets import-legacy`.
 */
final class SAM_Tickets_Legacy {
	const FIRST_VALID_ORDER  = 28451;
	const TEMPLATE_CHANGE_AT = '2026-10-29 10:07:15'; // místní čas
	const VALID_BEFORE       = '2026-12-31';
	const VALID_AFTER        = '2027-06-30';

	private $repository;
	private $wpdb;
	private $codes_table;

	public function __construct( SAM_Tickets_Repository $repository ) {
		global $wpdb;
		$this->repository  = $repository;
		$this->wpdb        = $wpdb;
		$this->codes_table = $wpdb->prefix . 'saso_eventtickets_codes';
	}

	public function register_hooks() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sam-tickets import-legacy', array( $this, 'cli_import' ) );
		}
	}

	public function available() {
		return (bool) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->codes_table ) );
	}

	public function status() {
		return array(
			'available' => $this->available(),
			'tickets'   => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}sam_tickets WHERE source = 'legacy'" ),
			'codes'     => $this->repository->count_legacy_codes(),
		);
	}

	/** Platnost převáděné vstupenky podle data objednávky (Y-m-d) nebo null = propadlá. */
	public static function valid_until_for( $order_id, $created_local ) {
		if ( absint( $order_id ) < self::FIRST_VALID_ORDER ) {
			return null;
		}
		return strcmp( (string) $created_local, self::TEMPLATE_CHANGE_AT ) < 0 ? self::VALID_BEFORE : self::VALID_AFTER;
	}

	/**
	 * @return array{tickets:int,codes:int,skipped:int,expired:int}|WP_Error
	 */
	public function import() {
		if ( ! $this->available() ) {
			return new WP_Error( 'sam_legacy_missing', 'Tabulka starého pluginu v databázi není.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		// Objednávky, na kterých zbývá aspoň jeden aktivní neuplatněný kód.
		$order_ids = array_map(
			'absint',
			$this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT DISTINCT order_id FROM {$this->codes_table} WHERE aktiv = 1 AND redeemed = 0 AND order_id >= %d ORDER BY order_id",
					self::FIRST_VALID_ORDER
				)
			)
		);

		$result = array( 'tickets' => 0, 'codes' => 0, 'skipped' => 0, 'expired' => 0 );
		foreach ( $order_ids as $order_id ) {
			if ( $this->repository->find_by_order_id( $order_id ) ) {
				$result['skipped']++;
				continue;
			}
			$imported = $this->import_order( $order_id );
			if ( is_wp_error( $imported ) ) {
				if ( 'sam_legacy_expired' === $imported->get_error_code() ) {
					$result['expired']++;
				}
				continue;
			}
			$result['tickets']++;
			$result['codes'] += $imported;
		}
		return $result;
	}

	/** @return int|WP_Error Počet převedených kódů. */
	private function import_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'sam_legacy_no_order', 'Objednávka neexistuje.' );
		}

		$created     = $order->get_date_created();
		$created_loc = $created ? $created->date_i18n( 'Y-m-d H:i:s' ) : '';
		$valid_date  = self::valid_until_for( $order_id, $created_loc );
		if ( ! $valid_date ) {
			return new WP_Error( 'sam_legacy_expired', 'Platnost už skončila.' );
		}
		$valid_until = SAM_Tickets_Validity::valid_until_gmt( $valid_date );
		if ( $valid_until < current_time( 'mysql', true ) ) {
			return new WP_Error( 'sam_legacy_expired', 'Platnost už skončila.' );
		}

		// Aktivní kódy + uplatněné (kvůli počtům a historii). Neaktivní neuplatněné
		// (zrušené ve starém systému) se nepřevádí.
		$codes = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT code, aktiv, redeemed, meta FROM {$this->codes_table} WHERE order_id = %d AND (aktiv = 1 OR redeemed = 1)", $order_id )
		);
		if ( ! $codes ) {
			return new WP_Error( 'sam_legacy_no_codes', 'Objednávka nemá převoditelné kódy.' );
		}

		$lines = array(); // order_item_id => data
		foreach ( $codes as $code ) {
			$meta          = json_decode( (string) $code->meta, true );
			$order_item_id = absint( $meta['woocommerce']['item_id'] ?? 0 );
			$product_id    = absint( $meta['woocommerce']['product_id'] ?? 0 );
			$key           = $order_item_id ? $order_item_id : 'p' . $product_id;
			if ( ! isset( $lines[ $key ] ) ) {
				$lines[ $key ] = array( 'order_item_id' => $order_item_id, 'product_id' => $product_id, 'purchased' => 0, 'redeemed' => 0, 'codes' => array(), 'redeemed_dates' => array() );
			}
			$lines[ $key ]['purchased']++;
			if ( (int) $code->redeemed ) {
				$lines[ $key ]['redeemed']++;
				$date = (string) ( $meta['wc_ticket']['redeemed_date'] ?? '' );
				$lines[ $key ]['redeemed_dates'][] = $date;
			}
			$lines[ $key ]['codes'][] = strtoupper( (string) $code->code );
		}

		$items = array();
		foreach ( $lines as $line ) {
			$order_item = $line['order_item_id'] ? $order->get_item( $line['order_item_id'] ) : null;
			$label      = $order_item ? $order_item->get_name() : get_the_title( $line['product_id'] );
			$quantity   = $order_item ? max( 1, (int) $order_item->get_quantity() ) : 1;
			$unit_price = $order_item ? round( ( (float) $order_item->get_total() + (float) $order_item->get_total_tax() ) / $quantity, 2 ) : 0;
			$items[]    = array(
				'order_item_id'      => $line['order_item_id'],
				'product_id'         => $line['product_id'],
				'label'              => sanitize_text_field( $label ? $label : 'Vstupenka' ),
				'unit_type'          => SAM_Tickets_Service::unit_type( $line['product_id'], $label ),
				'unit_price'         => $unit_price,
				'purchased_quantity' => $line['purchased'],
				'redeemed_quantity'  => $line['redeemed'],
			);
		}

		$redeemed_total  = array_sum( array_column( $lines, 'redeemed' ) );
		$purchased_total = array_sum( array_column( $lines, 'purchased' ) );
		$now             = current_time( 'mysql', true );
		$paid            = $order->get_date_paid() ? $order->get_date_paid() : $created;
		$language        = 'EUR' === $order->get_currency() ? 'en' : 'cs';
		$wpml_language   = (string) $order->get_meta( 'wpml_language', true );
		if ( $wpml_language ) {
			$language = 0 === strpos( strtolower( $wpml_language ), 'en' ) ? 'en' : 'cs';
		}

		$ticket_id = $this->repository->create(
			array(
				'order_id'       => $order_id,
				'token'          => bin2hex( random_bytes( 24 ) ),
				'customer_email' => sanitize_email( $order->get_billing_email() ),
				'language'       => $language,
				'currency'       => strtoupper( (string) $order->get_currency() ),
				'source'         => 'legacy',
				'status'         => $redeemed_total >= $purchased_total ? 'redeemed' : ( $redeemed_total > 0 ? 'partially_redeemed' : 'valid' ),
				'valid_from'     => $paid ? gmdate( 'Y-m-d H:i:s', $paid->getTimestamp() ) : $now,
				'valid_until'    => $valid_until,
				'created_by'     => 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			$items
		);
		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		$count = 0;
		foreach ( $lines as $line ) {
			foreach ( $line['codes'] as $code ) {
				if ( $this->repository->add_legacy_code( $code, $ticket_id, $order_id, $line['product_id'] ) ) {
					$count++;
				}
			}
		}
		$this->repository->add_event(
			$ticket_id,
			'legacy_imported',
			array(
				'codes'       => $count,
				'redeemed'    => $redeemed_total,
				'purchased'   => $purchased_total,
				'valid_until' => $valid_date,
			)
		);

		$order->update_meta_data( '_sam_ticket_id', $ticket_id );
		$order->update_meta_data( '_sam_ticket_source', 'legacy' );
		$order->save_meta_data();
		$order->add_order_note( sprintf( 'Vstupenka převedena ze starého systému jako #%d (%d kódů, uplatněno %d z %d, platnost do %s). Staré QR kódy dál fungují.', $ticket_id, $count, $redeemed_total, $purchased_total, wp_date( 'j. n. Y', strtotime( $valid_date ) ) ) );

		return $count;
	}

	/**
	 * Převede dosud platné vstupenky ze starého pluginu.
	 */
	public function cli_import() {
		$result = $this->import();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Převedeno %d objednávek (%d kódů), přeskočeno %d už převedených, %d propadlých.', $result['tickets'], $result['codes'], $result['skipped'], $result['expired'] ) );
	}
}
