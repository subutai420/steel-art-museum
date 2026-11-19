<?php

defined( 'ABSPATH' ) || exit;

/**
 * Napojení objednávek na Fakturoid.
 *
 * - Zaplacená objednávka (processing/completed) → faktura, označená jako uhrazená.
 *   Se „Chci fakturu“ hned (odkaz musí být v e-mailu se vstupenkou), jinak na pozadí přes cron.
 * - Bez „Chci fakturu“ jde faktura na společného odběratele „Koncový zákazník“.
 * - Refundace → opravný doklad k faktuře (i ke staré faktuře z pluginu Toret).
 * - Chyba API → poznámka v objednávce a nový pokus (5, 15, 60, 180 min).
 *   Když faktura se „Chci fakturu“ vznikne až po odeslání vstupenky, zákazník dostane odkaz zvlášť.
 * - Bez faktury: ruční vstupenky (_sam_no_invoice), nulové objednávky, objednávky s fakturou ze starého pluginu.
 */
final class SAM_Fakturoid_Service {
	const CREATE_HOOK     = 'sam_fakturoid_create';
	const CORRECTION_HOOK = 'sam_fakturoid_correction';
	const RETRY_MINUTES   = array( 5, 15, 60, 180 );
	const RETAIL_OPTION   = 'sam_fakturoid_retail_subject';

	const META_ID         = '_sam_fakturoid_id';
	const META_NUMBER     = '_sam_fakturoid_number';
	const META_URL        = '_sam_fakturoid_url';
	const META_ADMIN_URL  = '_sam_fakturoid_admin_url';
	const META_SUBJECT    = '_sam_fakturoid_subject_id';
	const META_ERROR      = '_sam_fakturoid_error';
	const META_LINK_SENT  = '_sam_fakturoid_link_sent';
	const LEGACY_META_ID  = 'fakturoid_invoice_id';
	const LEGACY_META_URL = 'fakturoid_invoice_public_html';

	private static $instance;
	private $client;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function set_client( SAM_Fakturoid_Client $client ) {
		$this->client = $client;
	}

	private function client() {
		if ( ! $this->client ) {
			$this->client = new SAM_Fakturoid_Client();
		}
		return $this->client;
	}

