<?php

defined( 'ABSPATH' ) || exit;

/**
 * Přístup do Fakturoidu (API v3, OAuth client credentials).
 *
 * Tajné údaje nejsou v databázi: konstanty ve wp-config.php nebo proměnné prostředí
 * serveru (na Rosti /srv/stack/.env → kontejnery php, cron, wpcli):
 *   SAM_FAKTUROID_ACCOUNT        slug účtu (roslincorp)
 *   SAM_FAKTUROID_CLIENT_ID      Client ID (Nastavení → Uživatelský účet → API v3)
 *   SAM_FAKTUROID_CLIENT_SECRET  Client Secret
 *   SAM_FAKTUROID_NUMBER_FORMAT  volitelně ID číselné řady faktur (jinak výchozí řada účtu)
 */
final class SAM_Fakturoid_Config {
	const API_BASE   = 'https://app.fakturoid.cz/api/v3/';
	const USER_AGENT = 'Steel Art Museum web (info@steelartmuseum.eu)';

	public static function value( $name ) {
		if ( defined( $name ) ) {
			return trim( (string) constant( $name ) );
		}
		$env = getenv( $name );
		return false === $env ? '' : trim( (string) $env );
	}

	public static function account() {
		return self::value( 'SAM_FAKTUROID_ACCOUNT' );
	}

	public static function client_id() {
		return self::value( 'SAM_FAKTUROID_CLIENT_ID' );
	}

	public static function client_secret() {
		return self::value( 'SAM_FAKTUROID_CLIENT_SECRET' );
	}

	public static function number_format_id() {
		$id = self::value( 'SAM_FAKTUROID_NUMBER_FORMAT' );
		return ctype_digit( $id ) ? (int) $id : 0;
	}

	public static function is_configured() {
		return '' !== self::account() && '' !== self::client_id() && '' !== self::client_secret();
	}

	public static function account_url( $path ) {
		return self::API_BASE . 'accounts/' . rawurlencode( self::account() ) . '/' . ltrim( $path, '/' );
	}
}
