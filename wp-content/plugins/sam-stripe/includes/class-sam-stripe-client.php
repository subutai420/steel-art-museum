<?php

defined( 'ABSPATH' ) || exit;

/**
 * Minimální klient Stripe API (bez knihovny stripe-php): form-encoded požadavky,
 * pevná verze API, idempotentní klíče u zápisů.
 */
final class SAM_Stripe_Client {
	private $secret_key;

	public function __construct( $secret_key = null ) {
		$this->secret_key = null === $secret_key ? SAM_Stripe_Config::secret_key() : (string) $secret_key;
	}

	public function get( $path, array $query = array() ) {
		$url = SAM_Stripe_Config::API_BASE . ltrim( $path, '/' );
		if ( $query ) {
			$url .= '?' . self::encode( $query );
		}
		return $this->request( 'GET', $url, null, '' );
	}

	public function post( $path, array $params = array(), $idempotency_key = '' ) {
		return $this->request( 'POST', SAM_Stripe_Config::API_BASE . ltrim( $path, '/' ), self::encode( $params ), $idempotency_key );
	}

	/**
	 * @return array|WP_Error Dekódovaná odpověď, nebo chyba s kódem a zprávou od Stripe.
	 */
	private function request( $method, $url, $body, $idempotency_key ) {
		if ( '' === $this->secret_key ) {
			return new WP_Error( 'sam_stripe_not_configured', 'Stripe není nastavený (chybí tajný klíč).' );
		}
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => SAM_Stripe_Config::API_VERSION,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		);
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = substr( (string) $idempotency_key, 0, 255 );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 45,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sam_stripe_http', 'Spojení se Stripe selhalo: ' . $response->get_error_message(), array( 'indeterminate' => true ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sam_stripe_bad_response', 'Stripe vrátil nečitelnou odpověď (HTTP ' . $code . ').', array( 'indeterminate' => $code >= 500 ) );
		}
		if ( $code >= 400 || isset( $data['error'] ) ) {
			$error = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
			return new WP_Error(
				'sam_stripe_' . sanitize_key( (string) ( $error['code'] ?? $error['type'] ?? 'error' ) ),
				(string) ( $error['message'] ?? 'Stripe požadavek odmítl.' ),
				array(
					'status'        => $code,
					'type'          => $error['type'] ?? '',
					'decline_code'  => $error['decline_code'] ?? '',
					'indeterminate' => $code >= 500 || 409 === $code,
				)
			);
		}
		return $data;
	}

	/** Pole → form-encoded tělo ve stylu Stripe (a[b]=c, a[0]=d). */
	public static function encode( array $params, $prefix = '' ) {
		$parts = array();
		foreach ( $params as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';
			if ( is_array( $value ) ) {
				$nested = self::encode( $value, $name );
				if ( '' !== $nested ) {
					$parts[] = $nested;
				}
			} elseif ( null !== $value ) {
				if ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				}
				$parts[] = rawurlencode( $name ) . '=' . rawurlencode( (string) $value );
			}
		}
		return implode( '&', $parts );
	}
}
