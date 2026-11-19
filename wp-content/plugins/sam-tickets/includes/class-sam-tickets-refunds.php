<?php

defined( 'ABSPATH' ) || exit;

/**
 * Udržuje v souladu refundace WooCommerce, platební bránu (Stripe) a použitelné kusy na QR vstupence.
 *
 * WooCommerce does not pass refund line items to a gateway's process_refund()
 * method. We therefore validate and capture the immutable item plan on
 * woocommerce_create_refund, then hand only its stable identity to the gateway
 * in the same request. Ticket quantities are reserved immediately before the
 * remote call so they cannot be redeemed concurrently.
 */
final class SAM_Tickets_Refunds {
	const META_OPERATION = '_sam_ticket_refund_operation';
	const META_PLAN      = '_sam_ticket_refund_plan';
	const META_STATE     = '_sam_ticket_refund_state';
	const META_GATEWAY_AUDIT = '_sam_ticket_refund_gateway_audit';

	private $repository;
	private $current_context = array();

	public function __construct( SAM_Tickets_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_hooks() {
		add_action( 'woocommerce_create_refund', array( $this, 'capture_refund_plan' ), 5, 2 );
		add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'render_admin_refund_guidance' ) );
		add_filter( 'sam_payment_refund_context', array( $this, 'provide_gateway_context' ), 10, 4 );
		add_filter( 'sam_payment_refund_preflight', array( $this, 'reserve_ticket_quantities' ), 10, 6 );
		add_action( 'sam_payment_refund_accepted', array( $this, 'commit_gateway_refund' ), 10, 5 );
		add_action( 'sam_payment_refund_failed', array( $this, 'release_gateway_refund' ), 10, 4 );

