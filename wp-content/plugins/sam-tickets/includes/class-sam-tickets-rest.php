<?php

defined( 'ABSPATH' ) || exit;

final class SAM_Tickets_REST {
	private $repository;

	public function __construct( SAM_Tickets_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'sam-tickets/v1',
			'/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array( 'token' => array( 'required' => true, 'type' => 'string' ) ),
			)
		);

		register_rest_route(
			'sam-tickets/v1',
			'/redeem',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'redeem' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'token'       => array( 'required' => true, 'type' => 'string' ),
					'items'       => array( 'required' => true, 'type' => 'array' ),
					'operationId' => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
	}

	public function permission_check() {
		return is_user_logged_in() && current_user_can( 'sam_scan_tickets' );
	}

	public function lookup( WP_REST_Request $request ) {
		$ticket = $this->resolve_ticket( $request->get_param( 'token' ) );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		return rest_ensure_response( $this->format_ticket( $ticket ) );
	}

	public function redeem( WP_REST_Request $request ) {
		$ticket = $this->resolve_ticket( $request->get_param( 'token' ) );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}

		$requested = array();
		foreach ( (array) $request->get_param( 'items' ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'], $entry['quantity'] ) ) {
				$requested[ absint( $entry['id'] ) ] = absint( $entry['quantity'] );
			}
		}

		$operation_id = sanitize_text_field( (string) $request->get_param( 'operationId' ) );
		$result       = $this->repository->redeem( $ticket->token, $requested, get_current_user_id(), $operation_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result->order_id && empty( $result->sam_replayed ) ) {
			$order = wc_get_order( $result->order_id );
			if ( $order ) {
				$order->update_meta_data( '_sam_ticket_usage_status', $result->status );
				$order->save_meta_data();
				$order->add_order_note( sprintf( 'Vstupenka uplatněna pracovníkem %s. Nový stav: %s.', wp_get_current_user()->display_name, $result->status ) );
			}
		}

		return rest_ensure_response( $this->format_ticket( $result ) );
	}

	/**
	 * Najde vstupenku podle obsahu QR / ručně zadaného kódu.
	 * Nový formát: 48 hex znaků. Starý formát (Event Tickets with Ticket Scanner):
	 * „<idcode>-<objednávka>-<KÓD>“, odkaz …/ticket/<totéž>, KÓD nebo KÓD po trojicích.
	 */
	private function resolve_ticket( $value ) {
		$parsed = self::parse_code( $value );
		if ( ! $parsed ) {
			return new WP_Error( 'sam_ticket_invalid_token', 'QR kód nemá platný formát.', array( 'status' => 400 ) );
		}

		$ticket = 'token' === $parsed['type'] ? $this->repository->find_by_token( $parsed['value'] ) : $this->repository->find_by_legacy_code( $parsed['value'] );
		if ( ! $ticket ) {
			$message = 'legacy' === $parsed['type'] ? 'Vstupenka ze starého systému nebyla nalezena (neplatná nebo už propadlá).' : 'Vstupenka nebyla nalezena.';
			return new WP_Error( 'sam_ticket_not_found', $message, array( 'status' => 404 ) );
		}
		return $ticket;
	}

	/**
	 * @return array{type:string,value:string}|null
	 */
	public static function parse_code( $value ) {
		$raw = trim( (string) $value );
		if ( preg_match( '/([a-f0-9]{48})/', strtolower( $raw ), $matches ) ) {
			return array( 'type' => 'token', 'value' => $matches[1] );
		}
		$upper = strtoupper( $raw );
		// Veřejné ID starého systému: číslo-objednávka-KÓD (i uvnitř odkazu).
		if ( preg_match( '/\d+-\d+-([A-Z0-9]{12,40})\b/', $upper, $matches ) ) {
			return array( 'type' => 'legacy', 'value' => $matches[1] );
		}
		// Samotný kód, případně zobrazený po trojicích s pomlčkami.
		$compact = str_replace( array( '-', ' ' ), '', $upper );
		if ( preg_match( '/^[A-Z0-9]{12,40}$/', $compact ) ) {
			return array( 'type' => 'legacy', 'value' => $compact );
		}
		return null;
	}

	private function format_ticket( $ticket ) {
		$now    = current_time( 'mysql', true );
		$status = $ticket->valid_until < $now && ! in_array( $ticket->status, array( 'redeemed', 'void' ), true ) ? 'expired' : $ticket->status;
		$items  = array();

		foreach ( $this->repository->get_items( $ticket->id ) as $item ) {
			$refunded       = (int) ( $item->refunded_quantity ?? 0 );
			$refund_pending = (int) ( $item->refund_pending_quantity ?? 0 );
			$items[]        = array(
				'id'            => (int) $item->id,
				'label'         => $item->label,
				'unitType'      => $item->unit_type,
				'purchased'     => (int) $item->purchased_quantity,
				'redeemed'      => (int) $item->redeemed_quantity,
				'refunded'      => $refunded,
				'refundPending' => $refund_pending,
				'remaining'     => max( 0, (int) $item->purchased_quantity - (int) $item->redeemed_quantity - $refunded - $refund_pending ),
			);
		}

		$history = array();
		foreach ( $this->repository->get_redemptions( $ticket->id ) as $redemption ) {
			$user      = get_userdata( $redemption->user_id );
			$history[] = array(
				'type'     => 'redeemed',
				'label'    => $redemption->label,
				'quantity' => (int) $redemption->quantity,
				'user'     => $user ? $user->display_name : ( 'legacy' === $redemption->note ? 'Starý systém' : 'Neznámý pracovník' ),
				'date'     => get_date_from_gmt( $redemption->redeemed_at, 'j. n. Y H:i' ),
				'_sort'    => strtotime( $redemption->redeemed_at . ' UTC' ),
			);
		}
		foreach ( $this->repository->get_events( $ticket->id, 'refunded', 20 ) as $event ) {
			$context = json_decode( (string) $event->context, true );
			if ( ! is_array( $context ) || empty( $context['items'] ) || ! is_array( $context['items'] ) ) {
				continue;
			}
			$user = get_userdata( $event->user_id );
			foreach ( $context['items'] as $entry ) {
				$history[] = array(
					'type'     => 'refunded',
					'label'    => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
					'quantity' => absint( $entry['quantity'] ?? 0 ),
					'user'     => $user ? $user->display_name : 'Systém',
					'date'     => get_date_from_gmt( $event->created_at, 'j. n. Y H:i' ),
					'refundId' => sanitize_text_field( (string) ( $context['refund_id'] ?? '' ) ),
					'_sort'    => strtotime( $event->created_at . ' UTC' ),
				);
			}
		}
		usort(
			$history,
			static function( $left, $right ) {
				return (int) $right['_sort'] <=> (int) $left['_sort'];
			}
		);
		$history = array_slice( $history, 0, 20 );
		foreach ( $history as &$entry ) {
			unset( $entry['_sort'] );
		}
		unset( $entry );

		return array(
			'id'            => (int) $ticket->id,
			'token'         => $ticket->token,
			'orderId'       => (int) $ticket->order_id,
			'source'        => $ticket->source,
			'customerEmail' => $ticket->customer_email,
			'language'      => $ticket->language,
			'currency'      => $ticket->currency,
			'status'        => $status,
			'validUntil'    => get_date_from_gmt( $ticket->valid_until, 'j. n. Y' ),
			'canRedeem'     => in_array( $status, array( 'valid', 'partially_redeemed' ), true ) && array_sum( array_column( $items, 'remaining' ) ) > 0,
			'items'         => $items,
			'history'       => $history,
		);
	}
}
