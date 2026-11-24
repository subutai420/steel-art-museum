<?php

defined( 'ABSPATH' ) || exit;

/**
 * Neúspěšná platba za vstupenky.
 *
 * Převzato z MoB: nezaplacená objednávka se nemaže ani neupomíná.
 * Dostane stav „Selhalo“ a zákazník dostane jediný e-mail, že platba
 * neproběhla a ať si vstupenky koupí znovu.
 *
 * - Platba zamítnutá bránou: brána Stripe (sam-stripe) nastaví „failed“.
 * - Platba nedokončená (zákazník zavřel bránu): po PENDING_TIMEOUT se
 *   objednávka sama přepne na „failed“, místo aby ji WooCommerce zrušil.
 * - E-mail odchází se zpožděním EMAIL_DELAY, aby nepřišel zákazníkovi,
 *   který platbu hned zopakoval a zaplatil. Jde nejvýš jednou.
 */
final class SAM_Tickets_Failed_Payment {
	const EMAIL_HOOK      = 'sam_tickets_failed_payment_email';
	const SWEEP_HOOK      = 'sam_tickets_fail_abandoned_orders';
	const SENT_META       = '_sam_failed_payment_email_sent';
	const EMAIL_DELAY     = 15 * MINUTE_IN_SECONDS;
	const PENDING_TIMEOUT = 2 * HOUR_IN_SECONDS;
	// Starší objednávky se nepřepínají ani neupomínají – hlavně ty, které
	// zůstaly z doby před nasazením (jinak by po nasazení odešly e-maily
	// k objednávkám starým týdny).
	const MAX_ORDER_AGE   = 2 * DAY_IN_SECONDS;
	const SWEEP_BATCH     = 50;

