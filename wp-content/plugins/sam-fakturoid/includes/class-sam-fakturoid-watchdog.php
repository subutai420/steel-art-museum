<?php

defined( 'ABSPATH' ) || exit;

/**
 * Hlídání, že každá zaplacená objednávka má fakturu (a refundace opravný doklad).
 *
 * Denně v 7:00 projde objednávky zaplacené za posledních 60 dní (nejdřív od spuštění nového webu),
 * starší než 6 hodin (to už proběhly všechny automatické pokusy 5–15–60–180 min).
 * Když ve Fakturoidu doklad chybí:
 * - naplánuje nový pokus o vystavení,
 * - pošle upozornění na adresy reportů (Vstupenky → Nastavení a reporty).
 * Ručně: wp sam-fakturoid audit [--since=2026-01-01] [--send]
 */
final class SAM_Fakturoid_Watchdog {
	const HOOK       = 'sam_fakturoid_watchdog';
	const START      = '2026-11-24 06:30:00'; // spuštění nového webu (UTC); starší objednávky fakturoval plugin Toret
	const DAYS       = 60;
	const GRACE_SECS = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$next = new DateTimeImmutable( 'today 07:00', wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
	}

	public static function run() {
		$found = self::find();
		if ( $found['invoices'] || $found['corrections'] ) {
			self::retry( $found );
			self::notify( $found );
		}
		update_option( 'sam_fakturoid_watchdog_last', array( 'time' => current_time( 'mysql' ), 'invoices' => count( $found['invoices'] ), 'corrections' => count( $found['corrections'] ) ), false );
		return $found;
	}

	/**
	 * @param string $since Datum (UTC), od kdy hledat; výchozí posledních 60 dní, nejdřív od spuštění webu.
	 * @return array{invoices: WC_Order[], corrections: WC_Order_Refund[]}
	 */
	public static function find( $since = '' ) {
		$service = SAM_Fakturoid_Service::instance();
		if ( ! $since ) {
			$since = max( self::START, gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS ) );
		}
		$until = gmdate( 'Y-m-d H:i:s', time() - self::GRACE_SECS );

		$missing = array();
		$orders  = wc_get_orders(
			array(
				'type'      => 'shop_order',
				'status'    => array( 'wc-processing', 'wc-completed', 'wc-refunded' ),
				'date_paid' => strtotime( $since . ' UTC' ) . '...' . strtotime( $until . ' UTC' ),
				'limit'     => -1,
			)
		);
		foreach ( $orders as $order ) {
			if ( $order->get_meta( SAM_Fakturoid_Service::META_ID ) || $order->get_meta( SAM_Fakturoid_Service::LEGACY_META_ID ) ) {
				continue;
			}
			// Plně refundovaná objednávka bez faktury: is_paid() je false, ale zaplacená byla → faktura měla vzniknout.
			$reason = $service->skip_reason( $order );
			if ( '' !== $reason && 'nezaplaceno' !== $reason ) {
				continue;
			}
			if ( 'nezaplaceno' === $reason && 'refunded' !== $order->get_status() ) {
				continue;
			}
			$missing[] = $order;
		}

