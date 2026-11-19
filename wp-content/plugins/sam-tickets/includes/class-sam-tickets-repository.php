<?php

defined( 'ABSPATH' ) || exit;

final class SAM_Tickets_Repository {
	private $wpdb;
	private $tickets_table;
	private $items_table;
	private $redemptions_table;
	private $events_table;
	private $legacy_table;

	public function __construct() {
		global $wpdb;
		$this->wpdb          = $wpdb;
		$this->tickets_table = $wpdb->prefix . 'sam_tickets';
		$this->items_table   = $wpdb->prefix . 'sam_ticket_items';
		$this->redemptions_table = $wpdb->prefix . 'sam_ticket_redemptions';
		$this->events_table  = $wpdb->prefix . 'sam_ticket_events';
		$this->legacy_table  = $wpdb->prefix . 'sam_ticket_legacy_codes';
	}

	public function find_by_order_id( $order_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->tickets_table} WHERE order_id = %d LIMIT 1", $order_id )
		);
	}

	public function find_by_token( $token ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->tickets_table} WHERE token = %s LIMIT 1", $token )
		);
	}

	public function find( $ticket_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->tickets_table} WHERE id = %d LIMIT 1", $ticket_id )
		);
	}

	public function get_items( $ticket_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->items_table} WHERE ticket_id = %d ORDER BY id ASC", $ticket_id )
		);
	}

	public function get_redemptions( $ticket_id, $limit = 20 ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT r.*, i.label FROM {$this->redemptions_table} r
				LEFT JOIN {$this->items_table} i ON i.id = r.item_id
				WHERE r.ticket_id = %d ORDER BY r.id DESC LIMIT %d",
				$ticket_id,
				max( 1, min( 100, absint( $limit ) ) )
			)
		);
	}

	public function get_events( $ticket_id, $event_type = '', $limit = 20 ) {
		$ticket_id  = absint( $ticket_id );
		$event_type = sanitize_key( $event_type );
		$limit      = max( 1, min( 100, absint( $limit ) ) );

		if ( $event_type ) {
			return $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->events_table}
					WHERE ticket_id = %d AND event_type = %s
					ORDER BY id DESC LIMIT %d",
					$ticket_id,
					$event_type,
					$limit
				)
			);
		}

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->events_table}
				WHERE ticket_id = %d ORDER BY id DESC LIMIT %d",
				$ticket_id,
				$limit
			)
		);
	}

	/**
	 * Redeem ticket quantities.
	 *
	 * A scanner request carries a client generated operation ID. Repeating the
	 * same operation (double click, network retry) returns the current ticket
	 * without deducting again.
	 *
	 * @param string $token           Ticket token.
	 * @param array  $requested_items Map of ticket item ID => quantity.
	 * @param int    $user_id         Redeeming WordPress user.
	 * @param string $operation_id    Idempotency key of this redemption.
	 * @return object|WP_Error Updated ticket row.
	 */
	public function redeem( $token, array $requested_items, $user_id, $operation_id = '' ) {
		$token        = sanitize_text_field( $token );
		$user_id      = absint( $user_id );
		$now          = current_time( 'mysql', true );
		$operation_id = preg_match( '/^[A-Za-z0-9-]{8,64}$/', (string) $operation_id ) ? (string) $operation_id : '';

		$this->wpdb->query( 'START TRANSACTION' );
		$ticket = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->tickets_table} WHERE token = %s LIMIT 1 FOR UPDATE", $token )
		);

		if ( ! $ticket ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_not_found', 'Vstupenka nebyla nalezena.', array( 'status' => 404 ) );
		}
		$event_key       = $operation_id ? 'redeem:' . (int) $ticket->id . ':' . $operation_id : '';
		$normalized_items = array();
		foreach ( $requested_items as $item_id => $quantity ) {
			if ( absint( $quantity ) ) {
				$normalized_items[ absint( $item_id ) ] = absint( $quantity );
			}
		}
		ksort( $normalized_items, SORT_NUMERIC );
		$request_hash = hash( 'sha256', wp_json_encode( $normalized_items ) );
		if ( $event_key ) {
			$existing = $this->find_event_by_key( $event_key, true );
			if ( $existing ) {
				$context = $this->decode_event_context( $existing );
				$this->wpdb->query( 'ROLLBACK' );
				if ( ! hash_equals( (string) ( $context['request_hash'] ?? '' ), $request_hash ) ) {
					return new WP_Error( 'sam_ticket_redeem_idempotency_conflict', 'Tento požadavek na uplatnění už byl použit s jinými položkami. Načtěte vstupenku znovu.', array( 'status' => 409 ) );
				}
				$current = $this->find( $ticket->id );
				if ( $current ) {
					$current->sam_replayed = true;
				}
				return $current;
			}
		}

		if ( $ticket->valid_until < $now ) {
			if ( in_array( $ticket->status, array( 'valid', 'partially_redeemed' ), true ) ) {
				$this->wpdb->update( $this->tickets_table, array( 'status' => 'expired', 'updated_at' => $now ), array( 'id' => $ticket->id ), array( '%s', '%s' ), array( '%d' ) );
				$this->wpdb->query( 'COMMIT' );
			} else {
				$this->wpdb->query( 'ROLLBACK' );
			}
			return new WP_Error( 'sam_ticket_expired', 'Platnost vstupenky vypršela.', array( 'status' => 409 ) );
		}
		if ( ! in_array( $ticket->status, array( 'valid', 'partially_redeemed' ), true ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_not_redeemable', 'Vstupenku už nelze uplatnit.', array( 'status' => 409, 'ticket_status' => $ticket->status ) );
		}

		$items = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->items_table} WHERE ticket_id = %d ORDER BY id ASC FOR UPDATE", $ticket->id ),
			OBJECT_K
		);
		$redemption_rows = array();

		foreach ( $requested_items as $item_id => $quantity ) {
			$item_id  = absint( $item_id );
			$quantity = absint( $quantity );
			if ( ! $quantity ) {
				continue;
			}
			if ( ! isset( $items[ $item_id ] ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_invalid_item', 'Vstupenka obsahuje neplatnou položku.', array( 'status' => 400 ) );
			}

			$item      = $items[ $item_id ];
			$remaining = $this->remaining_quantity( $item );
			if ( $quantity > $remaining ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_quantity_exceeded', sprintf( 'U položky „%s“ zbývá pouze %d ks.', $item->label, $remaining ), array( 'status' => 409 ) );
			}

			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->items_table}
					SET redeemed_quantity = redeemed_quantity + %d
					WHERE id = %d AND ticket_id = %d
					AND redeemed_quantity + refunded_quantity + refund_pending_quantity + %d <= purchased_quantity",
					$quantity,
					$item_id,
					$ticket->id,
					$quantity
				)
			);
			if ( 1 !== $updated ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_concurrent_update', 'Stav vstupenky se mezitím změnil. Načtěte ji znovu.', array( 'status' => 409 ) );
			}

			$inserted = $this->wpdb->insert(
				$this->redemptions_table,
				array(
					'ticket_id'  => $ticket->id,
					'item_id'    => $item_id,
					'quantity'   => $quantity,
					'user_id'    => $user_id,
					'redeemed_at'=> $now,
					'note'       => '',
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s' )
			);
			if ( false === $inserted ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_redemption_failed', 'Uplatnění se nepodařilo uložit.', array( 'status' => 500 ) );
			}
			$redemption_rows[] = array( 'item_id' => $item_id, 'quantity' => $quantity );
		}

		if ( ! $redemption_rows ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_empty_redemption', 'Vyberte alespoň jednu položku.', array( 'status' => 400 ) );
		}

		$remaining_total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT SUM(purchased_quantity - redeemed_quantity - refunded_quantity - refund_pending_quantity) FROM {$this->items_table} WHERE ticket_id = %d", $ticket->id )
		);
		$new_status = $remaining_total > 0 ? 'partially_redeemed' : 'redeemed';
		$this->wpdb->update(
			$this->tickets_table,
			array( 'status' => $new_status, 'updated_at' => $now ),
			array( 'id' => $ticket->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$event_context = array( 'items' => $redemption_rows, 'status' => $new_status, 'request_hash' => $request_hash );
		if ( ! $this->add_event( $ticket->id, 'redeemed', $event_context, $user_id, $event_key ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_redemption_failed', 'Uplatnění se nepodařilo uložit.', array( 'status' => 500 ) );
		}
		$this->wpdb->query( 'COMMIT' );

		return $this->find( $ticket->id );
	}

	/**
	 * Validate quantities keyed by WooCommerce order item ID.
	 *
	 * The returned plan is a snapshot for UI/gateway context only. reserve_refund()
	 * always validates the quantities again while holding row locks.
	 *
	 * @param int   $order_id       WooCommerce order ID.
	 * @param array $requested_items Map of order item ID => quantity.
	 * @return array|WP_Error Structured, normalized refund plan.
	 */
	public function validate_refund_plan( $order_id, array $requested_items ) {
		$order_id   = absint( $order_id );
		$quantities = $this->normalize_refund_quantities( $requested_items );
		if ( is_wp_error( $quantities ) ) {
			return $quantities;
		}

		$ticket = $this->find_by_order_id( $order_id );
		if ( ! $ticket ) {
			return new WP_Error( 'sam_ticket_not_found', 'Objednávka nemá vystavenou vstupenku.', array( 'status' => 404 ) );
		}

		return $this->build_refund_plan( $ticket, $this->get_items( $ticket->id ), $quantities );
	}

	/**
	 * Reserve unused ticket quantities immediately before the remote refund call.
	 *
	 * Pending quantities cannot be redeemed by the scanner. Repeating an identical
	 * operation key is safe and returns the original reservation.
	 *
	 * @param int    $order_id     WooCommerce order ID.
	 * @param string $operation_key Stable refund UUID/event key.
	 * @param array  $plan         Simple order item ID => quantity map or validated plan.
	 * @param int    $user_id      Initiating WordPress user.
	 * @return array|WP_Error The reserved plan.
	 */
	public function reserve_refund( $order_id, $operation_key, array $plan, $user_id = 0 ) {
		$order_id     = absint( $order_id );
		$operation_key = $this->normalize_operation_key( $operation_key );
		$quantities   = $this->normalize_refund_quantities( $plan );
		$user_id      = absint( $user_id );

		if ( is_wp_error( $operation_key ) ) {
			return $operation_key;
		}
		if ( is_wp_error( $quantities ) ) {
			return $quantities;
		}

		$request_hash = $this->refund_request_hash( $order_id, $quantities );
		$event_key    = $this->refund_event_key( 'reserve', $order_id, $operation_key );
		$commit_key   = $this->refund_event_key( 'commit', $order_id, $operation_key );
		$release_key  = $this->refund_event_key( 'release', $order_id, $operation_key );
		$now          = current_time( 'mysql', true );

		$this->wpdb->query( 'START TRANSACTION' );
		$ticket = $this->locked_ticket_by_order_id( $order_id );
		if ( ! $ticket ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_not_found', 'Objednávka nemá vystavenou vstupenku.', array( 'status' => 404 ) );
		}

		$existing = $this->find_event_by_key( $event_key, true );
		if ( $existing ) {
			$context = $this->decode_event_context( $existing );
			if ( (int) $existing->ticket_id !== (int) $ticket->id || ! hash_equals( (string) ( $context['request_hash'] ?? '' ), $request_hash ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_refund_idempotency_conflict', 'Tento identifikátor refundace už byl použit s jinými údaji.', array( 'status' => 409 ) );
			}
			if ( $this->find_event_by_key( $commit_key, true ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_refund_committed', 'Tato refundace už byla potvrzena.', array( 'status' => 409 ) );
			}
			if ( $this->find_event_by_key( $release_key, true ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_refund_released', 'Tato refundace už byla ukončena po neúspěšném požadavku.', array( 'status' => 409 ) );
			}

			$this->wpdb->query( 'COMMIT' );
			return $context['plan'] ?? $this->build_refund_plan( $ticket, $this->get_items( $ticket->id ), $quantities, true );
		}

		$items = $this->locked_items( $ticket->id );
		$validated_plan = $this->build_refund_plan( $ticket, $items, $quantities );
		if ( is_wp_error( $validated_plan ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return $validated_plan;
		}

		foreach ( $validated_plan['items'] as $entry ) {
			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->items_table}
					SET refund_pending_quantity = refund_pending_quantity + %d
					WHERE id = %d AND ticket_id = %d
					AND redeemed_quantity + refunded_quantity + refund_pending_quantity + %d <= purchased_quantity",
					$entry['quantity'],
					$entry['ticket_item_id'],
					$ticket->id,
					$entry['quantity']
				)
			);
			if ( 1 !== $updated ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_concurrent_update', 'Stav vstupenky se mezitím změnil. Refundaci spusťte znovu.', array( 'status' => 409 ) );
			}
		}

		$context = array(
			'operation_key' => $operation_key,
			'request_hash'  => $request_hash,
			'plan'          => $validated_plan,
			'reserved_at'   => $now,
		);
		if ( ! $this->add_event( $ticket->id, 'refund_reserved', $context, $user_id, $event_key ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_reservation_failed', 'Rezervaci množství pro refundaci se nepodařilo uložit.', array( 'status' => 500 ) );
		}

		$this->wpdb->query( 'COMMIT' );
		return $validated_plan;
	}

	/**
	 * Commit a previously reserved refund after the gateway accepted it.
	 *
	 * @param int    $order_id      WooCommerce order ID.
	 * @param string $operation_key Stable key passed to reserve_refund().
	 * @param string $refund_id     Provider/WooCommerce refund ID for the audit log.
	 * @param int    $user_id       Initiating WordPress user.
	 * @return object|WP_Error Updated ticket row.
	 */
	public function commit_refund( $order_id, $operation_key, $refund_id = '', $user_id = 0 ) {
		$order_id      = absint( $order_id );
		$operation_key = $this->normalize_operation_key( $operation_key );
		$refund_id     = sanitize_text_field( (string) $refund_id );
		$user_id       = absint( $user_id );

		if ( is_wp_error( $operation_key ) ) {
			return $operation_key;
		}

		$reserve_key = $this->refund_event_key( 'reserve', $order_id, $operation_key );
		$commit_key  = $this->refund_event_key( 'commit', $order_id, $operation_key );
		$release_key = $this->refund_event_key( 'release', $order_id, $operation_key );
		$now         = current_time( 'mysql', true );

		$this->wpdb->query( 'START TRANSACTION' );
		$ticket = $this->locked_ticket_by_order_id( $order_id );
		if ( ! $ticket ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_not_found', 'Objednávka nemá vystavenou vstupenku.', array( 'status' => 404 ) );
		}

		if ( $this->find_event_by_key( $commit_key, true ) ) {
			$this->wpdb->query( 'COMMIT' );
			return $this->find( $ticket->id );
		}
		if ( $this->find_event_by_key( $release_key, true ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_released', 'Tato rezervace refundace už byla uvolněna.', array( 'status' => 409 ) );
		}

		$reservation = $this->find_event_by_key( $reserve_key, true );
		if ( ! $reservation || (int) $reservation->ticket_id !== (int) $ticket->id ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_not_reserved', 'Refundace nemá aktivní rezervaci množství.', array( 'status' => 409 ) );
		}

		$reservation_context = $this->decode_event_context( $reservation );
		$reserved_plan       = $reservation_context['plan'] ?? array();
		if ( empty( $reserved_plan['items'] ) || ! is_array( $reserved_plan['items'] ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_reservation_invalid', 'Uložená rezervace refundace je neplatná.', array( 'status' => 500 ) );
		}

		$this->locked_items( $ticket->id );
		foreach ( $reserved_plan['items'] as $entry ) {
			$quantity = absint( $entry['quantity'] ?? 0 );
			$item_id  = absint( $entry['ticket_item_id'] ?? 0 );
			$updated  = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->items_table}
					SET refund_pending_quantity = refund_pending_quantity - %d,
						refunded_quantity = refunded_quantity + %d
					WHERE id = %d AND ticket_id = %d AND refund_pending_quantity >= %d",
					$quantity,
					$quantity,
					$item_id,
					$ticket->id,
					$quantity
				)
			);
			if ( ! $quantity || ! $item_id || 1 !== $updated ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_refund_commit_failed', 'Refundované množství se nepodařilo bezpečně zapsat.', array( 'status' => 500 ) );
			}
		}

		$totals     = $this->ticket_quantity_totals( $ticket->id );
		$new_status = $this->status_after_refund( $ticket->status, $totals );
		$updated    = $this->wpdb->update(
			$this->tickets_table,
			array( 'status' => $new_status, 'updated_at' => $now ),
			array( 'id' => $ticket->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_status_failed', 'Stav vstupenky se po refundaci nepodařilo uložit.', array( 'status' => 500 ) );
		}

		$context = array(
			'operation_key' => $operation_key,
			'refund_id'     => $refund_id,
			'request_hash'  => $reservation_context['request_hash'] ?? '',
			'items'         => $reserved_plan['items'],
			'status'        => $new_status,
		);
		if ( ! $this->add_event( $ticket->id, 'refunded', $context, $user_id, $commit_key ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_event_failed', 'Událost refundace se nepodařilo uložit.', array( 'status' => 500 ) );
		}

		$this->wpdb->query( 'COMMIT' );
		return $this->find( $ticket->id );
	}

	/**
	 * Release a reservation after a rejected/failed remote refund.
	 *
	 * @param int    $order_id      WooCommerce order ID.
	 * @param string $operation_key Stable key passed to reserve_refund().
	 * @param string $reason        Failure reason for the audit log.
	 * @param int    $user_id       Initiating WordPress user.
	 * @return object|WP_Error Updated ticket row.
	 */
	public function release_refund( $order_id, $operation_key, $reason = '', $user_id = 0 ) {
		$order_id      = absint( $order_id );
		$operation_key = $this->normalize_operation_key( $operation_key );
		$reason        = sanitize_text_field( (string) $reason );
		$user_id       = absint( $user_id );

		if ( is_wp_error( $operation_key ) ) {
			return $operation_key;
		}

		$reserve_key = $this->refund_event_key( 'reserve', $order_id, $operation_key );
		$commit_key  = $this->refund_event_key( 'commit', $order_id, $operation_key );
		$release_key = $this->refund_event_key( 'release', $order_id, $operation_key );

		$this->wpdb->query( 'START TRANSACTION' );
		$ticket = $this->locked_ticket_by_order_id( $order_id );
		if ( ! $ticket ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_not_found', 'Objednávka nemá vystavenou vstupenku.', array( 'status' => 404 ) );
		}

		if ( $this->find_event_by_key( $release_key, true ) ) {
			$this->wpdb->query( 'COMMIT' );
			return $this->find( $ticket->id );
		}
		if ( $this->find_event_by_key( $commit_key, true ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_committed', 'Tato refundace už byla potvrzena a její rezervaci nelze uvolnit.', array( 'status' => 409 ) );
		}

		$reservation = $this->find_event_by_key( $reserve_key, true );
		if ( ! $reservation || (int) $reservation->ticket_id !== (int) $ticket->id ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_not_reserved', 'Refundace nemá aktivní rezervaci množství.', array( 'status' => 409 ) );
		}

		$reservation_context = $this->decode_event_context( $reservation );
		$reserved_plan       = $reservation_context['plan'] ?? array();
		if ( empty( $reserved_plan['items'] ) || ! is_array( $reserved_plan['items'] ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_reservation_invalid', 'Uložená rezervace refundace je neplatná.', array( 'status' => 500 ) );
		}

		$this->locked_items( $ticket->id );
		foreach ( $reserved_plan['items'] as $entry ) {
			$quantity = absint( $entry['quantity'] ?? 0 );
			$item_id  = absint( $entry['ticket_item_id'] ?? 0 );
			$updated  = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->items_table}
					SET refund_pending_quantity = refund_pending_quantity - %d
					WHERE id = %d AND ticket_id = %d AND refund_pending_quantity >= %d",
					$quantity,
					$item_id,
					$ticket->id,
					$quantity
				)
			);
			if ( ! $quantity || ! $item_id || 1 !== $updated ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_refund_release_failed', 'Rezervované množství se nepodařilo bezpečně uvolnit.', array( 'status' => 500 ) );
			}
		}

		$now        = current_time( 'mysql', true );
		$totals     = $this->ticket_quantity_totals( $ticket->id );
		$new_status = $this->status_after_refund( $ticket->status, $totals );
		if ( $new_status !== $ticket->status ) {
			$updated = $this->wpdb->update(
				$this->tickets_table,
				array( 'status' => $new_status, 'updated_at' => $now ),
				array( 'id' => $ticket->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_refund_status_failed', 'Stav vstupenky se po uvolnění refundace nepodařilo uložit.', array( 'status' => 500 ) );
			}
		}

		if ( ! $this->add_event(
			$ticket->id,
			'refund_released',
			array(
				'operation_key' => $operation_key,
				'reason'        => $reason,
				'request_hash'  => $reservation_context['request_hash'] ?? '',
				'items'         => $reserved_plan['items'],
			),
			$user_id,
			$release_key
		) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_refund_release_event_failed', 'Uvolnění refundace se nepodařilo zapsat do historie.', array( 'status' => 500 ) );
		}

		$this->wpdb->query( 'COMMIT' );
		return $this->find( $ticket->id );
	}

	/**
	 * Atomically apply a refund when no remote preflight lifecycle is needed.
	 *
	 * Prefer reserve_refund()/commit_refund() around a remote gateway call.
	 */
	public function apply_refund( $order_id, $refund_id, array $plan, $user_id = 0 ) {
		$operation_key = $this->normalize_operation_key( $refund_id );
		if ( is_wp_error( $operation_key ) ) {
			return $operation_key;
		}
		$ticket = $this->find_by_order_id( $order_id );
		if ( $ticket && $this->find_event_by_key( $this->refund_event_key( 'commit', $order_id, $operation_key ) ) ) {
			return $ticket;
		}

		$reserved = $this->reserve_refund( $order_id, $refund_id, $plan, $user_id );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}

		return $this->commit_refund( $order_id, $refund_id, $refund_id, $user_id );
	}

	public function create( array $ticket, array $items ) {
		$this->wpdb->query( 'START TRANSACTION' );

		$inserted = $this->wpdb->insert( $this->tickets_table, $ticket, self::formats( $ticket ) );

		if ( false === $inserted ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sam_ticket_insert_failed', 'Vstupenku se nepodařilo uložit.', $this->wpdb->last_error );
		}

		$ticket_id = (int) $this->wpdb->insert_id;

		foreach ( $items as $item ) {
			$item = array_merge( array( 'ticket_id' => $ticket_id ), $item );
			$result = $this->wpdb->insert( $this->items_table, $item, self::formats( $item ) );

			if ( false === $result ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'sam_ticket_item_insert_failed', 'Položky vstupenky se nepodařilo uložit.', $this->wpdb->last_error );
			}
		}

		$this->wpdb->query( 'COMMIT' );
		$this->add_event( $ticket_id, 'created', array( 'source' => $ticket['source'] ), isset( $ticket['created_by'] ) ? $ticket['created_by'] : 0 );

		return $ticket_id;
	}

	public function set_status_by_order( $order_id, $status, $reason = '' ) {
		$ticket = $this->find_by_order_id( $order_id );
		if ( ! $ticket ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->tickets_table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $ticket->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->add_event( $ticket->id, 'status_changed', array( 'status' => $status, 'reason' => $reason ) );
		}

		return false !== $result;
	}

	/**
	 * Restore a ticket that was voided only because its order was cancelled or
	 * failed, after the order returns to a paid status.
	 *
	 * Tickets voided for any other reason (refunds, reconciliation) stay void.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string|false New status, or false when nothing was restored.
	 */
	public function reinstate_by_order( $order_id ) {
		$ticket = $this->find_by_order_id( $order_id );
		if ( ! $ticket || 'void' !== $ticket->status ) {
			return false;
		}

		$last_change = $this->get_events( $ticket->id, 'status_changed', 1 );
		$context     = $last_change ? $this->decode_event_context( $last_change[0] ) : array();
		if ( 'void' !== ( $context['status'] ?? '' ) || 'woocommerce_order_status' !== ( $context['reason'] ?? '' ) ) {
			return false;
		}

		$now        = current_time( 'mysql', true );
		$new_status = $ticket->valid_until < $now ? 'expired' : $this->status_after_refund( 'valid', $this->ticket_quantity_totals( $ticket->id ) );
		if ( 'void' === $new_status ) {
			return false;
		}

		return $this->set_status_by_order( $order_id, $new_status, 'woocommerce_order_reinstated' ) ? $new_status : false;
	}

	public function add_event( $ticket_id, $event_type, array $context = array(), $user_id = 0, $event_key = '' ) {
		$data = array(
			'ticket_id' => absint( $ticket_id ),
			'event_type' => sanitize_key( $event_type ),
			'user_id'    => absint( $user_id ),
			'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s' );
		$event_key = sanitize_text_field( (string) $event_key );
		if ( $event_key ) {
			$data['event_key'] = substr( $event_key, 0, 190 );
			$formats[]         = '%s';
		}

		return false !== $this->wpdb->insert(
			$this->events_table,
			$data,
			$formats
		);
	}

	/**
	 * Formáty pro $wpdb->insert podle názvů sloupců (pořadí klíčů nehraje roli).
	 */
	private static function formats( array $row ) {
		$integers = array( 'id', 'order_id', 'ticket_id', 'order_item_id', 'product_id', 'created_by', 'purchased_quantity', 'redeemed_quantity', 'refunded_quantity', 'refund_pending_quantity', 'user_id', 'item_id', 'quantity' );
		$formats  = array();
		foreach ( array_keys( $row ) as $column ) {
			if ( 'unit_price' === $column ) {
				$formats[] = '%f';
			} elseif ( in_array( $column, $integers, true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}

	/* ---------- Staré kódy (Event Tickets with Ticket Scanner) ---------- */

	public function find_by_legacy_code( $code ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT t.* FROM {$this->legacy_table} l JOIN {$this->tickets_table} t ON t.id = l.ticket_id WHERE l.code = %s LIMIT 1",
				(string) $code
			)
		);
	}

	public function add_legacy_code( $code, $ticket_id, $order_id, $product_id ) {
		return false !== $this->wpdb->replace(
			$this->legacy_table,
			array(
				'code'        => (string) $code,
				'ticket_id'   => absint( $ticket_id ),
				'order_id'    => absint( $order_id ),
				'product_id'  => absint( $product_id ),
				'imported_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);
	}

	public function count_legacy_codes() {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->legacy_table}" );
	}

	/* ---------- Platnost a reporty ---------- */

	/**
	 * Zneplatní vstupenky po konci platnosti (stav „expired“) a vrátí jejich přehled.
	 * Každá vstupenka se přepne jen jednou, takže report neobsahuje opakovaně tytéž.
	 *
	 * @return array Řádky vstupenek se součty množství (stav před propadnutím).
	 */
	public function expire_overdue() {
		$now     = current_time( 'mysql', true );
		$rows    = $this->report_rows( "t.status IN ('valid','partially_redeemed') AND t.valid_until < %s", array( $now ) );
		$expired = array();
		foreach ( $rows as $row ) {
			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->tickets_table} SET status = 'expired', updated_at = %s WHERE id = %d AND status IN ('valid','partially_redeemed') AND valid_until < %s",
					$now,
					$row->id,
					$now
				)
			);
			if ( 1 === $updated ) {
				$this->add_event( $row->id, 'status_changed', array( 'status' => 'expired', 'reason' => 'validity_ended', 'previous' => $row->status ) );
				$expired[] = $row;
			}
		}
		return $expired;
	}

	/** Platné vstupenky, na kterých ještě zbývá něco k uplatnění (pro účetní). */
	public function unredeemed_rows() {
		$rows = $this->report_rows( "t.status IN ('valid','partially_redeemed') AND t.valid_until >= %s", array( current_time( 'mysql', true ) ) );
		return array_values(
			array_filter(
				$rows,
				static function( $row ) {
					return (int) $row->remaining_total > 0;
				}
			)
		);
	}

	/**
	 * Prodlouží (nebo zkrátí) platnost všech dosud platných vstupenek na nové datum.
	 *
	 * @return int Počet změněných vstupenek.
	 */
	public function set_valid_until_for_active( $valid_until_gmt, $user_id = 0 ) {
		$now = current_time( 'mysql', true );
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->tickets_table} WHERE status IN ('valid','partially_redeemed') AND valid_until >= %s AND valid_until <> %s",
				$now,
				$valid_until_gmt
			)
		);
		foreach ( $ids as $id ) {
			$old = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT valid_until FROM {$this->tickets_table} WHERE id = %d", $id ) );
			$this->wpdb->update( $this->tickets_table, array( 'valid_until' => $valid_until_gmt, 'updated_at' => $now ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
			$this->add_event( $id, 'validity_changed', array( 'from' => $old, 'to' => $valid_until_gmt ), $user_id );
		}
		return count( $ids );
	}

	/**
	 * Vstupenky se součty a hodnotou neuplatněných kusů.
	 * remaining_value = součet (zbývající kusy × jednotková cena) v měně vstupenky.
	 */
	private function report_rows( $where, array $args ) {
		$sql = "SELECT t.*,
				COALESCE(SUM(i.purchased_quantity), 0) AS purchased_total,
				COALESCE(SUM(i.redeemed_quantity), 0) AS redeemed_total,
				COALESCE(SUM(i.refunded_quantity), 0) AS refunded_total,
				COALESCE(SUM(i.purchased_quantity - i.redeemed_quantity - i.refunded_quantity - i.refund_pending_quantity), 0) AS remaining_total,
				COALESCE(SUM((i.purchased_quantity - i.redeemed_quantity - i.refunded_quantity - i.refund_pending_quantity) * i.unit_price), 0) AS remaining_value,
				GROUP_CONCAT(CONCAT(i.purchased_quantity - i.redeemed_quantity - i.refunded_quantity - i.refund_pending_quantity, '× ', i.label) ORDER BY i.id SEPARATOR ', ') AS remaining_items
			FROM {$this->tickets_table} t
			LEFT JOIN {$this->items_table} i ON i.ticket_id = t.id
			WHERE {$where}
			GROUP BY t.id
			ORDER BY t.valid_until ASC, t.id ASC";
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ) );
	}

	private function remaining_quantity( $item ) {
		return max(
			0,
			(int) $item->purchased_quantity
			- (int) $item->redeemed_quantity
			- (int) ( $item->refunded_quantity ?? 0 )
			- (int) ( $item->refund_pending_quantity ?? 0 )
		);
	}

	private function normalize_refund_quantities( array $plan ) {
		$source = isset( $plan['items'] ) && is_array( $plan['items'] ) ? $plan['items'] : $plan;
		$normalized = array();

		foreach ( $source as $order_item_id => $value ) {
			if ( is_array( $value ) ) {
				$order_item_id = $value['order_item_id'] ?? $order_item_id;
				$value         = $value['quantity'] ?? null;
			}

			$order_item_id = absint( $order_item_id );
			$is_integer    = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			$quantity      = $is_integer ? (int) $value : 0;
			if ( ! $order_item_id || ! $is_integer || $quantity < 1 ) {
				return new WP_Error( 'sam_ticket_invalid_refund_quantity', 'Refundace musí obsahovat celé kladné množství u platné položky objednávky.', array( 'status' => 400 ) );
			}
			if ( isset( $normalized[ $order_item_id ] ) ) {
				return new WP_Error( 'sam_ticket_duplicate_refund_item', 'Refundace obsahuje stejnou položku objednávky vícekrát.', array( 'status' => 400 ) );
			}

			$normalized[ $order_item_id ] = $quantity;
		}

		if ( ! $normalized ) {
			return new WP_Error( 'sam_ticket_empty_refund', 'Vyberte alespoň jednu položku k refundaci.', array( 'status' => 400 ) );
		}

		ksort( $normalized, SORT_NUMERIC );
		return $normalized;
	}

	private function build_refund_plan( $ticket, array $items, array $quantities, $allow_reserved = false ) {
		$items_by_order_id = array();
		foreach ( $items as $item ) {
			$order_item_id = absint( $item->order_item_id );
			if ( $order_item_id ) {
				$items_by_order_id[ $order_item_id ] = $item;
			}
		}

		$entries = array();
		foreach ( $quantities as $order_item_id => $quantity ) {
			if ( ! isset( $items_by_order_id[ $order_item_id ] ) ) {
				return new WP_Error( 'sam_ticket_invalid_refund_item', 'Refundace obsahuje položku, která není součástí této vstupenky.', array( 'status' => 400, 'order_item_id' => $order_item_id ) );
			}

			$item       = $items_by_order_id[ $order_item_id ];
			$refundable = $this->remaining_quantity( $item );
			if ( $allow_reserved ) {
				$refundable += (int) ( $item->refund_pending_quantity ?? 0 );
			}
			if ( $quantity > $refundable ) {
				return new WP_Error(
					'sam_ticket_refund_quantity_exceeded',
					sprintf( 'U položky „%s“ lze refundovat nejvýše %d ks.', $item->label, $refundable ),
					array( 'status' => 409, 'order_item_id' => $order_item_id, 'refundable' => $refundable )
				);
			}

			$entries[] = array(
				'ticket_item_id'  => (int) $item->id,
				'order_item_id'   => $order_item_id,
				'label'           => (string) $item->label,
				'unit_type'       => (string) $item->unit_type,
				'quantity'        => $quantity,
				'purchased'       => (int) $item->purchased_quantity,
				'redeemed'        => (int) $item->redeemed_quantity,
				'refunded'        => (int) ( $item->refunded_quantity ?? 0 ),
				'refund_pending'  => (int) ( $item->refund_pending_quantity ?? 0 ),
				'refundable'      => $refundable,
			);
		}

		return array(
			'ticket_id' => (int) $ticket->id,
			'order_id'  => (int) $ticket->order_id,
			'items'     => $entries,
		);
	}

	private function normalize_operation_key( $operation_key ) {
		$operation_key = trim( sanitize_text_field( (string) $operation_key ) );
		if ( '' === $operation_key ) {
			return new WP_Error( 'sam_ticket_refund_key_required', 'Refundace musí mít stabilní identifikátor operace.', array( 'status' => 400 ) );
		}

		return $operation_key;
	}

	private function refund_request_hash( $order_id, array $quantities ) {
		return hash( 'sha256', wp_json_encode( array( 'order_id' => absint( $order_id ), 'items' => $quantities ) ) );
	}

	private function refund_event_key( $action, $order_id, $operation_key ) {
		$prefix = 'refund:' . sanitize_key( $action ) . ':' . absint( $order_id ) . ':';
		$key    = $prefix . $operation_key;
		if ( strlen( $key ) <= 190 ) {
			return $key;
		}

		return $prefix . hash( 'sha256', $operation_key );
	}

	private function locked_ticket_by_order_id( $order_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->tickets_table} WHERE order_id = %d LIMIT 1 FOR UPDATE", absint( $order_id ) )
		);
	}

	private function locked_items( $ticket_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->items_table} WHERE ticket_id = %d ORDER BY id ASC FOR UPDATE", absint( $ticket_id ) )
		);
	}

	private function find_event_by_key( $event_key, $for_update = false ) {
		$sql = $this->wpdb->prepare( "SELECT * FROM {$this->events_table} WHERE event_key = %s LIMIT 1", $event_key );
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}

		return $this->wpdb->get_row( $sql );
	}

	private function decode_event_context( $event ) {
		$context = json_decode( (string) ( $event->context ?? '' ), true );
		return is_array( $context ) ? $context : array();
	}

	private function ticket_quantity_totals( $ticket_id ) {
		$totals = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT COALESCE(SUM(purchased_quantity), 0) AS purchased,
					COALESCE(SUM(redeemed_quantity), 0) AS redeemed,
					COALESCE(SUM(refunded_quantity), 0) AS refunded,
					COALESCE(SUM(refund_pending_quantity), 0) AS refund_pending
				FROM {$this->items_table} WHERE ticket_id = %d",
				absint( $ticket_id )
			)
		);

		return array(
			'purchased'      => (int) ( $totals->purchased ?? 0 ),
			'redeemed'       => (int) ( $totals->redeemed ?? 0 ),
			'refunded'       => (int) ( $totals->refunded ?? 0 ),
			'refund_pending' => (int) ( $totals->refund_pending ?? 0 ),
		);
	}

	private function status_after_refund( $current_status, array $totals ) {
		if ( in_array( $current_status, array( 'void', 'expired' ), true ) ) {
			return $current_status;
		}

		$remaining = max( 0, $totals['purchased'] - $totals['redeemed'] - $totals['refunded'] - $totals['refund_pending'] );
		if ( 0 === $remaining ) {
			return $totals['redeemed'] > 0 ? 'redeemed' : 'void';
		}

		return $totals['redeemed'] > 0 ? 'partially_redeemed' : 'valid';
	}

	public function get_admin_rows( $limit = 50, $offset = 0, $search = '', $status = '' ) {
		$limit  = max( 1, min( 200, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );
		list( $where, $args ) = $this->admin_filter( $search, $status );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT t.*, COALESCE(SUM(i.purchased_quantity), 0) AS purchased_total,
					COALESCE(SUM(i.redeemed_quantity), 0) AS redeemed_total,
					COALESCE(SUM(i.refunded_quantity), 0) AS refunded_total,
					COALESCE(SUM(i.refund_pending_quantity), 0) AS refund_pending_total
				FROM {$this->tickets_table} t
				LEFT JOIN {$this->items_table} i ON i.ticket_id = t.id
				WHERE {$where}
				GROUP BY t.id
				ORDER BY t.id DESC
				LIMIT %d OFFSET %d",
				array_merge( $args, array( $limit, $offset ) )
			)
		);
	}

	public function count_tickets( $search = '', $status = '' ) {
		list( $where, $args ) = $this->admin_filter( $search, $status );
		$sql = "SELECT COUNT(*) FROM {$this->tickets_table} t WHERE {$where}";
		return (int) $this->wpdb->get_var( $args ? $this->wpdb->prepare( $sql, $args ) : $sql );
	}

	/**
	 * Filtr přehledu: hledání (číslo objednávky, ID vstupenky, e-mail, začátek kódu)
	 * a stav (valid, partially_redeemed, redeemed, void, expired; „expired“ zahrnuje
	 * i platné vstupenky po datu, které denní úloha ještě nepřepnula).
	 */
	private function admin_filter( $search, $status ) {
		$where = array( '1=1' );
		$args  = array();
		$now   = current_time( 'mysql', true );

		$status = sanitize_key( (string) $status );
		if ( 'expired' === $status ) {
			$where[] = "(t.status = 'expired' OR (t.status IN ('valid','partially_redeemed') AND t.valid_until < %s))";
			$args[]  = $now;
		} elseif ( in_array( $status, array( 'valid', 'partially_redeemed' ), true ) ) {
			$where[] = 't.status = %s AND t.valid_until >= %s';
			$args[]  = $status;
			$args[]  = $now;
		} elseif ( in_array( $status, array( 'redeemed', 'void' ), true ) ) {
			$where[] = 't.status = %s';
			$args[]  = $status;
		}

		$search = trim( sanitize_text_field( (string) $search ) );
		if ( '' !== $search ) {
			$number = ltrim( $search, '#' );
			if ( ctype_digit( $number ) ) {
				$where[] = '(t.order_id = %d OR t.id = %d)';
				$args[]  = (int) $number;
				$args[]  = (int) $number;
			} else {
				$where[] = '(t.customer_email LIKE %s OR t.token LIKE %s)';
				$args[]  = '%' . $this->wpdb->esc_like( $search ) . '%';
				$args[]  = $this->wpdb->esc_like( strtolower( $search ) ) . '%';
			}
		}

		return array( implode( ' AND ', $where ), $args );
	}

	/** Počty vstupenek podle stavu (prošlé platné se počítají jako „expired“). */
	public function count_by_status() {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT CASE WHEN status IN ('valid','partially_redeemed') AND valid_until < %s THEN 'expired' ELSE status END AS s, COUNT(*) AS n
				FROM {$this->tickets_table} GROUP BY s",
				current_time( 'mysql', true )
			)
		);
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->s ] = (int) $row->n;
		}
		return $counts;
	}
}