	public function register_hooks() {
		add_action( 'woocommerce_order_status_failed', array( $this, 'schedule_email' ), 30, 2 );
		add_action( self::EMAIL_HOOK, array( $this, 'send_email' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'fail_abandoned_orders' ) );
		add_action( 'init', array( $this, 'ensure_sweep_scheduled' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( $this, 'fail_instead_of_cancel' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_failed_order', array( $this, 'disable_core_customer_failed_email' ), 10, 2 );
	}

	public static function is_ticket_order( $order ) {
		// _sam_language ukládá pokladna u každé objednávky vstupenek (i nezaplacené).
		return $order instanceof WC_Order && ( $order->get_meta( '_sam_ticket_id', true ) || $order->get_meta( '_sam_language', true ) );
	}

	public function schedule_email( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! self::is_ticket_order( $order ) || $order->get_meta( self::SENT_META, true ) || ! self::is_recent( $order ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::EMAIL_HOOK, array( (int) $order->get_id() ) ) ) {
			wp_schedule_single_event( time() + self::EMAIL_DELAY, self::EMAIL_HOOK, array( (int) $order->get_id() ) );
		}
	}

	public function send_email( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! self::is_ticket_order( $order ) || 'failed' !== $order->get_status() || $order->is_paid() || $order->get_meta( self::SENT_META, true ) || ! self::is_recent( $order ) ) {
			return;
		}
		// Než zákazníkovi napíšeme „peníze neodešly“, ověříme to ve Stripe (a platbu zrušíme, aby nešla dokončit později).
		$state = self::stripe_state( $order );
		if ( 'unknown' === $state || 'processing' === $state ) {
			if ( ! wp_next_scheduled( self::EMAIL_HOOK, array( (int) $order->get_id() ) ) ) {
				wp_schedule_single_event( time() + self::EMAIL_DELAY, self::EMAIL_HOOK, array( (int) $order->get_id() ) );
			}
			return;
		}
		if ( 'paid' === $state ) {
			return;
		}
		$recipient = $order->get_billing_email();
		if ( ! is_email( $recipient ) ) {
			return;
		}

		// Značka se ukládá před odesláním: raději žádný e-mail než dva.
		$order->update_meta_data( self::SENT_META, current_time( 'mysql' ) );
		$order->save_meta_data();

		$copy    = self::copy( $order );
		$mailer  = WC()->mailer();
		$subject = sprintf( $copy['subject'], $order->get_order_number() );
		$body    = $mailer->wrap_message( $copy['heading'], self::render_body( $order, $copy ) );
		$sent    = $mailer->send( $recipient, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		$order->add_order_note( $sent ? 'Zákazníkovi odešel e-mail „Platba neproběhla“.' : 'E-mail „Platba neproběhla“ se nepodařilo odeslat.' );
	}

	/**
	 * Objednávky placené přes Stripe, které visí v „Čeká na platbu“ déle než
	 * PENDING_TIMEOUT, přepne na „Selhalo“ (spustí i e-mail).
	 */
	public function fail_abandoned_orders() {
		$orders = wc_get_orders(
			array(
				'status'         => array( 'pending' ),
				'payment_method' => 'sam_stripe',
				'date_created'   => ( time() - self::MAX_ORDER_AGE ) . '...' . ( time() - self::PENDING_TIMEOUT ),
				'limit'          => self::SWEEP_BATCH,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		foreach ( $orders as $order ) {
			$order = wc_get_order( $order->get_id() ); // čerstvý stav, webhook mohl mezitím zaplatit
			if ( ! $order || 'pending' !== $order->get_status() || ! self::is_ticket_order( $order ) || $order->is_paid() ) {
				continue;
			}
			if ( 'unpaid' !== self::stripe_state( $order ) ) {
				continue; // zaplaceno (dokončeno), zpracovává se, nebo Stripe neodpověděl – zkusí další běh
			}
			$order->update_status( 'failed', 'Platba nebyla dokončena do 2 hodin (ověřeno ve Stripe, platba zrušena), objednávka je označena jako neúspěšná.' );
		}
	}

	/**
	 * Zeptá se Stripe, jak platba skutečně dopadla (webhook nemusel dorazit).
	 * Zaplacenou objednávku brána rovnou dokončí, nezaplacenou platbu zruší.
	 *
	 * @return string paid | processing | unpaid | unknown
	 */
	private static function stripe_state( WC_Order $order ) {
		if ( 'sam_stripe' !== $order->get_payment_method() || ! is_callable( array( 'SAM_Stripe_Gateway', 'settle_pending' ) ) ) {
			return 'unpaid';
		}
		$state = SAM_Stripe_Gateway::settle_pending( $order );
		if ( 'paid' === $state ) {
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh ) {
				$fresh->add_order_note( 'Platba byla ve Stripe úspěšná, ale web se to nedozvěděl (webhook nedorazil). Objednávka dokončena při kontrole nedokončených plateb.' );
			}
		}
		return $state;
	}

	private static function is_recent( WC_Order $order ) {
		$created = $order->get_date_created();
		return $created && $created->getTimestamp() >= time() - self::MAX_ORDER_AGE;
	}

	public function ensure_sweep_scheduled() {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
	}

	/**
	 * WooCommerce (při „Držet zásoby“) ruší nezaplacené objednávky. Vstupenkové
	 * objednávky placené přes Stripe místo zrušení označíme jako neúspěšné.
	 */
	public function fail_instead_of_cancel( $cancel, $order ) {
		if ( $cancel && self::is_ticket_order( $order ) && 'sam_stripe' === $order->get_payment_method() ) {
			if ( 'unpaid' !== self::stripe_state( $order ) ) {
				return false;
			}
			$order->update_status( 'failed', 'Platba nebyla dokončena v časovém limitu, objednávka je označena jako neúspěšná.' );
			return false;
		}
		return $cancel;
	}

	/**
	 * Novější WooCommerce má vlastní e-mail zákazníkovi při neúspěšné objednávce.
	 * U vstupenek posíláme jen náš, aby nepřišly dva.
	 */
	public function disable_core_customer_failed_email( $enabled, $order ) {
		return self::is_ticket_order( $order ) ? false : $enabled;
	}

	private static function render_body( WC_Order $order, array $copy ) {
		$name    = trim( (string) $order->get_billing_first_name() );
		$url     = self::tickets_url( $order );
		$button  = 'display:inline-block;padding:14px 26px;border-radius:999px;background:#E6BC62;color:#0F1012;font-weight:700;text-decoration:none;';
		$html    = '<p>' . esc_html( sprintf( $copy['greeting'], $name ? ' ' . $name : '' ) ) . '</p>';
		$html   .= '<p>' . esc_html( sprintf( $copy['intro'], $order->get_order_number() ) ) . '</p>';
		$html   .= '<p>' . esc_html( $copy['retry'] ) . '</p>';
		$html   .= '<p><a href="' . esc_url( $url ) . '" style="' . esc_attr( $button ) . '">' . esc_html( $copy['button'] ) . '</a></p>';
		$html   .= '<p>' . esc_html( $copy['charged'] ) . '</p>';
		$html   .= '<p>' . esc_html( $copy['closing'] ) . '</p>';
		return $html;
	}

	/** Kam poslat zákazníka znovu koupit: kalkulačka na úvodní stránce v jeho jazyce. */
	private static function tickets_url( WC_Order $order ) {
		$language = self::language( $order );
		$home     = apply_filters( 'wpml_home_url', home_url( '/' ) );
		if ( 'en' === $language ) {
			$home = apply_filters( 'wpml_permalink', home_url( '/' ), 'en' );
		}
		return trailingslashit( $home ) . '#vstupenky';
	}

	private static function language( WC_Order $order ) {
		$language = strtolower( sanitize_key( (string) $order->get_meta( '_sam_language', true ) ) );
		return 0 === strpos( $language, 'en' ) ? 'en' : 'cs';
	}

	public static function copy( WC_Order $order ) {
		$all = array(
			'cs' => array(
				'subject'  => 'Platba neproběhla – objednávka #%s',
				'heading'  => 'Platba neproběhla',
				'greeting' => 'Dobrý den%s,',
				'intro'    => 'platba za objednávku vstupenek č. %s neproběhla, takže vstupenky nevznikly.',
				'retry'    => 'Pokud se k nám chystáte, kupte si prosím vstupenky znovu. Zabere to jen chvilku.',
				'button'   => 'Koupit vstupenky',
				'charged'  => 'Peníze vám z účtu neodešly. Kdyby se přesto částka na účtu objevila, napište nám na info@steelartmuseum.eu a vyřešíme to.',
				'closing'  => 'Těšíme se na vás ve Steel Art Museum.',
			),
			'en' => array(
				'subject'  => 'Payment not completed – order #%s',
				'heading'  => 'Payment not completed',
				'greeting' => 'Hello%s,',
				'intro'    => 'the payment for ticket order no. %s was not completed, so no tickets were issued.',
				'retry'    => 'If you are planning a visit, please buy your tickets again. It only takes a moment.',
				'button'   => 'Buy tickets',
				'charged'  => 'No money has left your account. If the amount still appears on your statement, write to us at info@steelartmuseum.eu and we will sort it out.',
				'closing'  => 'We look forward to seeing you at Steel Art Museum.',
			),
		);
		return $all[ self::language( $order ) ];
	}
}
