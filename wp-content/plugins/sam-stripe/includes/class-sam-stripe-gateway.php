<?php

defined( 'ABSPATH' ) || exit;

/**
 * Platební brána Stripe: Payment Element (karta, Apple Pay, Google Pay, případně Link).
 *
 * Tok platby (klasická pokladna WooCommerce):
 * 1. Prvek se vykreslí v sekci platby pod fakturačními údaji (deferred intent – bez PaymentIntentu).
 * 2. Odeslání objednávky: JS zavolá elements.submit() (validace, u peněženek se otevře
 *    Apple/Google Pay), WooCommerce vytvoří objednávku a process_payment() k ní vytvoří
 *    PaymentIntent. Vrátí „přesměrování“ na #sam-stripe-confirm=…, JS pak zavolá
 *    stripe.confirmPayment() a Stripe zákazníka vrátí na děkovací stránku.
 * 3. Na děkovací stránce se stav PaymentIntentu ověří přímo u Stripe; webhook je záloha
 *    pro případ, že zákazník zavře okno (SAM_Stripe_Webhook).
 */
class SAM_Stripe_Gateway extends WC_Payment_Gateway {
	const ID          = 'sam_stripe';
	const META_INTENT = '_sam_stripe_intent';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'Stripe – karta, Apple Pay, Google Pay';
		$this->method_description = 'Platby přes Stripe Payment Element. Klíče se nastavují v prostředí serveru (SAM_STRIPE_SECRET_KEY, SAM_STRIPE_PUBLISHABLE_KEY, SAM_STRIPE_WEBHOOK_SECRET), ne zde.';
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title', 'Platba kartou / Apple Pay / Google Pay' );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
	}

	public static function register_static_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_return' ), 5 );
	}

	public function init_form_fields() {
		$status = SAM_Stripe_Config::is_configured()
			? ( SAM_Stripe_Config::is_test_mode() ? 'TESTOVACÍ režim (klíče sk_test/pk_test).' : 'OSTRÝ režim.' ) . ( SAM_Stripe_Config::keys_match() ? '' : ' POZOR: tajný a veřejný klíč nejsou ze stejného režimu!' ) . ( SAM_Stripe_Config::webhook_secret() ? ' Webhook nastaven.' : ' Chybí SAM_STRIPE_WEBHOOK_SECRET.' )
			: 'Klíče nejsou nastavené – platba se zákazníkům nezobrazí.';

		$this->form_fields = array(
			'enabled'     => array(
				'title'       => 'Zapnout',
				'type'        => 'checkbox',
				'label'       => 'Přijímat platby přes Stripe',
				'default'     => 'no',
				'description' => $status,
			),
			'title'       => array(
				'title'   => 'Název na pokladně',
				'type'    => 'text',
				'default' => 'Platba kartou / Apple Pay / Google Pay',
			),
			'description' => array(
				'title'   => 'Popis na pokladně',
				'type'    => 'textarea',
				'default' => '',
			),
		);
	}

	public function is_available() {
		return parent::is_available() && SAM_Stripe_Config::is_configured() && SAM_Stripe_Config::keys_match();
	}

	/* ---------- Pokladna ---------- */

	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		$total    = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
		$currency = get_woocommerce_currency();
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( $order ) {
				$total    = (float) $order->get_total();
				$currency = $order->get_currency();
			}
		}
		printf(
			'<div id="sam-stripe-element" data-amount="%d" data-currency="%s" style="min-height:56px"></div><div id="sam-stripe-errors" role="alert" style="margin-top:10px;color:#b32d2e"></div>',
			(int) SAM_Stripe_Config::to_minor( $total, $currency ),
			esc_attr( strtolower( $currency ) )
		);
	}

	public function payment_scripts() {
		if ( ! $this->is_available() || ! ( is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) || is_order_received_page() ) {
			return;
		}
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'sam-stripe-checkout', plugins_url( 'assets/js/sam-stripe-checkout.js', SAM_STRIPE_FILE ), array( 'jquery', 'stripe-js', 'wc-checkout' ), SAM_STRIPE_VERSION, true );
		$language = apply_filters( 'wpml_current_language', null );
		wp_localize_script(
			'sam-stripe-checkout',
			'samStripe',
			array(
				'publishableKey' => SAM_Stripe_Config::publishable_key(),
				'locale'         => 0 === strpos( (string) $language, 'en' ) ? 'en' : 'cs',
				'appearance'     => array(
					'theme'     => 'stripe',
					'variables' => array(
						'colorPrimary'    => '#0F1012',
						'colorText'       => '#0F1012',
						'colorDanger'     => '#b32d2e',
						'fontFamily'      => 'system-ui, -apple-system, "Segoe UI", sans-serif',
						'borderRadius'    => '4px',
						'spacingUnit'     => '4px',
					),
				),
				'i18n'           => array(
					'generic' => 0 === strpos( (string) $language, 'en' ) ? 'The payment could not be completed. Please try again.' : 'Platbu se nepodařilo dokončit. Zkuste to prosím znovu.',
				),
			)
		);
	}

	/* ---------- Platba ---------- */

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( $this->t( 'Objednávka nebyla nalezena.', 'Order not found.' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$intent = $this->intent_for_order( $order );
		if ( is_wp_error( $intent ) ) {
			wc_get_logger()->error( 'PaymentIntent pro objednávku #' . $order_id . ' se nepodařilo připravit: ' . $intent->get_error_message(), array( 'source' => 'sam-stripe' ) );
			wc_add_notice( $this->t( 'Platbu se nepodařilo připravit. Zkuste to prosím znovu.', 'The payment could not be prepared. Please try again.' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'succeeded' === $intent['status'] ) {
			self::complete_from_intent( $order, $intent );
			return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
		}

		$return_url = add_query_arg( 'sam_stripe_return', '1', $this->get_return_url( $order ) );
		$payload    = base64_encode( wp_json_encode( array( 'cs' => $intent['client_secret'], 'ret' => $return_url, 'email' => $order->get_billing_email() ) ) );
		return array(
			'result'   => 'success',
			'redirect' => '#sam-stripe-confirm=' . rawurlencode( $payload ),
		);
	}

	/**
	 * PaymentIntent k objednávce: existující znovu použije (opakovaný pokus po zamítnuté
	 * kartě), u změněné částky ho aktualizuje, jinak vytvoří nový.
	 *
	 * @return array|WP_Error
	 */
	private function intent_for_order( WC_Order $order ) {
		$client   = new SAM_Stripe_Client();
		$amount   = SAM_Stripe_Config::to_minor( $order->get_total(), $order->get_currency() );
		$currency = strtolower( $order->get_currency() );
		$existing = (string) $order->get_meta( self::META_INTENT, true );

		if ( $existing ) {
			$intent = $client->get( 'payment_intents/' . rawurlencode( $existing ) );
			if ( ! is_wp_error( $intent ) ) {
				$reusable = in_array( $intent['status'], array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true );
				if ( 'succeeded' === $intent['status'] || 'processing' === $intent['status'] ) {
					return $intent;
				}
				if ( $reusable && (int) $intent['amount'] === $amount && $intent['currency'] === $currency ) {
					return $intent;
				}
				if ( $reusable ) {
					return $client->post( 'payment_intents/' . rawurlencode( $existing ), array( 'amount' => $amount, 'currency' => $currency ) );
				}
			}
		}

		$attempt = absint( $order->get_meta( '_sam_stripe_attempt', true ) ) + 1;
		$intent  = $client->post(
			'payment_intents',
			array(
				'amount'                    => $amount,
				'currency'                  => $currency,
				'automatic_payment_methods' => array( 'enabled' => true ),
				'description'               => sprintf( 'Steel Art Museum – objednávka #%s', $order->get_order_number() ),
				'metadata'                  => array(
					'order_id'  => $order->get_id(),
					'order_key' => $order->get_order_key(),
					'site'      => wp_parse_url( home_url(), PHP_URL_HOST ),
				),
			),
			'sam-pi-' . $order->get_id() . '-' . $attempt . '-' . $amount . $currency
		);
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		$order->update_meta_data( self::META_INTENT, $intent['id'] );
		$order->update_meta_data( '_sam_stripe_attempt', $attempt );
		$order->update_meta_data( '_sam_stripe_mode', SAM_Stripe_Config::is_test_mode() ? 'test' : 'live' );
		$order->set_transaction_id( $intent['id'] );
		$order->save();
		return $intent;
	}

	/**
	 * Návrat ze Stripe na děkovací stránku: ověří PaymentIntent u Stripe. Při neúspěchu
	 * vrátí zákazníka na pokladnu (objednávka zůstává nezaplacená a jde zaplatit znovu).
	 */
	public static function handle_return() {
		if ( empty( $_GET['sam_stripe_return'] ) || ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) $key ) || self::ID !== $order->get_payment_method() ) {
			return;
		}
		$intent_id = (string) $order->get_meta( self::META_INTENT, true );
		$returned  = isset( $_GET['payment_intent'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) ) : '';
		if ( ! $intent_id || ( $returned && ! hash_equals( $intent_id, $returned ) ) ) {
			return;
		}

		if ( $order->is_paid() ) {
			return;
		}

		$intent = ( new SAM_Stripe_Client() )->get( 'payment_intents/' . rawurlencode( $intent_id ), array( 'expand' => array( 'latest_charge' ) ) );
		if ( is_wp_error( $intent ) ) {
			$order->add_order_note( 'Návrat ze Stripe: stav platby se nepodařilo ověřit (' . $intent->get_error_message() . '). Rozhodne webhook.' );
			return;
		}

		if ( 'succeeded' === $intent['status'] ) {
			self::complete_from_intent( $order, $intent );
			return;
		}
		if ( 'processing' === $intent['status'] ) {
			$order->update_status( 'on-hold', 'Stripe platbu zpracovává, potvrzení přijde webhookem.' );
			return;
		}

		// requires_payment_method / canceled: platba neprošla (zamítnutá karta, zrušená peněženka).
		$order->add_order_note( 'Platba přes Stripe neprošla (stav ' . sanitize_text_field( $intent['status'] ) . '). Zákazník byl vrácen na pokladnu.' );
		$english = 'en' === (string) $order->get_meta( '_sam_language', true );
		wc_add_notice( $english ? 'The payment did not go through. Please try again or use another card.' : 'Platba neprošla. Zkuste to prosím znovu nebo jinou kartou.', 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Označí objednávku jako zaplacenou podle úspěšného PaymentIntentu (idempotentně –
	 * volá se z návratu i z webhooku).
	 */
	public static function complete_from_intent( WC_Order $order, array $intent ) {
		$order = wc_get_order( $order->get_id() ); // čerstvý stav (souběh s webhookem)
		if ( ! $order || $order->is_paid() ) {
			return false;
		}
		if ( (string) $order->get_meta( self::META_INTENT, true ) !== (string) $intent['id'] ) {
			return false;
		}
		if ( (int) $intent['amount_received'] !== SAM_Stripe_Config::to_minor( $order->get_total(), $order->get_currency() ) || strtoupper( (string) $intent['currency'] ) !== strtoupper( $order->get_currency() ) ) {
			$order->update_status( 'on-hold', sprintf( 'Stripe přijal %s %s, ale objednávka je na %s %s. Zkontrolujte platbu ručně.', number_format( $intent['amount_received'] / 100, 2, ',', ' ' ), strtoupper( $intent['currency'] ), $order->get_total(), $order->get_currency() ) );
			return false;
		}

		$charge = $intent['latest_charge'] ?? '';
		$method = '';
		if ( is_array( $charge ) ) {
			$details = $charge['payment_method_details'] ?? array();
			$method  = (string) ( $details['card']['wallet']['type'] ?? $details['type'] ?? '' );
			$order->update_meta_data( '_sam_stripe_charge', (string) $charge['id'] );
			if ( ! empty( $details['card']['last4'] ) ) {
				$order->update_meta_data( '_sam_stripe_card', strtoupper( (string) ( $details['card']['brand'] ?? '' ) ) . ' •••• ' . $details['card']['last4'] );
			}
		}
		$labels = array( 'card' => 'karta', 'apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay', 'link' => 'Link' );
		if ( $method ) {
			$order->update_meta_data( '_sam_stripe_method', $method );
		}
		$order->save();
		$order->payment_complete( (string) $intent['id'] );
		// payment_complete() vrací název platby na název brány – konkrétní způsob až po něm.
		if ( $method ) {
			$order->set_payment_method_title( 'Stripe – ' . ( $labels[ $method ] ?? $method ) );
			$order->save();
		}
		$order->add_order_note( sprintf( 'Platba přes Stripe přijata (%s, %s%s).', $labels[ $method ] ?? ( $method ? $method : 'Stripe' ), $intent['id'], SAM_Stripe_Config::is_test_mode() ? ', TESTOVACÍ režim' : '' ) );
		return true;
	}

	/* ---------- Refundace ---------- */

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return ( new SAM_Stripe_Refunds() )->process( $this, $order_id, $amount, $reason );
	}

	public function can_refund_order( $order ) {
		return $order instanceof WC_Order && $order->get_transaction_id() && SAM_Stripe_Config::is_configured();
	}

	private function t( $cs, $en ) {
		return 0 === strpos( (string) apply_filters( 'wpml_current_language', null ), 'en' ) ? $en : $cs;
	}
}
