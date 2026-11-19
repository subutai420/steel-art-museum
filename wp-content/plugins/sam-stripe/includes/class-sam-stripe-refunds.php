<?php

defined( 'ABSPATH' ) || exit;

/**
 * Refundace přes Stripe napojené na vstupenky (sam-tickets).
 *
 * Smlouva háčků (stejná jako u brány GP webpay v MoB):
 * - sam_payment_refund_context (filtr): sam-tickets vrátí refund_id a operation_key
 *   refundace, ke které už ověřil plán položek (kolik kusů kterých vstupenek).
 * - sam_payment_refund_preflight (filtr): těsně před voláním Stripe rezervuje kusy,
 *   aby je skener nemohl souběžně uplatnit. Vrací true nebo WP_Error.
 * - sam_payment_refund_accepted (akce): Stripe refundaci přijal → kusy se odepíší.
 * - sam_payment_refund_failed (akce): odmítnuto → rezervace se uvolní; při nejasném
 *   výsledku („indeterminate“) zůstanou kusy blokované do ruční kontroly.
 *
 * Objednávka bez vstupenky (stará, plně uplatněná nebo propadlá) se refunduje bez
 * háčků – není co rezervovat.
 */
final class SAM_Stripe_Refunds {

	/**
	 * @return true|WP_Error
	 */
	public function process( WC_Payment_Gateway $gateway, $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || null === $amount || (float) $amount <= 0 ) {
			return new WP_Error( 'sam_stripe_refund_local', 'Objednávku nebo částku refundace nelze ověřit.' );
		}

		$reference = (string) $order->get_transaction_id();
		if ( ! preg_match( '/^(pi|ch|py)_[A-Za-z0-9]+$/', $reference ) ) {
			return new WP_Error( 'sam_stripe_refund_reference', 'Objednávka nemá ID platby Stripe (pi_/ch_), refundaci nelze poslat.' );
		}

		$has_ticket = $this->order_has_ticket( $order );
		$context    = $has_ticket ? apply_filters( 'sam_payment_refund_context', array(), (int) $order_id, $amount, (string) $reason ) : array();
		$refund_id  = isset( $context['refund_id'] ) ? absint( $context['refund_id'] ) : $this->latest_refund_id( $order );
		$operation  = isset( $context['operation_key'] ) && is_string( $context['operation_key'] ) ? $context['operation_key'] : '';

		if ( $has_ticket && ( ! $refund_id || '' === $operation ) ) {
			return $this->error( 'context', $order_id, $refund_id, $operation, 'Chybí jednoznačný kontext refundovaných vstupenek (vyberte položky a počty kusů).' );
		}
		if ( ! SAM_Stripe_Config::is_configured() ) {
			return $this->error( 'configuration', $order_id, $refund_id, $operation, 'Stripe není nastavený.' );
		}

		if ( $has_ticket ) {
			$preflight = apply_filters( 'sam_payment_refund_preflight', true, (int) $order_id, $refund_id, $operation, $amount, (string) $reason );
			if ( is_wp_error( $preflight ) ) {
				$this->failed( $order_id, $refund_id, $operation, 'preflight' );
				return $preflight;
			}
			if ( true !== $preflight ) {
				return $this->error( 'preflight', $order_id, $refund_id, $operation, 'Refundované vstupenky se nepodařilo bezpečně rezervovat.' );
			}
		}

		$params = array(
			'amount'   => SAM_Stripe_Config::to_minor( $amount, $order->get_currency() ),
			'metadata' => array(
				'order_id'     => $order->get_id(),
				'wc_refund_id' => $refund_id,
				'reason'       => substr( wp_strip_all_tags( (string) $reason ), 0, 400 ),
			),
		);
		if ( 0 === strpos( $reference, 'pi_' ) ) {
			$params['payment_intent'] = $reference;
		} else {
			$params['charge'] = $reference; // objednávky ze starého pluginu
		}
		$idempotency = 'sam-refund-' . $order->get_id() . '-' . ( $refund_id ? $refund_id : md5( $amount . '|' . $reason ) );

