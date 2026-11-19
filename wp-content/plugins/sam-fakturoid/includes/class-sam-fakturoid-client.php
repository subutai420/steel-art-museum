<?php

defined( 'ABSPATH' ) || exit;

/**
 * Tenký klient Fakturoid API v3 (JSON). Token z client credentials se drží v transientu
 * (platí 2 hodiny); při 401 se jednou obnoví.
 */
class SAM_Fakturoid_Client {
	const TOKEN_TRANSIENT = 'sam_fakturoid_token_';

	public function get( $path, array $query = array() ) {
		$url = SAM_Fakturoid_Config::account_url( $path );
		if ( $query ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
		}
		return $this->request( 'GET', $url );
	}

	public function post( $path, array $body = array() ) {
		return $this->request( 'POST', SAM_Fakturoid_Config::account_url( $path ), $body );
	}

	public function request( $method, $url, $body = null, $retry = true ) {
		if ( ! SAM_Fakturoid_Config::is_configured() ) {
			return new WP_Error( 'sam_fakturoid_config', 'Fakturoid není nastavený (SAM_FAKTUROID_ACCOUNT / CLIENT_ID / CLIENT_SECRET).' );
		}
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'User-Agent'    => SAM_Fakturoid_Config::USER_AGENT,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && $retry ) {
			$this->forget_token();
			return $this->request( $method, $url, $body, false );
		}
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' === $raw ? array() : json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'sam_fakturoid_http_' . $code, self::error_message( $code, $data, $raw ), array( 'status' => $code, 'body' => $data ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public static function error_message( $code, $data, $raw = '' ) {
		$parts = array();
		if ( is_array( $data ) && isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			foreach ( $data['errors'] as $field => $messages ) {
				$parts[] = $field . ': ' . implode( ', ', array_map( 'strval', (array) $messages ) );
			}
		} elseif ( is_array( $data ) && isset( $data['error_description'] ) ) {
			$parts[] = (string) $data['error_description'];
		} elseif ( '' !== $raw ) {
			$parts[] = substr( wp_strip_all_tags( $raw ), 0, 200 );
		}
		return sprintf( 'Fakturoid HTTP %d%s', $code, $parts ? ': ' . implode( '; ', $parts ) : '' );
	}

	private function transient_key() {
		return self::TOKEN_TRANSIENT . md5( SAM_Fakturoid_Config::client_id() );
	}

	public function forget_token() {
		delete_transient( $this->transient_key() );
	}

	private function token() {
		$cached = get_transient( $this->transient_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			SAM_Fakturoid_Config::API_BASE . 'oauth/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( SAM_Fakturoid_Config::client_id() . ':' . SAM_Fakturoid_Config::client_secret() ),
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => SAM_Fakturoid_Config::USER_AGENT,
				),
				'body'    => wp_json_encode( array( 'grant_type' => 'client_credentials' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $data['access_token'] ) ) {
			return new WP_Error( 'sam_fakturoid_token', self::error_message( $code, $data ) );
		}
		$ttl = max( 60, (int) ( $data['expires_in'] ?? 7200 ) - 120 );
		set_transient( $this->transient_key(), $data['access_token'], $ttl );
		return $data['access_token'];
	}
}
