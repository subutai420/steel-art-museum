<?php

defined( 'ABSPATH' ) || exit;

final class SAM_Tickets_Service {
	private $repository;

	public function __construct( SAM_Tickets_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_hooks() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_order_context' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'capture_order_context' ), 20 );
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_paid_order' ), 5 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_paid_order' ), 5 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_paid_order' ), 5 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_void_order' ), 20 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'handle_void_order' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_refunded_order' ), 20 );
	}

	public function capture_order_context( $order, $data = null ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$language = apply_filters( 'wpml_current_language', null );
		$language = $this->normalize_language( $language ? $language : get_locale() );
		$currency = strtoupper( (string) $order->get_currency() );

		$order->update_meta_data( '_sam_language', $language );
		$order->update_meta_data( '_sam_currency', $currency ? $currency : strtoupper( get_woocommerce_currency() ) );
	}

	public function handle_paid_order( $order_id ) {
		$result = $this->generate_for_order( $order_id );
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( 'sam_ticket_exists' === $result->get_error_code() ) {
			$restored = $this->repository->reinstate_by_order( $order_id );
			if ( $restored && $order ) {
				$order->add_order_note( 'Objednávka je znovu zaplacená, vstupenka byla obnovena.' );
			}
			return;
		}
		if ( $order && 'sam_order_not_paid' !== $result->get_error_code() ) {
			$order->add_order_note( 'Vstupenku se nepodařilo vytvořit: ' . $result->get_error_message() );
		}
	}

	public function handle_void_order( $order_id ) {
		// Původní důvod už zneplatněné vstupenky (např. refundace) se nepřepisuje,
		// aby ji pozdější cyklus zrušeno → zaplaceno nemohl obnovit.
		$ticket = $this->repository->find_by_order_id( $order_id );
		if ( ! $ticket || 'void' === $ticket->status ) {
			return;
		}
		$this->repository->set_status_by_order( $order_id, 'void', 'woocommerce_order_status' );
	}

	/**
	 * Refundovaná objednávka nesmí mít použitelný QR kód, ani když peníze odešly
	 * mimo refundaci po položkách (portál Stripe, ruční změna stavu). Na rozdíl od
	 * zrušení se už nikdy neobnoví.
	 */
	public function handle_refunded_order( $order_id ) {
		$ticket = $this->repository->find_by_order_id( $order_id );
		if ( ! $ticket || in_array( $ticket->status, array( 'void', 'redeemed', 'expired' ), true ) ) {
			return;
		}

		if ( $this->repository->set_status_by_order( $order_id, 'void', 'woocommerce_order_refunded' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( 'Objednávka byla označena jako refundovaná, QR vstupenka byla zneplatněna.' );
			}
		}
	}

	public function generate_for_order( $order_id, $allow_unpaid = false ) {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );

		if ( ! $order || $order instanceof WC_Order_Refund ) {
			return new WP_Error( 'sam_order_missing', 'Objednávka neexistuje.' );
		}

		$existing = $this->repository->find_by_order_id( $order_id );
		if ( $existing ) {
			return new WP_Error( 'sam_ticket_exists', 'Objednávka už má vstupenku.', array( 'ticket_id' => (int) $existing->id ) );
		}

		if ( ! $allow_unpaid && ! $order->is_paid() ) {
			return new WP_Error( 'sam_order_not_paid', 'Objednávka zatím není zaplacená.' );
		}

		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $order_item_id => $order_item ) {
			$product_id = $order_item->get_variation_id() ? $order_item->get_variation_id() : $order_item->get_product_id();
			$quantity   = max( 0, (int) $order_item->get_quantity() );

			if ( ! $product_id || ! $quantity || ! self::is_ticket_product( $product_id ) ) {
				continue;
			}

			$items[] = array(
				'order_item_id'      => absint( $order_item_id ),
				'product_id'         => absint( $product_id ),
				'label'              => sanitize_text_field( $order_item->get_name() ),
				'unit_type'          => self::unit_type( $product_id, $order_item->get_name() ),
				'unit_price'         => round( ( (float) $order_item->get_total() + (float) $order_item->get_total_tax() ) / $quantity, 2 ),
				'purchased_quantity' => $quantity,
				'redeemed_quantity'  => 0,
			);
		}

		if ( ! $items ) {
			return new WP_Error( 'sam_ticket_invalid_order', 'Objednávka neobsahuje žádné vstupenky.' );
		}

		$paid_date   = $order->get_date_paid();
		$valid_ts    = $paid_date ? $paid_date->getTimestamp() : time();
		$valid_until = SAM_Tickets_Validity::valid_until_gmt();
		$now         = current_time( 'mysql', true );
		$source      = sanitize_key( $order->get_meta( '_sam_ticket_source', true ) );
		$language    = $this->order_language( $order );
		$currency    = strtoupper( sanitize_text_field( $order->get_currency() ) );

		$ticket_id = $this->repository->create(
			array(
				'order_id'       => $order_id,
				'token'          => $this->generate_unique_token(),
				'customer_email' => sanitize_email( $order->get_billing_email() ),
				'language'       => $language,
				'currency'       => $currency ? $currency : ( 'cs' === $language ? 'CZK' : 'EUR' ),
				'source'         => $source ? $source : 'woocommerce',
				'status'         => 'valid',
				'valid_from'     => gmdate( 'Y-m-d H:i:s', $valid_ts ),
				'valid_until'    => $valid_until,
				'created_by'     => absint( $order->get_meta( '_sam_ticket_created_by', true ) ),
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			$items
		);

		if ( is_wp_error( $ticket_id ) ) {
			$race_winner = $this->repository->find_by_order_id( $order_id );
			return $race_winner ? (int) $race_winner->id : $ticket_id;
		}

		$order->update_meta_data( '_sam_ticket_id', $ticket_id );
		$order->save_meta_data();
		$order->add_order_note( sprintf( 'Vytvořena vstupenka #%d s platností do %s.', $ticket_id, get_date_from_gmt( $valid_until, 'j. n. Y' ) ) );

		do_action( 'sam_ticket_created', $ticket_id, $order_id );

		return $ticket_id;
	}

	/**
	 * Je produkt vstupenka? Na Steel Art Museum jsou vstupenkami všechny produkty;
	 * výjimku jde nastavit metou _sam_not_ticket = yes (např. budoucí merch).
	 */
	public static function is_ticket_product( $product_id ) {
		return 'yes' !== get_post_meta( absint( $product_id ), '_sam_not_ticket', true );
	}

	/**
	 * Jak se položka uplatňuje na pokladně: „single“ = každý kus je jeden návštěvník,
	 * „group“ = každý kus je skupina (rodinné 2+1, Magenta 1+1…) a uplatní se najednou.
	 * Ruční přepis: meta produktu _sam_ticket_unit = single | group.
	 */
	public static function unit_type( $product_id, $name = '' ) {
		$override = (string) get_post_meta( absint( $product_id ), '_sam_ticket_unit', true );
		if ( in_array( $override, array( 'single', 'group' ), true ) ) {
			return $override;
		}
		$name = $name ? $name : get_the_title( $product_id );
		return preg_match( '/\d\s*\+\s*\d/u', (string) $name ) || preg_match( '/rodinn|family/iu', (string) $name ) ? 'group' : 'single';
	}

	public function order_language( WC_Order $order ) {
		$language = (string) $order->get_meta( '_sam_language', true );
		if ( ! $language ) {
			$language = (string) $order->get_meta( 'wpml_language', true );
		}
		return $this->normalize_language( $language ? $language : ( 'EUR' === $order->get_currency() ? 'en' : 'cs' ) );
	}

	private function normalize_language( $language ) {
		$language = strtolower( sanitize_key( (string) $language ) );
		return 0 === strpos( $language, 'en' ) ? 'en' : 'cs';
	}

	public function generate_unique_token() {
		do {
			$token = bin2hex( random_bytes( 24 ) );
		} while ( $this->repository->find_by_token( $token ) );

		return $token;
	}
}