	public function init() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ), 6 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_paid' ), 6 );
		add_action( self::CREATE_HOOK, array( $this, 'create_invoice' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 20, 2 );
		add_action( self::CORRECTION_HOOK, array( $this, 'create_correction' ), 10, 2 );
		add_action( 'sam_ticket_email_after_qr', array( $this, 'email_link' ), 10, 3 );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'admin_post_sam_fakturoid_retry', array( $this, 'admin_retry' ) );
	}

	/* ------------------------------------------------------------ faktura */

	public function wants_link( WC_Order $order ) {
		return 'yes' === $order->get_meta( '_sam_invoice_requested' );
	}

	/** Proč objednávka fakturu nedostane (prázdný řetězec = dostane). */
	public function skip_reason( WC_Order $order ) {
		if ( $order instanceof WC_Order_Refund ) {
			return 'refund';
		}
		if ( 'yes' === $order->get_meta( '_sam_no_invoice' ) ) {
			return 'bez faktury (ruční vstupenka)';
		}
		if ( $order->get_meta( self::LEGACY_META_ID ) ) {
			return 'faktura ze starého pluginu';
		}
		if ( (float) $order->get_total() <= 0 ) {
			return 'nulová objednávka';
		}
		if ( ! $order->is_paid() ) {
			return 'nezaplaceno';
		}
		return '';
	}

	public function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_ID ) || '' !== $this->skip_reason( $order ) ) {
			return;
		}
		if ( $this->wants_link( $order ) ) {
			// Odkaz patří do e-mailu se vstupenkou, který se posílá hned po této akci.
			$this->create_invoice( $order_id, 0 );
		} elseif ( ! wp_next_scheduled( self::CREATE_HOOK, array( (int) $order_id, 0 ) ) ) {
			wp_schedule_single_event( time() + 5, self::CREATE_HOOK, array( (int) $order_id, 0 ) );
		}
	}

	/** @return array|WP_Error|null faktura z Fakturoidu, null = nic se nevystavuje */
	public function create_invoice( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || '' !== $this->skip_reason( $order ) ) {
			return null;
		}
		if ( $order->get_meta( self::META_ID ) ) {
			return array( 'id' => (int) $order->get_meta( self::META_ID ) );
		}
		if ( ! $this->lock( 'inv-' . $order_id ) ) {
			return null;
		}

		$result = $this->do_create_invoice( $order );
		$this->unlock( 'inv-' . $order_id );

		if ( is_wp_error( $result ) ) {
			$this->failed( $order, $result, self::CREATE_HOOK, array( (int) $order_id ), (int) $attempt, 'Fakturu' );
			return $result;
		}

		$order = wc_get_order( $order_id );
		$order->update_meta_data( self::META_ID, (int) $result['id'] );
		$order->update_meta_data( self::META_NUMBER, (string) ( $result['number'] ?? '' ) );
		$order->update_meta_data( self::META_URL, (string) ( $result['public_html_url'] ?? '' ) );
		$order->update_meta_data( self::META_ADMIN_URL, (string) ( $result['html_url'] ?? '' ) );
		$order->update_meta_data( self::META_SUBJECT, (int) ( $result['subject_id'] ?? 0 ) );
		$order->delete_meta_data( self::META_ERROR );
		$order->save();

		$note = sprintf( 'Fakturoid: faktura %s vystavena a uhrazena.', $result['number'] ?? $result['id'] );
		if ( isset( $result['total'] ) && abs( (float) $result['total'] - (float) $order->get_total() ) > 0.009 ) {
			$note .= sprintf( ' POZOR: součet faktury %s neodpovídá objednávce %s.', $result['total'], $order->get_total() );
		}
		$order->add_order_note( $note );

		// Faktura vznikla až po e-mailu se vstupenkou (opakovaný pokus) → odkaz zvlášť.
		if ( $this->wants_link( $order ) && 'yes' !== $order->get_meta( self::META_LINK_SENT ) && $attempt > 0 ) {
			$this->send_link_email( $order );
		}
		return $result;
	}

	private function do_create_invoice( WC_Order $order ) {
		$existing = $this->find_by_custom_id( 'wc-' . $order->get_id() );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( $existing ) {
			return $existing; // předchozí pokus fakturu vytvořil, jen se nedozvěděl odpověď
		}

		$subject_id = $this->subject_for( $order );
		if ( is_wp_error( $subject_id ) ) {
			return $subject_id;
		}

		$payload = SAM_Fakturoid_Builder::invoice( $this->order_data( $order, $subject_id ), SAM_Fakturoid_Config::number_format_id() );
		$invoice = $this->client()->post( 'invoices.json', $payload );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}
		$paid = $this->mark_paid( $invoice, $this->paid_date( $order ) );
		if ( is_wp_error( $paid ) ) {
			$order->add_order_note( 'Fakturoid: fakturu se nepodařilo označit jako uhrazenou: ' . $paid->get_error_message() );
		}
		return $invoice;
	}

	public function order_data( WC_Order $order, $subject_id ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$items[] = array(
				'name'  => $item->get_name(),
				'qty'   => $item->get_quantity(),
				'total' => (float) $item->get_total() + (float) $item->get_total_tax(),
				'tax'   => (float) $item->get_total_tax(),
			);
		}
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$items[] = array(
				'name'  => $fee->get_name(),
				'qty'   => 1,
				'total' => (float) $fee->get_total() + (float) $fee->get_total_tax(),
				'tax'   => (float) $fee->get_total_tax(),
			);
		}
		return array(
			'order_id'   => $order->get_id(),
			'number'     => $order->get_order_number(),
			'date'       => $this->paid_date( $order ),
			'currency'   => $order->get_currency(),
			'language'   => $this->language( $order ),
			'subject_id' => $subject_id,
			'items'      => $items,
			'note'       => sprintf( 'en' === $this->language( $order ) ? 'Order #%s, paid by card.' : 'Objednávka č. %s, uhrazeno kartou.', $order->get_order_number() ),
		);
	}

	private function language( WC_Order $order ) {
		return 'en' === $order->get_meta( '_sam_language' ) ? 'en' : 'cs';
	}

	private function paid_date( WC_Order $order ) {
		$date = $order->get_date_paid() ?: $order->get_date_created();
		return $date ? wp_date( 'Y-m-d', $date->getTimestamp() ) : wp_date( 'Y-m-d' );
	}

	private function mark_paid( array $document, $date ) {
		return $this->client()->post(
			'invoices/' . (int) $document['id'] . '/payments.json',
			array_filter( array(
				'paid_on'  => $date,
				'amount'   => SAM_Fakturoid_Builder::money( $document['total'] ?? 0 ),
				'currency' => $document['currency'] ?? '',
			) )
		);
	}

	private function find_by_custom_id( $custom_id ) {
		$list = $this->client()->get( 'invoices.json', array( 'custom_id' => $custom_id ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( $list as $doc ) {
			if ( isset( $doc['custom_id'] ) && $custom_id === $doc['custom_id'] ) {
				return $doc;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------ odběratel */

	private function subject_for( WC_Order $order ) {
		if ( ! $this->wants_link( $order ) ) {
			return $this->retail_subject();
		}
		$data = SAM_Fakturoid_Builder::subject( array(
			'company'    => $order->get_billing_company(),
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'street'     => $order->get_billing_address_1(),
			'city'       => $order->get_billing_city(),
			'zip'        => $order->get_billing_postcode(),
			'country'    => $order->get_billing_country() ?: 'CZ',
			'ic'         => (string) $order->get_meta( '_billing_ic' ),
			'dic'        => (string) $order->get_meta( '_billing_dic' ),
			'email'      => $order->get_billing_email(),
		) );
		if ( empty( $data['name'] ) ) {
			return $this->retail_subject();
		}

		// Firma s IČO: použít existující kontakt, ať se v adresáři nehromadí kopie.
		if ( ! empty( $data['registration_no'] ) ) {
			$found = $this->client()->get( 'subjects/search.json', array( 'query' => $data['registration_no'] ) );
			if ( ! is_wp_error( $found ) ) {
				foreach ( $found as $subject ) {
					if ( isset( $subject['registration_no'] ) && $subject['registration_no'] === $data['registration_no'] ) {
						return (int) $subject['id'];
					}
				}
			}
		}
		$subject = $this->client()->post( 'subjects.json', $data );
		return is_wp_error( $subject ) ? $subject : (int) $subject['id'];
	}

	private function retail_subject() {
		$cached = (int) get_option( self::RETAIL_OPTION . '_' . md5( SAM_Fakturoid_Config::account() ) );
		if ( $cached ) {
			return $cached;
		}
		$data  = SAM_Fakturoid_Builder::retail_subject();
		$found = $this->client()->get( 'subjects.json', array( 'custom_id' => $data['custom_id'] ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$id = 0;
		foreach ( $found as $subject ) {
			if ( isset( $subject['custom_id'] ) && $data['custom_id'] === $subject['custom_id'] ) {
				$id = (int) $subject['id'];
				break;
			}
		}
		if ( ! $id ) {
			$subject = $this->client()->post( 'subjects.json', $data );
			if ( is_wp_error( $subject ) ) {
				return $subject;
			}
			$id = (int) $subject['id'];
		}
		update_option( self::RETAIL_OPTION . '_' . md5( SAM_Fakturoid_Config::account() ), $id, false );
		return $id;
	}

	/* ------------------------------------------------------------ opravný doklad */

	public function on_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( '_sam_no_invoice' ) ) {
			return;
		}
		if ( ! $order->get_meta( self::META_ID ) && ! $order->get_meta( self::LEGACY_META_ID ) ) {
			// Faktura ještě nevznikla (čeká v cronu / chyba) → opravný doklad až po ní.
			if ( ! wp_next_scheduled( self::CORRECTION_HOOK, array( (int) $refund_id, 0 ) ) ) {
				wp_schedule_single_event( time() + 300, self::CORRECTION_HOOK, array( (int) $refund_id, 0 ) );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CORRECTION_HOOK, array( (int) $refund_id, 0 ) ) ) {
			wp_schedule_single_event( time() + 5, self::CORRECTION_HOOK, array( (int) $refund_id, 0 ) );
		}
	}

	public function create_correction( $refund_id, $attempt = 0 ) {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof WC_Order_Refund || $refund->get_meta( self::META_ID ) ) {
			return null;
		}
		$order = wc_get_order( $refund->get_parent_id() );
		if ( ! $order || 'yes' === $order->get_meta( '_sam_no_invoice' ) ) {
			return null;
		}

		$invoice_id = (int) ( $order->get_meta( self::META_ID ) ?: $order->get_meta( self::LEGACY_META_ID ) );
		if ( ! $invoice_id ) {
			$error = new WP_Error( 'sam_fakturoid_no_invoice', 'objednávka zatím nemá fakturu' );
			$this->failed( $order, $error, self::CORRECTION_HOOK, array( (int) $refund_id ), (int) $attempt, 'Opravný doklad' );
			return $error;
		}
		if ( ! $this->lock( 'cor-' . $refund_id ) ) {
			return null;
		}

		$result = $this->do_create_correction( $order, $refund, $invoice_id );
		$this->unlock( 'cor-' . $refund_id );
		if ( is_wp_error( $result ) ) {
			$this->failed( $order, $result, self::CORRECTION_HOOK, array( (int) $refund_id ), (int) $attempt, 'Opravný doklad' );
			return $result;
		}

		$refund->update_meta_data( self::META_ID, (int) $result['id'] );
		$refund->update_meta_data( self::META_NUMBER, (string) ( $result['number'] ?? '' ) );
		$refund->update_meta_data( self::META_URL, (string) ( $result['public_html_url'] ?? '' ) );
		$refund->update_meta_data( self::META_ADMIN_URL, (string) ( $result['html_url'] ?? '' ) );
		$refund->save();
		$order->add_order_note( sprintf( 'Fakturoid: opravný doklad %s k refundaci %s vystaven.', $result['number'] ?? $result['id'], wc_price( $refund->get_amount(), array( 'currency' => $order->get_currency() ) ) ) );
		return $result;
	}

	private function do_create_correction( WC_Order $order, WC_Order_Refund $refund, $invoice_id ) {
		$existing = $this->find_by_custom_id( 'wc-refund-' . $refund->get_id() );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( $existing ) {
			return $existing;
		}

		$subject_id = (int) $order->get_meta( self::META_SUBJECT );
		if ( ! $subject_id ) {
			$invoice = $this->client()->get( 'invoices/' . $invoice_id . '.json' );
			if ( is_wp_error( $invoice ) ) {
				return $invoice;
			}
			$subject_id = (int) ( $invoice['subject_id'] ?? 0 );
		}

		$items = array();
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			$items[] = array(
				'name'  => $item->get_name(),
				'qty'   => $item->get_quantity(),
				'total' => (float) $item->get_total() + (float) $item->get_total_tax(),
				'tax'   => (float) $item->get_total_tax(),
			);
		}
		$date    = $refund->get_date_created() ? wp_date( 'Y-m-d', $refund->get_date_created()->getTimestamp() ) : wp_date( 'Y-m-d' );
		$payload = SAM_Fakturoid_Builder::correction( array(
			'refund_id'    => $refund->get_id(),
			'invoice_id'   => $invoice_id,
			'order_number' => $order->get_order_number(),
			'date'         => $date,
			'currency'     => $order->get_currency(),
			'language'     => $this->language( $order ),
			'subject_id'   => $subject_id,
			'items'        => $items,
			'amount'       => $refund->get_amount(),
			'reason'       => $refund->get_reason(),
		) );
		$doc = $this->client()->post( 'invoices.json', $payload );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$paid = $this->mark_paid( $doc, $date );
		if ( is_wp_error( $paid ) ) {
			$order->add_order_note( 'Fakturoid: opravný doklad se nepodařilo označit jako uhrazený: ' . $paid->get_error_message() );
		}
		return $doc;
	}

	/* ------------------------------------------------------------ chyby a zámky */

	private function failed( WC_Order $order, WP_Error $error, $hook, array $args, $attempt, $what ) {
		$order->update_meta_data( self::META_ERROR, $error->get_error_message() );
		$order->save();
		if ( isset( self::RETRY_MINUTES[ $attempt ] ) ) {
			$args[] = $attempt + 1;
			wp_schedule_single_event( time() + self::RETRY_MINUTES[ $attempt ] * MINUTE_IN_SECONDS, $hook, $args );
			$order->add_order_note( sprintf( 'Fakturoid: %s se nepodařilo vystavit (%s). Další pokus za %d min.', strtolower( $what ), $error->get_error_message(), self::RETRY_MINUTES[ $attempt ] ) );
		} else {
			$order->add_order_note( sprintf( 'Fakturoid: %s se nepodařilo vystavit ani po opakování (%s). Vystavte ručně tlačítkem v boxu Fakturoid.', strtolower( $what ), $error->get_error_message() ) );
		}
		error_log( '[sam-fakturoid] order ' . $order->get_id() . ': ' . $error->get_error_message() ); // phpcs:ignore
	}

	private function lock( $key ) {
		$name = 'sam_fakturoid_lock_' . $key;
		$time = (int) get_option( $name );
		if ( $time && time() - $time < 120 ) {
			return false;
		}
		if ( $time ) {
			delete_option( $name );
		}
		return add_option( $name, time(), '', false );
	}

	private function unlock( $key ) {
		delete_option( 'sam_fakturoid_lock_' . $key );
	}

	/* ------------------------------------------------------------ e-mail */

	private function texts( $lang ) {
		return 'en' === $lang
			? array( 'title' => 'Invoice', 'button' => 'Download invoice %s', 'subject' => 'Invoice for order #%s', 'body' => 'Here is the invoice for your order #%s.' )
			: array( 'title' => 'Faktura', 'button' => 'Stáhnout fakturu %s', 'subject' => 'Faktura k objednávce č. %s', 'body' => 'Posíláme fakturu k vaší objednávce č. %s.' );
	}

	/** Odkaz na fakturu do e-mailu se vstupenkou (háček ze sam-tickets). */
	public function email_link( $order, $plain = false, $lang = 'cs' ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order = wc_get_order( $order->get_id() ); // objekt e-mailu může být starší než uložení faktury
		if ( ! $order || ! $this->wants_link( $order ) ) {
			return;
		}
		$url = (string) $order->get_meta( self::META_URL );
		if ( '' === $url ) {
			return; // faktura ještě není → přijde zvlášť po opakovaném pokusu
		}
		$t      = $this->texts( $lang );
		$number = (string) $order->get_meta( self::META_NUMBER );
		if ( $plain ) {
			echo "\n" . sprintf( $t['button'], $number ) . ': ' . esc_url_raw( $url ) . "\n";
		} else {
			echo '<p style="margin:0 0 28px;text-align:center"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;border:2px solid #0F1012;color:#0F1012;font-weight:700;text-decoration:none">' . esc_html( sprintf( $t['button'], $number ) ) . '</a></p>';
		}
		$order->update_meta_data( self::META_LINK_SENT, 'yes' );
		$order->save_meta_data();
	}

	public function send_link_email( WC_Order $order ) {
		$url = (string) $order->get_meta( self::META_URL );
		if ( '' === $url || ! $order->get_billing_email() ) {
			return false;
		}
		$t      = $this->texts( $this->language( $order ) );
		$number = (string) $order->get_meta( self::META_NUMBER );
		$mailer = WC()->mailer();
		$html   = '<p>' . esc_html( sprintf( $t['body'], $order->get_order_number() ) ) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html( sprintf( $t['button'], $number ) ) . '</a></p>';
		$sent   = $mailer->send( $order->get_billing_email(), sprintf( $t['subject'], $order->get_order_number() ), $mailer->wrap_message( $t['title'], $html ) );
		if ( $sent ) {
			$order->update_meta_data( self::META_LINK_SENT, 'yes' );
			$order->save_meta_data();
			$order->add_order_note( 'Fakturoid: odkaz na fakturu poslán zákazníkovi e-mailem.' );
		}
		return $sent;
	}

	/* ------------------------------------------------------------ administrace */

	public function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'sam-fakturoid', 'Fakturoid', array( $this, 'render_box' ), $screen, 'side', 'default' );
	}

	public function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}
		$admin_url = (string) ( $order->get_meta( self::META_ADMIN_URL ) ?: '' );
		$number    = (string) $order->get_meta( self::META_NUMBER );
		if ( $number ) {
			echo '<p>Faktura: ' . ( $admin_url ? '<a href="' . esc_url( $admin_url ) . '" target="_blank">' . esc_html( $number ) . '</a>' : esc_html( $number ) ) . '</p>';
		} elseif ( $order->get_meta( self::LEGACY_META_ID ) ) {
			echo '<p>Faktura ze starého pluginu: <a href="' . esc_url( (string) $order->get_meta( self::LEGACY_META_URL ) ) . '" target="_blank">' . esc_html( (string) $order->get_meta( 'fakturoid_invoice_main_id' ) ) . '</a></p>';
		} else {
			$reason = $this->skip_reason( $order );
			echo '<p>Faktura zatím není' . ( $reason ? ' (' . esc_html( $reason ) . ')' : '' ) . '.</p>';
		}
		echo '<p>Odkaz zákazníkovi: ' . ( $this->wants_link( $order ) ? 'ano (chtěl fakturu)' : 'ne' ) . '</p>';
		foreach ( $order->get_refunds() as $refund ) {
			$num = (string) $refund->get_meta( self::META_NUMBER );
			if ( $num ) {
				echo '<p>Opravný doklad: <a href="' . esc_url( (string) $refund->get_meta( self::META_ADMIN_URL ) ) . '" target="_blank">' . esc_html( $num ) . '</a></p>';
			}
		}
		$error = (string) $order->get_meta( self::META_ERROR );
		if ( $error ) {
			echo '<p style="color:#b32d2e">Poslední chyba: ' . esc_html( $error ) . '</p>';
		}
		if ( ! $number && '' === $this->skip_reason( $order ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=sam_fakturoid_retry&order_id=' . $order->get_id() ), 'sam_fakturoid_retry_' . $order->get_id() );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">Vystavit fakturu</a></p>';
		}
	}

	public function admin_retry() {
		$order_id = absint( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'sam_fakturoid_retry_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Nemáte oprávnění.' );
		}
		$this->create_invoice( $order_id, count( self::RETRY_MINUTES ) ); // bez dalšího plánování, chybu uvidí v boxu
		$order = wc_get_order( $order_id );
		wp_safe_redirect( $order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}
}
