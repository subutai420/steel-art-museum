<?php

defined( 'ABSPATH' ) || exit;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * QR kód, PDF vstupenka, e-mail se vstupenkou a zabezpečené stažení PDF.
 *
 * Soubory leží v uploads/sam-tickets/RRRR/MM s náhodnou částí názvu; přímý přístup
 * blokuje nginx (a .htaccess pro Apache). Zákazník stahuje přes podepsaný odkaz.
 */
final class SAM_Tickets_Documents {
	const ASSET_VERSION     = '1';
	const CUSTOMER_EMAIL_ID = 'customer_processing_order';

	private $repository;
	private $pending_embeds = array();

	public function __construct( SAM_Tickets_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_hooks() {
		add_action( 'sam_ticket_created', array( $this, 'handle_ticket_created' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'handle_download' ), 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'render_order_qr' ), 5 );
		add_action( 'woocommerce_view_order', array( $this, 'render_order_qr' ), 5 );
		add_filter( 'woocommerce_email_attachments', array( $this, 'email_attachments' ), 10, 4 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( $this, 'ensure_processing_email_ready' ), 10, 3 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( $this, 'disable_duplicate_completed_email' ), 10, 3 );
		add_filter( 'woocommerce_email_order_items_args', array( $this, 'hide_ticket_product_images' ) );
		add_filter( 'woocommerce_email_subject_customer_processing_order', array( $this, 'customer_email_subject' ), 10, 3 );
		add_filter( 'woocommerce_email_heading_customer_processing_order', array( $this, 'customer_email_heading' ), 10, 3 );
		add_filter( 'woocommerce_locate_template', array( $this, 'locate_customer_email_template' ), 10, 3 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_ticket' ), 20, 4 );
		add_action( 'phpmailer_init', array( $this, 'embed_email_images' ), 20 );
	}

	/**
	 * Zaplacené objednávky vstupenek zůstávají ve stavu „zpracovává se“ a e-mail
	 * k tomuto stavu je jediný, který zákazníkovi doručí vstupenku. E-mail
	 * „dokončeno“ by byl duplicitní.
	 */
	public function disable_duplicate_completed_email( $enabled, $order, $email = null ) {
		return $order instanceof WC_Order && $this->is_ticket_order( $order ) ? false : $enabled;
	}

	/**
	 * E-mail se vstupenkou se odešle, jen když existuje vstupenka a čitelné PDF
	 * (jinak by zákazník dostal e-mail bez vstupenky). Chyba se zapíše k objednávce.
	 */
	public function ensure_processing_email_ready( $enabled, $order, $email = null ) {
		if ( ! $enabled || ! $order instanceof WC_Order ) {
			return $enabled;
		}
		$ticket = $this->repository->find_by_order_id( $order->get_id() );
		if ( ! $ticket ) {
			// Objednávka bez vstupenky (např. zatím nezaplacená) – e-mail k ní nepatří.
			if ( $order->is_paid() ) {
				$this->record_delivery_error( $order, 'Zákaznický e-mail nebyl odeslán: objednávka nemá vystavenou vstupenku.' );
			}
			return false;
		}
		$assets = $this->ensure_assets( $ticket->id, $order->get_id() );
		if ( is_wp_error( $assets ) || empty( $assets['pdf'] ) || ! is_readable( $assets['pdf'] ) ) {
			$message = is_wp_error( $assets ) ? $assets->get_error_message() : 'PDF vstupenky není čitelné.';
			$this->record_delivery_error( $order, 'Zákaznický e-mail nebyl odeslán: ' . $message );
			return false;
		}

		$order->delete_meta_data( '_sam_delivery_last_error' );
		$order->save_meta_data();
		return true;
	}

	public function hide_ticket_product_images( $args ) {
		$order = isset( $args['order'] ) && $args['order'] instanceof WC_Order ? $args['order'] : null;
		if ( $order && $this->is_ticket_order( $order ) ) {
			$args['show_image'] = false;
		}
		return $args;
	}

	public function customer_email_subject( $subject, $order, $email = null ) {
		if ( ! $order instanceof WC_Order || ! $this->is_ticket_order( $order ) ) {
			return $subject;
		}
		$copy = self::customer_email_copy( $order );
		return sprintf( $copy['subject'], $order->get_order_number() );
	}

	public function customer_email_heading( $heading, $order, $email = null ) {
		if ( ! $order instanceof WC_Order || ! $this->is_ticket_order( $order ) ) {
			return $heading;
		}
		$copy = self::customer_email_copy( $order );
		return $copy['heading'];
	}

	public function locate_customer_email_template( $template, $template_name, $template_path ) {
		if ( 'emails/customer-processing-order.php' === $template_name ) {
			return SAM_TICKETS_DIR . 'templates/emails/customer-ticket-order.php';
		}
		if ( 'emails/plain/customer-processing-order.php' === $template_name ) {
			return SAM_TICKETS_DIR . 'templates/emails/plain/customer-ticket-order.php';
		}
		return $template;
	}

	public static function order_language( WC_Order $order ) {
		$language = strtolower( sanitize_key( (string) $order->get_meta( '_sam_language', true ) ) );
		if ( ! $language ) {
			$language = strtolower( sanitize_key( (string) $order->get_meta( 'wpml_language', true ) ) );
		}
		return 0 === strpos( $language, 'en' ) ? 'en' : 'cs';
	}

	public static function customer_email_copy( WC_Order $order ) {
		$all = array(
			'cs' => array(
				'subject'  => 'Vstupenky do Steel Art Museum – objednávka #%s',
				'heading'  => 'Vaše vstupenky jsou připravené',
				'greeting' => 'Dobrý den%s,',
				'intro'    => 'děkujeme za nákup vstupenek do Steel Art Museum. Platba proběhla v pořádku.',
				'validity' => 'Nemusíte si rezervovat termín – přijďte kdykoli v otevírací době (denně 10–21 h), vstupenky platí do %s.',
				'delivery' => 'Níže najdete QR kód pro vstup a odkaz ke stažení. PDF vstupenku máte i v příloze.',
				'summary'  => 'Vaše objednávka:',
				'closing'  => 'Těšíme se na vás v Celetné 15, Praha 1.',
			),
			'en' => array(
				'subject'  => 'Steel Art Museum tickets – order #%s',
				'heading'  => 'Your tickets are ready',
				'greeting' => 'Hello%s,',
				'intro'    => 'thank you for buying tickets to Steel Art Museum. Your payment was successful.',
				'validity' => 'No need to book a time – come any day during opening hours (daily 10 am–9 pm). Tickets are valid until %s.',
				'delivery' => 'Below you will find the entry QR code and a download link. The ticket PDF is also attached.',
				'summary'  => 'Your order:',
				'closing'  => 'We look forward to seeing you at Celetná 15, Prague 1.',
			),
		);
		return $all[ self::order_language( $order ) ];
	}

	public function handle_ticket_created( $ticket_id, $order_id ) {
		$result = $this->ensure_assets( $ticket_id, $order_id );
		if ( is_wp_error( $result ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( 'PDF/QR vstupenky se nepodařilo vytvořit: ' . $result->get_error_message() );
			}
			$this->repository->add_event( $ticket_id, 'assets_failed', array( 'message' => $result->get_error_message() ) );
		}
	}

	public function ensure_assets( $ticket_id, $order_id = 0 ) {
		$ticket = $this->repository->find( absint( $ticket_id ) );
		if ( ! $ticket ) {
			return new WP_Error( 'sam_ticket_missing', 'Vstupenka neexistuje.' );
		}

		$order = wc_get_order( $order_id ? $order_id : $ticket->order_id );
		if ( ! $order ) {
			return new WP_Error( 'sam_ticket_order_missing', 'Objednávka vstupenky neexistuje.' );
		}

		// Verze zahrnuje i platnost: po jejím prodloužení se PDF vytvoří znovu s novým datem.
		$version        = self::ASSET_VERSION . '|' . $ticket->valid_until;
		$stored_version = (string) $order->get_meta( '_sam_ticket_asset_version', true );
		$pdf_path       = (string) $order->get_meta( '_sam_ticket_pdf_path', true );
		$qr_path        = (string) $order->get_meta( '_sam_ticket_qr_path', true );
		if ( $version === $stored_version && $pdf_path && $qr_path && is_readable( $pdf_path ) && is_readable( $qr_path ) ) {
			return array( 'pdf' => $pdf_path, 'qr' => $qr_path, 'url' => $this->download_url( $ticket ) );
		}

		if ( ! class_exists( 'Dompdf\\Dompdf' ) || ! class_exists( 'chillerlan\\QRCode\\QRCode' ) ) {
			return new WP_Error( 'sam_ticket_dependencies_missing', 'Chybí knihovna pro generování PDF nebo QR.' );
		}

		$directory = $this->asset_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$fingerprint = substr( hash( 'sha256', $ticket->token ), 0, 16 );
		$base_name   = 'sam-ticket-' . absint( $ticket->id ) . '-' . $fingerprint;
		$qr_path     = trailingslashit( $directory ) . $base_name . '.png';
		$pdf_path    = trailingslashit( $directory ) . $base_name . '.pdf';

		try {
			$this->create_qr( $ticket->token, $qr_path );
			$this->create_pdf( $ticket, $order, $qr_path, $pdf_path );
		} catch ( Throwable $error ) {
			return new WP_Error( 'sam_ticket_asset_error', $error->getMessage() );
		}

		if ( ! is_readable( $qr_path ) || ! is_readable( $pdf_path ) ) {
			return new WP_Error( 'sam_ticket_asset_write_failed', 'Soubor vstupenky se nepodařilo uložit.' );
		}

		$order->update_meta_data( '_sam_ticket_qr_path', $qr_path );
		$order->update_meta_data( '_sam_ticket_pdf_path', $pdf_path );
		$order->update_meta_data( '_sam_ticket_asset_version', $version );
		$order->save_meta_data();
		$this->repository->add_event( $ticket->id, 'assets_generated', array( 'version' => $version ) );

		return array( 'pdf' => $pdf_path, 'qr' => $qr_path, 'url' => $this->download_url( $ticket ) );
	}

	private function asset_directory() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'sam_ticket_upload_error', $uploads['error'] );
		}

		$root      = trailingslashit( $uploads['basedir'] ) . 'sam-tickets';
		$directory = $root . '/' . gmdate( 'Y/m' );
		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'sam_ticket_directory_error', 'Nelze vytvořit adresář pro vstupenky.' );
		}

		if ( ! file_exists( trailingslashit( $root ) . 'index.php' ) ) {
			file_put_contents( trailingslashit( $root ) . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( trailingslashit( $root ) . '.htaccess' ) ) {
			file_put_contents( trailingslashit( $root ) . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		return $directory;
	}

	private function create_qr( $payload, $path ) {
		$options = new QROptions(
			array(
				'outputType'   => QROutputInterface::GDIMAGE_PNG,
				'outputBase64' => false,
				'eccLevel'     => EccLevel::H,
				'scale'        => 12,
				'addQuietzone' => true,
			)
		);
		( new QRCode( $options ) )->render( (string) $payload, $path );
	}

	private function create_pdf( $ticket, WC_Order $order, $qr_path, $pdf_path ) {
		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $this->pdf_html( $ticket, $order, $qr_path ), 'UTF-8' );
		$dompdf->setPaper( 'A5', 'portrait' );
		$dompdf->render();

		if ( false === file_put_contents( $pdf_path, $dompdf->output() ) ) {
			throw new RuntimeException( 'PDF vstupenky se nepodařilo uložit.' );
		}
	}

	/** PDF vstupenka A5 v designu „Žár – černá ocel“. */
	private function pdf_html( $ticket, WC_Order $order, $qr_path ) {
		$texts       = $this->texts( $ticket->language );
		$valid_until = get_date_from_gmt( $ticket->valid_until, 'j. n. Y' );
		$code        = 'SAM-' . str_pad( (string) $ticket->id, 6, '0', STR_PAD_LEFT );
		$logo_data   = $this->image_data_uri( SAM_TICKETS_DIR . 'assets/images/sam-logo.png' );
		$qr_data     = $this->image_data_uri( $qr_path );
		$rows        = '';

		foreach ( $this->repository->get_items( $ticket->id ) as $item ) {
			$remaining = max( 0, (int) $item->purchased_quantity - (int) $item->refunded_quantity );
			if ( ! $remaining ) {
				continue;
			}
			$rows .= '<tr><td>' . esc_html( $item->label ) . '</td><td class="qty">' . esc_html( $remaining ) . '&times;</td></tr>';
		}

		$css = '@page{margin:0;size:A5 portrait}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#F4F4F2;color:#0F1012;font-family:"DejaVu Sans",sans-serif}'
			. '.page{position:relative;width:148mm;height:210mm;overflow:hidden;background:#F4F4F2}'
			. '.hero{position:absolute;left:0;top:0;width:148mm;height:86mm;background:#0F1012;color:#F4F4F2}'
			. '.logo{position:absolute;left:14mm;top:10mm;width:20mm;height:20mm}'
			. '.brand{position:absolute;left:37mm;top:14mm;font-size:9pt;font-weight:bold;letter-spacing:1.2pt;line-height:1.25}'
			. '.tag{position:absolute;right:14mm;top:13mm;padding:2.5mm 5mm;border-radius:6mm;background:#E6BC62;color:#0F1012;font-size:7.5pt;font-weight:bold;text-transform:uppercase;letter-spacing:.8pt}'
			. '.headline{position:absolute;left:14mm;top:38mm;font-size:25pt;line-height:1.02;font-weight:bold;text-transform:uppercase}'
			. '.subline{position:absolute;left:14mm;top:63mm;width:120mm;font-size:8.5pt;color:#CDD0D4}'
			. '.qr-card{position:absolute;left:45mm;top:70mm;width:58mm;height:58mm;padding:6mm;border-radius:2mm;background:#fff;border:.4mm solid #DCDDDF}'
			. '.qr-card img{width:46mm;height:46mm}'
			. '.code{position:absolute;left:0;top:131mm;width:148mm;text-align:center;color:#3F4349;font-size:7.5pt;letter-spacing:.6pt}'
			. '.content{position:absolute;left:14mm;right:14mm;top:139mm;height:36mm}.content h2{margin:0 0 2.5mm;font-size:11pt;text-transform:uppercase}'
			. '.items{width:100%;border-collapse:collapse;font-size:8.8pt}.items td{padding:1.6mm 0;border-bottom:.25mm solid #DCDDDF}.items .qty{text-align:right;font-weight:bold}'
			. '.validity{position:absolute;left:14mm;right:14mm;top:178mm;height:12mm}'
			. '.valid-pill{display:inline-block;margin-right:3mm;padding:2.2mm 4.5mm;border-radius:5mm;background:#0F1012;color:#E6BC62;font-size:7.5pt;font-weight:bold}'
			. '.valid-copy{display:inline-block;vertical-align:middle;font-size:9pt;font-weight:bold}'
			. '.valid-address{position:absolute;left:62mm;top:1.2mm;width:58mm;color:#3F4349;font-size:7pt;line-height:1.3}'
			. '.footer{position:absolute;left:0;bottom:0;width:148mm;height:16mm;padding:4mm 14mm;background:#0F1012;color:#F4F4F2}'
			. '.footer-main{font-size:7.4pt;font-weight:bold}.footer-meta{margin-top:1.6mm;color:#9CA1A8;font-size:6.5pt}';

		return '<!doctype html><html lang="' . esc_attr( $ticket->language ) . '"><head><meta charset="utf-8"><style>' . $css . '</style></head><body><div class="page">'
			. '<div class="hero">'
			. ( $logo_data ? '<img class="logo" src="' . esc_attr( $logo_data ) . '" alt="Steel Art Museum">' : '' )
			. '<div class="brand">STEEL ART<br>MUSEUM</div>'
			. '<div class="tag">' . esc_html( $texts['eyebrow'] ) . '</div>'
			. '<div class="headline">' . esc_html( $texts['headline_1'] ) . '<br>' . esc_html( $texts['headline_2'] ) . '</div>'
			. '<div class="subline">' . esc_html( $texts['subline'] ) . '</div>'
			. '</div>'
			. '<div class="qr-card"><img src="' . esc_attr( $qr_data ) . '" alt="QR"></div>'
			. '<div class="code">' . esc_html( $texts['code'] . ' ' . $code . ' · ' . $texts['order'] . ' #' . $order->get_order_number() ) . '</div>'
			. '<div class="content"><h2>' . esc_html( $texts['contents'] ) . '</h2><table class="items">' . $rows . '</table></div>'
			. '<div class="validity"><span class="valid-pill">' . esc_html( $texts['valid_until'] ) . '</span><span class="valid-copy">' . esc_html( $valid_until ) . '</span><span class="valid-address">' . esc_html( $texts['address'] ) . '</span></div>'
			. '<div class="footer"><div class="footer-main">' . esc_html( $texts['footer'] ) . '</div><div class="footer-meta">steelartmuseum.eu &nbsp;&middot;&nbsp; info@steelartmuseum.eu</div></div>'
			. '</div></body></html>';
	}

	private function image_data_uri( $path ) {
		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : '';
		if ( ! $mime ) {
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$mime      = 'png' === $extension ? 'image/png' : ( 'webp' === $extension ? 'image/webp' : 'image/jpeg' );
		}
		return 'data:' . $mime . ';base64,' . base64_encode( file_get_contents( $path ) );
	}

	/** QR kód na děkovací stránce a v detailu objednávky. */
	public function render_order_qr( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$ticket = $this->repository->find_by_order_id( $order->get_id() );
		if ( ! $ticket ) {
			return;
		}

		$assets = $this->ensure_assets( $ticket->id, $order->get_id() );
		if ( is_wp_error( $assets ) ) {
			return;
		}

		$qr_data = $this->image_data_uri( $assets['qr'] );
		if ( ! $qr_data ) {
			return;
		}

		$texts       = $this->texts( $ticket->language );
		$valid_until = get_date_from_gmt( $ticket->valid_until, 'j. n. Y' );

		echo '<section class="sam-order-ticket-qr" aria-labelledby="sam-order-ticket-qr-title">';
		echo '<h2 id="sam-order-ticket-qr-title">' . esc_html( $texts['email_title'] ) . '</h2>';
		echo '<p class="sam-order-ticket-valid">' . esc_html( $texts['valid_until_text'] . ' ' . $valid_until ) . '</p>';
		echo '<img src="' . esc_attr( $qr_data ) . '" width="240" height="240" alt="' . esc_attr( $texts['email_title'] ) . '">';
		echo '<p><a class="sam-order-ticket-download" href="' . esc_url( $assets['url'] ) . '">' . esc_html( $texts['download'] ) . '</a></p>';
		echo '<p class="sam-order-ticket-note">' . esc_html( $texts['order_qr_note'] ) . '</p>';
		echo '</section>';
	}

	public function email_attachments( $attachments, $email_id, $object, $email = null ) {
		if ( self::CUSTOMER_EMAIL_ID !== $email_id ) {
			return $attachments;
		}
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object );
		if ( ! $order ) {
			return $attachments;
		}
		$ticket = $this->repository->find_by_order_id( $order->get_id() );
		if ( ! $ticket ) {
			return $attachments;
		}
		$assets = $this->ensure_assets( $ticket->id, $order->get_id() );
		if ( ! is_wp_error( $assets ) ) {
			$attachments[] = $assets['pdf'];
		}
		return array_values( array_unique( $attachments ) );
	}

	public function render_email_ticket( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! $email || self::CUSTOMER_EMAIL_ID !== $email->id ) {
			return;
		}
		$ticket = $this->repository->find_by_order_id( $order->get_id() );
		if ( ! $ticket ) {
			return;
		}
		$assets = $this->ensure_assets( $ticket->id, $order->get_id() );
		if ( is_wp_error( $assets ) ) {
			return;
		}

		$texts       = $this->texts( $ticket->language );
		$valid_until = get_date_from_gmt( $ticket->valid_until, 'j. n. Y' );
		if ( $plain_text ) {
			echo "\n" . $texts['email_title'] . "\n";
			echo $texts['valid_until_text'] . ' ' . $valid_until . "\n";
			echo $assets['url'] . "\n";
			do_action( 'sam_ticket_email_after_qr', $order, true, $ticket->language );
			return;
		}

		$cid                          = 'sam-ticket-qr-' . absint( $ticket->id );
		$this->pending_embeds[ $cid ] = $assets['qr'];
		echo '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:28px 0;background:#0F1012;border-radius:6px"><tr><td style="padding:28px 24px;text-align:center">'
			. '<h2 style="margin:0 0 8px;color:#F4F4F2;font-size:22px;text-transform:uppercase">' . esc_html( $texts['email_title'] ) . '</h2>'
			. '<p style="margin:0 0 18px;color:#E6BC62;font-weight:700">' . esc_html( $texts['valid_until_text'] . ' ' . $valid_until ) . '</p>'
			. '<img src="cid:' . esc_attr( $cid ) . '" width="220" height="220" alt="QR" style="display:block;width:220px;height:220px;margin:0 auto 20px;background:#fff;border-radius:4px">'
			. '<a href="' . esc_url( $assets['url'] ) . '" style="display:inline-block;padding:14px 26px;border-radius:999px;background:#E6BC62;color:#0F1012;font-weight:700;text-decoration:none">' . esc_html( $texts['download'] ) . '</a>'
			. '<p style="margin:18px 0 0;color:#9CA1A8;font-size:13px">' . esc_html( $texts['email_note'] ) . '</p>'
			. '</td></tr></table>';
		do_action( 'sam_ticket_email_after_qr', $order, false, $ticket->language );
	}

	private function is_ticket_order( WC_Order $order ) {
		return (bool) $order->get_meta( '_sam_ticket_id', true ) || (bool) $this->repository->find_by_order_id( $order->get_id() );
	}

	private function record_delivery_error( WC_Order $order, $message ) {
		$message = sanitize_text_field( $message );
		if ( $message === (string) $order->get_meta( '_sam_delivery_last_error', true ) ) {
			return;
		}
		$order->update_meta_data( '_sam_delivery_last_error', $message );
		$order->add_order_note( $message );
		$order->save_meta_data();
	}

	public function embed_email_images( $mailer ) {
		if ( ! $this->pending_embeds ) {
			return;
		}
		foreach ( $this->pending_embeds as $cid => $path ) {
			if ( is_readable( $path ) ) {
				try {
					$mailer->addEmbeddedImage( $path, $cid, 'steel-art-museum-qr.png', 'base64', 'image/png' );
				} catch ( Throwable $error ) {
					// PDF v příloze a zabezpečený odkaz zůstávají, i když vložení obrázku selže.
				}
			}
		}
		$this->pending_embeds = array();
	}

	public function handle_download() {
		if ( empty( $_GET['sam_ticket_download'] ) || empty( $_GET['sam_ticket_key'] ) ) {
			return;
		}
		$ticket_id = absint( $_GET['sam_ticket_download'] );
		$key       = sanitize_text_field( wp_unslash( $_GET['sam_ticket_key'] ) );
		$ticket    = $this->repository->find( $ticket_id );
		if ( ! $ticket || ! hash_equals( $this->download_signature( $ticket ), $key ) ) {
			wp_die( esc_html__( 'Odkaz na vstupenku není platný.', 'sam-tickets' ), '', array( 'response' => 403 ) );
		}

		$assets = $this->ensure_assets( $ticket->id, $ticket->order_id );
		if ( is_wp_error( $assets ) || ! is_readable( $assets['pdf'] ) ) {
			wp_die( esc_html__( 'PDF vstupenky není dostupné.', 'sam-tickets' ), '', array( 'response' => 404 ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $assets['pdf'] ) );
		header( 'Content-Disposition: attachment; filename="steel-art-museum-vstupenka-' . absint( $ticket->id ) . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $assets['pdf'] );
		exit;
	}

	public function download_url( $ticket ) {
		return add_query_arg(
			array(
				'sam_ticket_download' => absint( $ticket->id ),
				'sam_ticket_key'      => $this->download_signature( $ticket ),
			),
			home_url( '/' )
		);
	}

	private function download_signature( $ticket ) {
		return substr( hash_hmac( 'sha256', $ticket->id . '|' . $ticket->token, wp_salt( 'auth' ) ), 0, 40 );
	}

	private function texts( $language ) {
		$all = array(
			'cs' => array(
				'eyebrow'          => 'Online vstupenka',
				'headline_1'       => 'Na tohle',
				'headline_2'       => 'se sahá.',
				'subline'          => 'Bez rezervace termínu – přijďte kdykoli v otevírací době, denně 10–21 h.',
				'code'             => 'KÓD',
				'order'            => 'objednávka',
				'contents'         => 'Obsah vstupenky',
				'valid_until'      => 'PLATÍ DO',
				'valid_until_text' => 'Platí do',
				'address'          => 'Steel Art Museum, Celetná 15, 110 00 Praha 1',
				'footer'           => 'Jeden QR kód pro celou objednávku. Osoby lze odbavit i postupně. Nesdílejte jej.',
				'download'         => 'Stáhnout PDF vstupenku',
				'email_title'      => 'QR kód pro vstup',
				'email_note'       => 'Kód ukažte na pokladně v telefonu nebo vytištěný. PDF vstupenku máte i v příloze.',
				'order_qr_note'    => 'Ukažte tento kód na pokladně. Jednotlivé osoby lze odbavit i postupně.',
			),
			'en' => array(
				'eyebrow'          => 'Online ticket',
				'headline_1'       => 'You can',
				'headline_2'       => 'touch this.',
				'subline'          => 'No time slot needed – come any time during opening hours, daily 10 am–9 pm.',
				'code'             => 'CODE',
				'order'            => 'order',
				'contents'         => 'Ticket contents',
				'valid_until'      => 'VALID UNTIL',
				'valid_until_text' => 'Valid until',
				'address'          => 'Steel Art Museum, Celetná 15, 110 00 Prague 1',
				'footer'           => 'One QR code for the whole order. Visitors may also be checked in separately. Do not share it.',
				'download'         => 'Download ticket PDF',
				'email_title'      => 'Entry QR code',
				'email_note'       => 'Show the code at the ticket desk on your phone or printed. The ticket PDF is attached too.',
				'order_qr_note'    => 'Show this code at the ticket desk. Visitors may also be checked in separately.',
			),
		);
		$language = isset( $all[ $language ] ) ? $language : 'cs';
		return $all[ $language ];
	}
}
