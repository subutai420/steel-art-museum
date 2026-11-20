<?php
/**
 * Odesílání e-mailů přes SMTP (vstupenky, faktury, kontaktní formulář, reporty).
 *
 * Údaje jsou v prostředí serveru (/srv/stack/.env → docker-compose), ne v databázi ani v kódu:
 *   SAM_SMTP_HOST, SAM_SMTP_PORT (465 = SSL, 587 = STARTTLS), SAM_SMTP_USER, SAM_SMTP_PASS,
 *   SAM_SMTP_FROM (výchozí info@steelartmuseum.eu), SAM_SMTP_FROM_NAME (výchozí Steel Art Museum).
 * Bez SAM_SMTP_HOST se nic nemění (PHP mail()).
 *
 * Kontrola: wp eval 'var_dump( SAM_SMTP::configured() );'  a  wp sam-smtp test <adresa>
 */

defined( 'ABSPATH' ) || exit;

final class SAM_SMTP {
	public static function env( $name, $default = '' ) {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$v = getenv( $name );
		return false === $v || '' === trim( $v ) ? $default : trim( $v );
	}

	public static function configured() {
		return '' !== self::env( 'SAM_SMTP_HOST' );
	}

	public static function init() {
		if ( ! self::configured() ) {
			return;
		}
		add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer' ), 20 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from' ), 20 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 20 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_failure' ) );
	}

	public static function phpmailer( $mailer ) {
		$port = (int) self::env( 'SAM_SMTP_PORT', '465' );
		$mailer->isSMTP();
		$mailer->Host       = self::env( 'SAM_SMTP_HOST' );
		$mailer->Port       = $port;
		$mailer->SMTPSecure = 465 === $port ? 'ssl' : ( 25 === $port ? '' : 'tls' );
		$mailer->SMTPAutoTLS = 25 !== $port;
		$user               = self::env( 'SAM_SMTP_USER' );
		if ( '' !== $user ) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $user;
			$mailer->Password = self::env( 'SAM_SMTP_PASS' );
		}
		$mailer->Timeout = 15;
		// Návratová adresa (envelope sender) = odesílatel, kvůli SPF.
		$mailer->Sender = self::from( $mailer->From );
	}

	public static function from( $from ) {
		return self::env( 'SAM_SMTP_FROM', 'info@steelartmuseum.eu' );
	}

	public static function from_name( $name ) {
		return self::env( 'SAM_SMTP_FROM_NAME', 'Steel Art Museum' );
	}

	public static function log_failure( $error ) {
		error_log( '[sam-smtp] ' . ( $error instanceof WP_Error ? $error->get_error_message() : 'unknown error' ) );
	}
}

SAM_SMTP::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Zkušební e-mail: wp sam-smtp test info@steelartmuseum.eu
	 */
	WP_CLI::add_command(
		'sam-smtp test',
		static function ( $args ) {
			$to = $args[0] ?? '';
			if ( ! is_email( $to ) ) {
				WP_CLI::error( 'Zadejte adresu příjemce.' );
			}
			if ( ! SAM_SMTP::configured() ) {
				WP_CLI::warning( 'SAM_SMTP_HOST není nastavený, posílá se přes PHP mail().' );
			}
			$ok = wp_mail( $to, 'Steel Art Museum – zkušební e-mail', "Odesílání e-mailů z webu funguje.\n" );
			$ok ? WP_CLI::success( 'Odesláno.' ) : WP_CLI::error( 'Odeslání selhalo (podrobnosti v logu PHP).' );
		}
	);
}
