<?php

defined( 'ABSPATH' ) || exit;

/**
 * Nastavení vstupenek (uložené v jedné volbě sam_tickets_settings).
 *
 * - valid_until: pevné datum platnosti nově vydaných vstupenek (Y-m-d, místní čas).
 *   Datum se ke vstupence uloží při vydání; změna platí pro nové nákupy.
 * - report_email: adresa pro měsíční report neuplatněných a report propadlých vstupenek.
 */
final class SAM_Tickets_Settings {
	const OPTION = 'sam_tickets_settings';

	public static function defaults() {
		return array(
			'valid_until'  => '2027-06-30',
			'report_email' => 'info@steelartmuseum.eu',
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function valid_until_date() {
		$value = (string) self::get( 'valid_until' );
		return self::sanitize_date( $value ) ? $value : self::defaults()['valid_until'];
	}

	/** E-mailové adresy pro reporty (může jich být víc, oddělené čárkou). */
	public static function report_recipients() {
		$emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) self::get( 'report_email' ) ) ) ), 'is_email' );
		return $emails ? array_values( $emails ) : array( self::defaults()['report_email'] );
	}

	public static function update( array $values ) {
		$current = self::all();
		if ( isset( $values['valid_until'] ) && self::sanitize_date( $values['valid_until'] ) ) {
			$current['valid_until'] = $values['valid_until'];
		}
		if ( isset( $values['report_email'] ) ) {
			$emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $values['report_email'] ) ) ), 'is_email' );
			if ( $emails ) {
				$current['report_email'] = implode( ', ', $emails );
			}
		}
		update_option( self::OPTION, $current, false );
		return $current;
	}

	public static function sanitize_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value, wp_timezone() );
		return $date && $date->format( 'Y-m-d' ) === $value;
	}
}
