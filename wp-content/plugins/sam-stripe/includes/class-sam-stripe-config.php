<?php

defined( 'ABSPATH' ) || exit;

/**
 * Klíče Stripe.
 *
 * Tajné údaje nejsou v databázi: čtou se z konstant ve wp-config.php nebo
 * z proměnných prostředí serveru (na Rosti v /srv/stack/.env → kontejner php).
 *   SAM_STRIPE_SECRET_KEY       sk_test_… / sk_live_… (nebo omezený rk_…)
 *   SAM_STRIPE_PUBLISHABLE_KEY  pk_test_… / pk_live_…
 *   SAM_STRIPE_WEBHOOK_SECRET   whsec_…
 * Režim (test/ostrý) se pozná podle klíče.
 */
final class SAM_Stripe_Config {
	const API_BASE    = 'https://api.stripe.com/v1/';
	const API_VERSION = '2024-06-20';

	public static function value( $name ) {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$env = getenv( $name );
		return false === $env ? '' : trim( (string) $env );
	}

	public static function secret_key() {
		return self::value( 'SAM_STRIPE_SECRET_KEY' );
	}

	public static function publishable_key() {
		return self::value( 'SAM_STRIPE_PUBLISHABLE_KEY' );
	}

	public static function webhook_secret() {
		return self::value( 'SAM_STRIPE_WEBHOOK_SECRET' );
	}

	public static function is_test_mode() {
		return 0 === strpos( self::secret_key(), 'sk_test_' ) || 0 === strpos( self::secret_key(), 'rk_test_' );
	}

	public static function is_configured() {
		return '' !== self::secret_key() && '' !== self::publishable_key();
	}

	/** Klíče musí být ze stejného režimu (test/ostrý). */
	public static function keys_match() {
		$secret_test = self::is_test_mode();
		$public_test = 0 === strpos( self::publishable_key(), 'pk_test_' );
		return $secret_test === $public_test;
	}

	/**
	 * Částka v nejmenších jednotkách měny (CZK i EUR mají u Stripe 2 desetinná místa).
	 */
	public static function to_minor( $amount, $currency ) {
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$amount       = (float) $amount;
		return in_array( strtoupper( (string) $currency ), $zero_decimal, true ) ? (int) round( $amount ) : (int) round( $amount * 100 );
	}
}