		$result = ( new SAM_Stripe_Client() )->post( 'refunds', $params, $idempotency );
		if ( is_wp_error( $result ) ) {
			$data          = (array) $result->get_error_data();
			$indeterminate = ! empty( $data['indeterminate'] );
			wc_get_logger()->error( 'Refundace objednávky #' . $order_id . ': ' . $result->get_error_message(), array( 'source' => 'sam-stripe' ) );
			return $this->error(
				$indeterminate ? 'indeterminate' : 'rejected',
				$order_id,
				$refund_id,
				$operation,
				$indeterminate
					? 'Výsledek refundace u Stripe nelze bezpečně určit. Požadavek neopakujte a zkontrolujte ho v Stripe (Platby). ' . $result->get_error_message()
					: 'Stripe refundaci odmítl: ' . $result->get_error_message() . ' Objednávka ani vstupenka nebyly změněny.'
			);
		}

		$status = (string) ( $result['status'] ?? '' );
		if ( in_array( $status, array( 'failed', 'canceled' ), true ) ) {
			return $this->error( 'rejected', $order_id, $refund_id, $operation, 'Stripe refundaci nedokončil (stav ' . $status . '). Objednávka ani vstupenka nebyly změněny.' );
		}

		// Stripe refundaci přijal (succeeded / pending / requires_action). Od teď se už nesmí
		// vracet chyba – WooCommerce by smazal záznam refundace, i když peníze odcházejí.
		if ( $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( $refund instanceof WC_Order_Refund ) {
				$refund->update_meta_data( '_sam_stripe_refund', (string) $result['id'] );
				$refund->save_meta_data();
			}
		}
		try {
			if ( $has_ticket ) {
				do_action( 'sam_payment_refund_accepted', (int) $order_id, $refund_id, $operation, $amount, array( 'stripe_refund' => $result['id'], 'status' => $status ) );
			}
			$order->add_order_note( sprintf( 'Stripe přijal refundaci %s (%s, stav %s).', wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ), $result['id'], $status ) );
		} catch ( Throwable $error ) {
			wc_get_logger()->critical( 'Stripe přijal refundaci objednávky #' . $order_id . ', ale aktualizace vstupenek selhala: ' . $error->getMessage(), array( 'source' => 'sam-stripe' ) );
			$order->add_order_note( 'Stripe refundaci přijal, ale aktualizace vstupenek vyžaduje ruční kontrolu.' );
		}
		return true;
	}

	private function order_has_ticket( WC_Order $order ) {
		if ( ! class_exists( 'SAM_Tickets_Repository' ) ) {
			return false;
		}
		return (bool) ( new SAM_Tickets_Repository() )->find_by_order_id( $order->get_id() );
	}

	/** WooCommerce vytváří záznam refundace před voláním brány – nejnovější patří této operaci. */
	private function latest_refund_id( WC_Order $order ) {
		$refunds = $order->get_refunds();
		return $refunds ? (int) $refunds[0]->get_id() : 0;
	}

	private function error( $code, $order_id, $refund_id, $operation, $message ) {
		$this->failed( $order_id, $refund_id, $operation, $code );
		return new WP_Error( 'sam_stripe_refund_' . sanitize_key( $code ), $message );
	}

	private function failed( $order_id, $refund_id, $operation, $code ) {
		if ( ! $operation ) {
			return;
		}
		try {
			do_action( 'sam_payment_refund_failed', (int) $order_id, (int) $refund_id, (string) $operation, sanitize_key( $code ) );
		} catch ( Throwable $error ) {
			wc_get_logger()->critical( 'Uvolnění rezervace refundace selhalo u objednávky #' . $order_id . ': ' . $error->getMessage(), array( 'source' => 'sam-stripe' ) );
		}
	}
}
