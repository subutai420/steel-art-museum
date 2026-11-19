<?php

defined( 'ABSPATH' ) || exit;

/**
 * Automatické úlohy a reporty vstupenek.
 *
 * Denně (v 6:00 místního času):
 * - vstupenky po konci platnosti přepne na „propadlá“ a pošle jejich přehled,
 * - 1. den v měsíci pošle přehled všech neuplatněných vstupenek (pro účetní).
 *
 * Reporty chodí na adresu z nastavení (výchozí info@steelartmuseum.eu) jako
 * e-mail se souhrnem a CSV v příloze (středníky, UTF-8 s BOM – otevře se v Excelu).
 */
final class SAM_Tickets_Reports {
	const DAILY_HOOK     = 'sam_tickets_daily';
	const MONTHLY_OPTION = 'sam_tickets_last_monthly_report';

	private $repository;

	public function __construct( SAM_Tickets_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_hooks() {
		add_action( self::DAILY_HOOK, array( $this, 'run_daily' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sam-tickets report', array( $this, 'cli_report' ) );
		}
	}

	public function ensure_scheduled() {
		if ( wp_next_scheduled( self::DAILY_HOOK ) ) {
			return;
		}
		$next = new DateTimeImmutable( 'today 06:00', wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY_HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	public function run_daily() {
		$expired = $this->repository->expire_overdue();
		if ( $expired ) {
			self::send_expired_report( $expired );
		}

		$today = new DateTimeImmutable( 'now', wp_timezone() );
		if ( '1' === $today->format( 'j' ) && get_option( self::MONTHLY_OPTION ) !== $today->format( 'Y-m' ) ) {
			self::send_unredeemed_report( $this->repository );
		}
	}

	/**
	 * Měsíční přehled neuplatněných vstupenek.
	 *
	 * @param bool $manual Ruční odeslání z administrace (neoznačí měsíc jako odeslaný).
	 * @return int|WP_Error Počet vstupenek v reportu.
	 */
	public static function send_unredeemed_report( SAM_Tickets_Repository $repository, $manual = false ) {
		$rows  = $repository->unredeemed_rows();
		$today = new DateTimeImmutable( 'now', wp_timezone() );
		$label = $today->format( 'j. n. Y' );

		$summary = self::summary( $rows );
		$subject = sprintf( 'Steel Art Museum – neuplatněné vstupenky k %s', $label );
		$intro   = $rows
			? sprintf( 'K %s je neuplatněno %s vstupenek (objednávek) s %s osobami/skupinami.', $label, number_format_i18n( count( $rows ) ), number_format_i18n( $summary['remaining'] ) )
			: sprintf( 'K %s nejsou žádné neuplatněné vstupenky.', $label );

		$result = self::send( $subject, $intro, $summary, $rows, 'neuplatnene-vstupenky-' . $today->format( 'Y-m-d' ) . '.csv' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $manual ) {
			update_option( self::MONTHLY_OPTION, $today->format( 'Y-m' ), false );
		} else {
			update_option( self::MONTHLY_OPTION . '_manual', current_time( 'mysql' ), false );
		}
		return count( $rows );
	}

	/** Přehled vstupenek, které právě propadly (a byly zneplatněny). */
	public static function send_expired_report( array $rows ) {
		$today   = new DateTimeImmutable( 'now', wp_timezone() );
		$summary = self::summary( $rows );
		$subject = sprintf( 'Steel Art Museum – propadlé vstupenky (%s)', $today->format( 'j. n. Y' ) );
		$intro   = sprintf( 'Skončila platnost %s vstupenek (objednávek), na kterých zůstalo neuplatněno %s osob/skupin. Vstupenky byly automaticky zneplatněny a skener je už nepřijme.', number_format_i18n( count( $rows ) ), number_format_i18n( $summary['remaining'] ) );
		return self::send( $subject, $intro, $summary, $rows, 'propadle-vstupenky-' . $today->format( 'Y-m-d' ) . '.csv' );
	}

	private static function summary( array $rows ) {
		$summary = array( 'remaining' => 0, 'value' => array() );
		foreach ( $rows as $row ) {
			$summary['remaining'] += (int) $row->remaining_total;
			$currency = $row->currency ? $row->currency : 'CZK';
			$summary['value'][ $currency ] = ( $summary['value'][ $currency ] ?? 0 ) + (float) $row->remaining_value;
		}
		return $summary;
	}

	private static function send( $subject, $intro, array $summary, array $rows, $filename ) {
		$html = '<p>' . esc_html( $intro ) . '</p>';
		if ( $summary['value'] ) {
			$html .= '<p><strong>Hodnota neuplatněných kusů:</strong> ';
			$parts = array();
			foreach ( $summary['value'] as $currency => $value ) {
				$parts[] = esc_html( number_format_i18n( $value, 2 ) . ' ' . $currency );
			}
			$html .= implode( ', ', $parts ) . ' (včetně DPH, podle ceny v objednávce).</p>';
		}
		if ( $rows ) {
			$html .= '<p>Podrobný seznam je v příloze (CSV pro Excel).</p>';
		}
		$html .= '<p style="color:#666;font-size:12px">Automatický report z webu steelartmuseum.eu. Nastavení: Vstupenky → Nastavení a reporty.</p>';

		$attachment = $rows ? self::write_csv( $rows, $filename ) : '';
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		$body   = $mailer ? $mailer->wrap_message( $subject, $html ) : $html;
		$sent   = wp_mail( SAM_Tickets_Settings::report_recipients(), $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ), $attachment ? array( $attachment ) : array() );
		if ( $attachment && file_exists( $attachment ) ) {
			wp_delete_file( $attachment );
		}
		return $sent ? true : new WP_Error( 'sam_tickets_report_mail_failed', 'E-mail se nepodařilo odeslat.' );
	}

	private static function write_csv( array $rows, $filename ) {
		$directory = trailingslashit( get_temp_dir() ) . 'sam-tickets-reports';
		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'sam_tickets_report_dir', 'Nelze vytvořit dočasný adresář pro report.' );
		}
		$path   = trailingslashit( $directory ) . sanitize_file_name( $filename );
		$handle = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'sam_tickets_report_file', 'Nelze zapsat CSV reportu.' );
		}
		fwrite( $handle, "\xEF\xBB\xBF" ); // BOM, aby Excel poznal UTF-8
		fputcsv( $handle, array( 'Vstupenka', 'Objednávka', 'Datum nákupu', 'E-mail', 'Jazyk', 'Měna', 'Zakoupeno', 'Uplatněno', 'Refundováno', 'Zbývá', 'Zbývající položky', 'Hodnota zbývajících', 'Platnost do', 'Stav', 'Zdroj' ), ';' );
		foreach ( $rows as $row ) {
			fputcsv(
				$handle,
				array(
					$row->id,
					$row->order_id,
					get_date_from_gmt( $row->valid_from, 'j. n. Y' ),
					$row->customer_email,
					strtoupper( $row->language ),
					$row->currency,
					(int) $row->purchased_total,
					(int) $row->redeemed_total,
					(int) $row->refunded_total,
					(int) $row->remaining_total,
					preg_replace( '/(^|, )0× [^,]+/u', '', (string) $row->remaining_items ),
					number_format( (float) $row->remaining_value, 2, ',', '' ),
					get_date_from_gmt( $row->valid_until, 'j. n. Y' ),
					self::label( 'status', $row->status ),
					self::label( 'source', $row->source ),
				),
				';'
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $path;
	}

	private static function label( $type, $value ) {
		$labels = array(
			'status' => array( 'valid' => 'platná', 'partially_redeemed' => 'částečně uplatněná', 'redeemed' => 'uplatněná', 'expired' => 'propadlá', 'void' => 'zneplatněná' ),
			'source' => array( 'woocommerce' => 'online nákup', 'legacy' => 'starý systém', 'bank_transfer' => 'bankovní převod', 'invoice' => 'faktura', 'compensation' => 'kompenzace', 'gift' => 'dárek / spolupráce', 'manual' => 'ruční vystavení' ),
		);
		return $labels[ $type ][ $value ] ?? $value;
	}

	/**
	 * Reporty z příkazové řádky.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : unredeemed = poslat přehled neuplatněných; expire = zneplatnit propadlé a poslat report; daily = celá denní úloha.
	 *
	 * @param array $args Argumenty.
	 */
	public function cli_report( $args ) {
		$type = $args[0] ?? '';
		if ( 'unredeemed' === $type ) {
			$result = self::send_unredeemed_report( $this->repository, true );
			is_wp_error( $result ) ? WP_CLI::error( $result->get_error_message() ) : WP_CLI::success( sprintf( 'Report odeslán (%d vstupenek).', $result ) );
		} elseif ( 'expire' === $type ) {
			$expired = $this->repository->expire_overdue();
			if ( $expired ) {
				self::send_expired_report( $expired );
			}
			WP_CLI::success( sprintf( 'Zneplatněno %d vstupenek.', count( $expired ) ) );
		} elseif ( 'daily' === $type ) {
			$this->run_daily();
			WP_CLI::success( 'Denní úloha proběhla.' );
		} else {
			WP_CLI::error( 'Použití: wp sam-tickets report unredeemed|expire|daily' );
		}
	}
}
