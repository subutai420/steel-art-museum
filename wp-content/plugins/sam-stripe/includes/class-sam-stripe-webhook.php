<?php

defined( 'ABSPATH' ) || exit;

/**
 * Webhook Stripe: POST /wp-json/sam-stripe/v1/webhook
 *
 * - payment_intent.succeeded: označí objednávku jako zaplacenou (záloha k ověření
 *   při návratu – zákazník mohl po platbě zavřít okno).
 * - payment_intent.payment_failed: poznámka k objednávce (zákazník může zkusit znovu).
 * - charge.refunded: refundace provedená mimo web (v Stripe) → poznámka a upozornění,
 *   že vstupenky je potřeba zkontrolovat ručně.
 *
 * Podpis se ověřuje tajemstvím SAM_STRIPE_WEBHOOK_SECRET (tolerance 5 minut).
 */
final class SAM_Stripe_Webhook {
	const TOLERANCE = 300;

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route() {
		register_rest_route(
			'sam-stripe/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // ověřuje se podpis
			)
		);
	}

	/**
	 * Ověří hlavičku Stripe-Signature: t=…,v1=… (HMAC-SHA256 z „t.payload“).
	 */
	public static function verify_signature( $payload, $header, $secret, $now = null ) {
		if ( '' === (string) $secret || '' === (string) $header ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		$now = null === $now ? time() : (int) $now;
		if ( ! $timestamp || ! $signatures || abs( $now - $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, (string) $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	public function handle( WP_REST_Request $request ) {
		$payload = (string) $request->get_body();
		if ( ! self::verify_signature( $payload, (string) $request->get_header( 'stripe-signature' ), SAM_Stripe_Config::webhook_secret() ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}
		// Testovací události nesmí ovlivnit ostrý web a naopak.
		if ( isset( $event['livemode'] ) && (bool) $event['livemode'] === SAM_Stripe_Config::is_test_mode() ) {
			return new WP_REST_Response( array( 'ignored' => 'mode mismatch' ), 200 );
		}

		$object = $event['data']['object'] ?? array();
		switch ( $event['type'] ) {
			case 'payment_intent.succeeded':
				$this->intent_succeeded( $object );
				break;
			case 'payment_intent.payment_failed':
				$this->intent_failed( $object );
				break;
			case 'charge.refunded':
				$this->charge_refunded( $object );
				break;
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private function order_for_intent( array $intent ) {
		$order_id = absint( $intent['metadata']['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || (string) $order->get_meta( SAM_Stripe_Gateway::META_INTENT, true ) !== (string) ( $intent['id'] ?? '' ) ) {
			return null;
		}
		return $order;
	}

	private function intent_succeeded( array $intent ) {
		$order = $this->order_for_intent( $intent );
		if ( ! $order || $order->is_paid() || ! class_exists( 'SAM_Stripe_Gateway' ) ) {
			return;
		}
		// Kvůli detailům karty/peněženky načteme PaymentIntent i s platbou.
		$full = ( new SAM_Stripe_Client() )->get( 'payment_intents/' . rawurlencode( $intent['id'] ), array( 'expand' => array( 'latest_charge' ) ) );
		SAM_Stripe_Gateway::complete_from_intent( $order, is_wp_error( $full ) ? $intent : $full );
	}

	private function intent_failed( array $intent ) {
		$order = $this->order_for_intent( $intent );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$error = $intent['last_payment_error'] ?? array();
		$order->add_order_note( sprintf( 'Stripe: pokus o platbu neprošel (%s).', sanitize_text_field( (string) ( $error['decline_code'] ?? $error['code'] ?? $error['message'] ?? 'neznámý důvod' ) ) ) );
	}

	/**
	 * Refundace z webu nesou metadata wc_refund_id (přidává je SAM_Stripe_Refunds).
	 * Ostatní vznikly v Stripe ručně – WooCommerce ani vstupenky o nich neví.
	 */
	private function charge_refunded( array $charge ) {
		$order = null;
		$intent_id = (string) ( $charge['payment_intent'] ?? '' );
		if ( $intent_id ) {
			$order_id = absint( $charge['metadata']['order_id'] ?? 0 );
			if ( ! $order_id ) {
				$orders   = wc_get_orders( array( 'transaction_id' => $intent_id, 'limit' => 1 ) );
				$order_id = $orders ? $orders[0]->get_id() : 0;
			}
			$order = $order_id ? wc_get_order( $order_id ) : null;
		}
		if ( ! $order ) {
			$orders = wc_get_orders( array( 'transaction_id' => (string) ( $charge['id'] ?? '' ), 'limit' => 1 ) );
			$order  = $orders ? $orders[0] : null;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$known = array();
		foreach ( $order->get_refunds() as $refund ) {
			$known[] = (string) $refund->get_meta( '_sam_stripe_refund', true );
		}
		foreach ( (array) ( $charge['refunds']['data'] ?? array() ) as $refund ) {
			$id = (string) ( $refund['id'] ?? '' );
			if ( ! $id || in_array( $id, $known, true ) || ! empty( $refund['metadata']['wc_refund_id'] ) ) {
				continue;
			}
			if ( $order->get_meta( '_sam_stripe_external_refund_' . $id, true ) ) {
				continue;
			}
			$order->update_meta_data( '_sam_stripe_external_refund_' . $id, current_time( 'mysql' ) );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					'POZOR: v Stripe byla mimo web provedena refundace %s %s (%s). V objednávce ani u vstupenky se automaticky nepropsala – zkontrolujte a případně zneplatněte vstupenky ručně.',
					number_format( ( (int) $refund['amount'] ) / 100, 2, ',', ' ' ),
					strtoupper( (string) ( $refund['currency'] ?? '' ) ),
					$id
				)
			);
		}
	}
}