		$corrections = array();
		$refunds     = wc_get_orders(
			array(
				'type'         => 'shop_order_refund',
				'date_created' => strtotime( $since . ' UTC' ) . '...' . strtotime( $until . ' UTC' ),
				'limit'        => -1,
			)
		);
		foreach ( $refunds as $refund ) {
			if ( $refund->get_meta( SAM_Fakturoid_Service::META_ID ) || (float) $refund->get_amount() <= 0 ) {
				continue;
			}
			$parent = wc_get_order( $refund->get_parent_id() );
			if ( $parent && ( $parent->get_meta( SAM_Fakturoid_Service::META_ID ) || $parent->get_meta( SAM_Fakturoid_Service::LEGACY_META_ID ) ) ) {
				$corrections[] = $refund;
			}
		}
		return array( 'invoices' => $missing, 'corrections' => $corrections );
	}

	/** Nový pokus pro chybějící faktury (opravné doklady jen ručně – potřebují kontrolu částky). */
	private static function retry( array $found ) {
		foreach ( $found['invoices'] as $order ) {
			$args = array( (int) $order->get_id(), 0 );
			if ( ! wp_next_scheduled( SAM_Fakturoid_Service::CREATE_HOOK, $args ) ) {
				wp_schedule_single_event( time() + 60, SAM_Fakturoid_Service::CREATE_HOOK, $args );
			}
		}
	}

	public static function notify( array $found ) {
		$recipients = class_exists( 'SAM_Tickets_Settings' ) ? SAM_Tickets_Settings::report_recipients() : array( get_option( 'admin_email' ) );
		$n          = count( $found['invoices'] ) + count( $found['corrections'] );
		$subject    = sprintf( 'Steel Art Museum – chybí %d %s ve Fakturoidu', $n, 1 === $n ? 'doklad' : ( $n < 5 ? 'doklady' : 'dokladů' ) );

		$td   = 'style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left"';
		$html = '<p>Kontrola našla zaplacené objednávky nebo refundace, ke kterým ve Fakturoidu nevznikl doklad ani po automatických pokusech.</p>';
		if ( $found['invoices'] ) {
			$html .= '<h3>Chybí faktura (' . count( $found['invoices'] ) . ')</h3><p>Nový pokus o vystavení je naplánovaný. Když ani ten neprojde, vystavte fakturu ručně tlačítkem v boxu Fakturoid u objednávky.</p>';
			$html .= '<table cellspacing="0" style="border-collapse:collapse;width:100%"><tr><th ' . $td . '>Objednávka</th><th ' . $td . '>Zaplaceno</th><th ' . $td . '>Částka</th><th ' . $td . '>Chyba</th></tr>';
			foreach ( $found['invoices'] as $order ) {
				$paid  = $order->get_date_paid();
				$html .= '<tr><td ' . $td . '><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>'
					. '<td ' . $td . '>' . esc_html( $paid ? $paid->date_i18n( 'j. n. Y H:i' ) : '' ) . '</td>'
					. '<td ' . $td . '>' . esc_html( number_format_i18n( (float) $order->get_total(), 2 ) . ' ' . $order->get_currency() ) . '</td>'
					. '<td ' . $td . '>' . esc_html( (string) $order->get_meta( SAM_Fakturoid_Service::META_ERROR ) ?: '—' ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		if ( $found['corrections'] ) {
			$html .= '<h3>Chybí opravný doklad k refundaci (' . count( $found['corrections'] ) . ')</h3><table cellspacing="0" style="border-collapse:collapse;width:100%"><tr><th ' . $td . '>Objednávka</th><th ' . $td . '>Refundace</th><th ' . $td . '>Částka</th><th ' . $td . '>Chyba</th></tr>';
			foreach ( $found['corrections'] as $refund ) {
				$parent = wc_get_order( $refund->get_parent_id() );
				$html  .= '<tr><td ' . $td . '>' . ( $parent ? '<a href="' . esc_url( $parent->get_edit_order_url() ) . '">#' . esc_html( $parent->get_order_number() ) . '</a>' : '' ) . '</td>'
					. '<td ' . $td . '>' . esc_html( $refund->get_date_created() ? $refund->get_date_created()->date_i18n( 'j. n. Y H:i' ) : '' ) . '</td>'
					. '<td ' . $td . '>−' . esc_html( number_format_i18n( (float) $refund->get_amount(), 2 ) . ' ' . $refund->get_currency() ) . '</td>'
					. '<td ' . $td . '>' . esc_html( (string) $refund->get_meta( SAM_Fakturoid_Service::META_ERROR ) ?: '—' ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		$html .= '<p style="color:#666;font-size:12px">Automatická denní kontrola z webu steelartmuseum.eu. Adresy pro upozornění: Vstupenky → Nastavení a reporty.</p>';

		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		$body   = $mailer ? $mailer->wrap_message( $subject, $html ) : $html;
		return wp_mail( $recipients, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
