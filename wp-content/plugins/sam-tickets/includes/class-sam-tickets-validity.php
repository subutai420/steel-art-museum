<?php

defined( 'ABSPATH' ) || exit;

/**
 * Platnost nově vydávaných vstupenek.
 *
 * Steel Art Museum má (na rozdíl od MoB) pevné datum platnosti pro všechny
 * vstupenky, nastavitelné v administraci. Vstupenka platí do konce toho dne
 * (23:59:59 místního času). Už vydané vstupenky si drží datum z databáze.
 */
final class SAM_Tickets_Validity {

	/**
	 * Konec platnosti pro vstupenku vydanou nyní (nebo v čase $issued_at).
	 *
	 * @param string|null $date Datum Y-m-d; výchozí je hodnota z nastavení.
	 */
	public static function calculate_valid_until( $date = null ): DateTimeImmutable {
		$date = $date ? (string) $date : SAM_Tickets_Settings::valid_until_date();
		$end  = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $end ) {
			$end = DateTimeImmutable::createFromFormat( '!Y-m-d', SAM_Tickets_Settings::defaults()['valid_until'], wp_timezone() );
		}
		return $end->setTime( 23, 59, 59 );
	}

	/** Konec platnosti v UTC ve formátu pro databázi. */
	public static function valid_until_gmt( $date = null ) {
		return self::calculate_valid_until( $date )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** Je nastavená platnost už v minulosti? (nové vstupenky by byly hned propadlé) */
	public static function configured_date_is_past() {
		return self::calculate_valid_until()->getTimestamp() < time();
	}
}