		// Manual Woo refunds do not call the gateway hooks. Reconcile them before
		// Woo sends its partial/full refund notification email.
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'reconcile_woo_refund' ), 1, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'reconcile_woo_refund' ), 1, 2 );
	}

	/**
	 * Objednávka zaplacená online bránou, u které musí jít refundace přes bránu.
	 * Seznam bran jde rozšířit filtrem sam_tickets_online_gateways.
	 */
	public static function is_online_gateway_order( WC_Order $order ) {
		$gateways = (array) apply_filters( 'sam_tickets_online_gateways', array( 'sam_stripe' ) );
		return in_array( $order->get_payment_method(), $gateways, true );
	}

	public function capture_refund_plan( $refund, $args ) {
		if ( ! $refund instanceof WC_Order_Refund || ! is_array( $args ) ) {
			return;
		}

		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : absint( $refund->get_parent_id() );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$ticket = $this->repository->find_by_order_id( $order_id );
		$is_ticket_order = $ticket || $order->get_meta( '_sam_ticket_id', true );
		if ( ! $is_ticket_order ) {
			return;
		}
		if ( ! $ticket ) {
			throw new RuntimeException( 'Refundaci nelze provést, protože objednávka nemá bezpečně evidovanou QR vstupenku.' );
		}
		if ( self::is_online_gateway_order( $order ) && empty( $args['refund_payment'] ) ) {
			throw new RuntimeException( 'U objednávky zaplacené online použijte tlačítko „Refundovat přes ' . $order->get_payment_method_title() . '“. Ruční refundace by nevrátila peníze zákazníkovi.' );
		}

		$requested = $this->requested_line_items( isset( $args['line_items'] ) && is_array( $args['line_items'] ) ? $args['line_items'] : array() );
		if ( ! $requested ) {
			throw new RuntimeException( 'U vstupenek je nutné vybrat konkrétní položky a jejich množství. Refundaci pouze částkou nelze bezpečně přiřadit ke QR kódu.' );
		}

		$plan = $this->repository->validate_refund_plan( $order_id, $requested );
		if ( is_wp_error( $plan ) ) {
			throw new RuntimeException( $plan->get_error_message() );
		}

		$operation_key = wp_generate_uuid4();
		$user_id       = get_current_user_id();
		$refund->update_meta_data( self::META_OPERATION, $operation_key );
		$refund->update_meta_data( self::META_PLAN, wp_json_encode( $plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$refund->update_meta_data( self::META_STATE, 'prepared' );
		$refund->update_meta_data( '_sam_ticket_refund_user_id', absint( $user_id ) );

		$this->current_context = array(
			'order_id'      => $order_id,
			'operation_key' => $operation_key,
			'plan'          => $plan,
			'refund'        => $refund,
			'amount'        => wc_format_decimal( isset( $args['amount'] ) ? $args['amount'] : $refund->get_amount() ),
			'user_id'       => absint( $user_id ),
		);
	}

	public function render_admin_refund_guidance( $order ) {
		if ( ! $order instanceof WC_Order || ! self::is_online_gateway_order( $order ) || ! $this->repository->find_by_order_id( $order->get_id() ) ) {
			return;
		}
		?>
		<style>.wc-order-refund-items .do-manual-refund{display:none!important}</style>
		<span class="description" style="margin-left:8px">U této objednávky refundujte vybrané položky pouze přes platební bránu (tlačítko Refundovat přes …).</span>
		<?php
	}

	public function provide_gateway_context( $context, $order_id, $amount, $reason = '' ) {
		if ( ! $this->context_matches( $order_id, $amount ) ) {
			return $context;
		}

		$refund = $this->current_context['refund'];
		if ( ! $refund instanceof WC_Order_Refund || ! $refund->get_id() ) {
			return $context;
		}

		return array(
			'refund_id'     => (int) $refund->get_id(),
			'operation_key' => (string) $this->current_context['operation_key'],
		);
	}

	public function reserve_ticket_quantities( $ready, $order_id, $refund_id, $operation_key, $amount, $reason = '' ) {
		if ( is_wp_error( $ready ) || true !== $ready ) {
			return $ready;
		}
		if ( ! $this->context_matches( $order_id, $amount ) || ! hash_equals( (string) $this->current_context['operation_key'], (string) $operation_key ) ) {
			return new WP_Error( 'sam_ticket_refund_context_missing', 'Refundace nemá platný plán položek vstupenky.' );
		}

		$result = $this->repository->reserve_refund(
			$order_id,
			$operation_key,
			$this->current_context['plan'],
			$this->current_context['user_id']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->update_refund_state( $refund_id, 'reserved' );
		return true;
	}

	public function commit_gateway_refund( $order_id, $refund_id, $operation_key, $amount, $audit_data ) {
		$user_id = $this->context_user_id( $refund_id );
		$result  = $this->repository->commit_refund( $order_id, $operation_key, (string) $refund_id, $user_id );
		if ( is_wp_error( $result ) ) {
			$this->mark_reconciliation_required( $order_id, $refund_id, $result->get_error_message() );
			throw new RuntimeException( $result->get_error_message() );
		}

		$refund = wc_get_order( $refund_id );
		if ( $refund instanceof WC_Order_Refund ) {
			$refund->update_meta_data( self::META_STATE, 'committed' );
			$refund->update_meta_data( self::META_GATEWAY_AUDIT, wp_json_encode( is_array( $audit_data ) ? $audit_data : array() ) );
			$refund->save_meta_data();
		}

		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( sprintf( 'Platební brána přijala refundaci #%d; odpovídající nevyužité vstupy byly zneplatněny.', absint( $refund_id ) ) );
		}
	}

	public function release_gateway_refund( $order_id, $refund_id, $operation_key, $error_code ) {
		$error_code = sanitize_key( (string) $error_code );
		if ( 'indeterminate' === $error_code ) {
			$this->update_refund_state( $refund_id, 'unknown' );
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->add_order_note( 'Výsledek refundace u platební brány je nejasný. Rezervované vstupy zůstávají do ručního ověření nepoužitelné.' );
			}
			return;
		}

		$result = $this->repository->release_refund( $order_id, $operation_key, $error_code, $this->context_user_id( $refund_id ) );
		if ( is_wp_error( $result ) && 'sam_ticket_refund_not_reserved' === $result->get_error_code() ) {
			// The gateway failed before quantities were reserved (configuration,
			// preflight, context). No money moved and nothing needs releasing.
			$this->update_refund_state( $refund_id, 'released' );
			return;
		}
		$this->update_refund_state( $refund_id, is_wp_error( $result ) ? 'release_failed' : 'released' );
		if ( is_wp_error( $result ) ) {
			$this->mark_reconciliation_required( $order_id, $refund_id, $result->get_error_message() );
		}
	}

	public function reconcile_woo_refund( $order_id, $refund_id ) {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof WC_Order_Refund ) {
			return;
		}

		$state = (string) $refund->get_meta( self::META_STATE, true );
		if ( in_array( $state, array( 'committed', 'unknown', 'reconciliation_required' ), true ) ) {
			return;
		}

		$operation_key = (string) $refund->get_meta( self::META_OPERATION, true );
		$plan          = json_decode( (string) $refund->get_meta( self::META_PLAN, true ), true );
		if ( ! $operation_key || ! is_array( $plan ) ) {
			return;
		}

		$user_id = absint( $refund->get_meta( '_sam_ticket_refund_user_id', true ) );
		if ( 'reserved' !== $state ) {
			$reserved = $this->repository->reserve_refund( $order_id, $operation_key, $plan, $user_id );
			if ( is_wp_error( $reserved ) ) {
				$this->fail_safe_manual_refund( $order_id, $refund_id, $reserved->get_error_message() );
				return;
			}
		}

		$result = $this->repository->commit_refund( $order_id, $operation_key, (string) $refund_id, $user_id );
		if ( is_wp_error( $result ) ) {
			$this->fail_safe_manual_refund( $order_id, $refund_id, $result->get_error_message() );
			return;
		}

		$this->update_refund_state( $refund_id, 'committed' );
	}

	private function requested_line_items( array $line_items ) {
		$requested = array();
		foreach ( $line_items as $order_item_id => $entry ) {
			$quantity = isset( $entry['qty'] ) ? $entry['qty'] : 0;
			if ( ! is_numeric( $quantity ) || (float) $quantity <= 0 || floor( (float) $quantity ) !== (float) $quantity ) {
				continue;
			}
			$requested[ absint( $order_item_id ) ] = (int) $quantity;
		}
		return array_filter( $requested );
	}

	private function context_matches( $order_id, $amount ) {
		return $this->current_context
			&& absint( $order_id ) === (int) $this->current_context['order_id']
			&& wc_format_decimal( $amount ) === (string) $this->current_context['amount'];
	}

	private function context_user_id( $refund_id ) {
		if ( $this->current_context && isset( $this->current_context['user_id'] ) ) {
			return absint( $this->current_context['user_id'] );
		}
		$refund = wc_get_order( $refund_id );
		return $refund instanceof WC_Order_Refund ? absint( $refund->get_meta( '_sam_ticket_refund_user_id', true ) ) : 0;
	}

	private function update_refund_state( $refund_id, $state ) {
		$refund = wc_get_order( $refund_id );
		if ( $refund instanceof WC_Order_Refund ) {
			$refund->update_meta_data( self::META_STATE, sanitize_key( $state ) );
			$refund->save_meta_data();
		}
	}

	private function mark_reconciliation_required( $order_id, $refund_id, $message ) {
		$this->update_refund_state( $refund_id, 'reconciliation_required' );
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( 'KRITICKÉ: Platba mohla být refundována, ale stav QR vstupenky vyžaduje ruční kontrolu. ' . sanitize_text_field( $message ) );
		}
		error_log( 'SAM ticket refund reconciliation required for order ' . absint( $order_id ) . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private function fail_safe_manual_refund( $order_id, $refund_id, $message ) {
		$this->repository->set_status_by_order( $order_id, 'void', 'refund_reconciliation_required' );
		$this->mark_reconciliation_required( $order_id, $refund_id, $message );
	}
}
