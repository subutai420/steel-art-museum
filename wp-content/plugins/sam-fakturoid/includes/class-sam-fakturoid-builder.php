<?php

defined( 'ABSPATH' ) || exit;

/**
 * Data pro Fakturoid z objednávky. Čisté funkce bez WordPressu (testy: tests/test-builder.php).
 *
 * Ceny jdou včetně DPH (vat_price_mode from_total_with_vat), sleva je už rozpočítaná
 * v cenách řádků (WooCommerce i akce „každá druhá zdarma“ snižují řádky košíku).
 * Když cena řádku nejde beze zbytku vydělit počtem kusů, řádek se rozdělí na dva,
 * aby součet faktury seděl na haléř s objednávkou.
 */
final class SAM_Fakturoid_Builder {
	const VAT_RATES = array( 0, 10, 12, 15, 21 );

	/**
	 * @param array $items [ [ 'name' => string, 'qty' => int, 'total' => float (s DPH), 'tax' => float ] ]
	 * @param int   $sign  1 pro fakturu, -1 pro opravný doklad (záporné počty)
	 */
	public static function lines( array $items, $sign = 1 ) {
		$lines = array();
		foreach ( $items as $item ) {
			$qty   = abs( (int) $item['qty'] );
			$total = abs( (float) $item['total'] );
			$tax   = abs( (float) $item['tax'] );
			if ( $qty < 1 ) {
				continue;
			}
			$rate  = self::vat_rate( $total, $tax );
			$cents = (int) round( $total * 100 );
			$base  = intdiv( $cents, $qty );
			$rest  = $cents - $base * $qty; // tolik kusů stojí o haléř víc
			$parts = $rest ? array( array( $qty - $rest, $base ), array( $rest, $base + 1 ) ) : array( array( $qty, $base ) );
			foreach ( $parts as $part ) {
				if ( $part[0] < 1 ) {
					continue;
				}
				$lines[] = array(
					'name'       => (string) $item['name'],
					'quantity'   => $part[0],
					'unit_name'  => 'ks',
					'unit_price' => self::money( $sign * $part[1] / 100 ),
					'vat_rate'   => $rate,
				);
			}
		}
		return $lines;
	}

	/** Sazba DPH z ceny s daní a daně, zaokrouhlená na nejbližší českou sazbu. */
	public static function vat_rate( $total_with_vat, $tax ) {
		$net = (float) $total_with_vat - (float) $tax;
		if ( $tax <= 0 || $net <= 0 ) {
			return 0;
		}
		$raw  = $tax / $net * 100;
		$best = 0;
		foreach ( self::VAT_RATES as $rate ) {
			if ( abs( $rate - $raw ) < abs( $best - $raw ) ) {
				$best = $rate;
			}
		}
		return $best;
	}

	public static function lines_total( array $lines ) {
		$cents = 0;
		foreach ( $lines as $line ) {
			$cents += (int) round( (float) $line['unit_price'] * 100 ) * (int) $line['quantity'];
		}
		return round( $cents / 100, 2 );
	}

	public static function money( $value ) {
		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * @param array $o [ order_id, number, date (Y-m-d), currency, language (cs|en), subject_id, items, note ]
	 */
	public static function invoice( array $o, $number_format_id = 0 ) {
		$payload = array(
			'custom_id'               => 'wc-' . $o['order_id'],
			'subject_id'              => (int) $o['subject_id'],
			'order_number'            => (string) $o['number'],
			'variable_symbol'         => preg_replace( '/\D/', '', (string) $o['number'] ),
			'issued_on'               => $o['date'],
			'taxable_fulfillment_due' => $o['date'],
			'due'                     => 0,
			'payment_method'          => 'card',
			'currency'                => strtoupper( (string) $o['currency'] ),
			'language'                => 'en' === $o['language'] ? 'en' : 'cz',
			'vat_price_mode'          => 'from_total_with_vat',
			'lines'                   => self::lines( $o['items'] ),
		);
		if ( ! empty( $o['note'] ) ) {
			$payload['note'] = (string) $o['note'];
		}
		if ( $number_format_id ) {
			$payload['number_format_id'] = (int) $number_format_id;
		}
		return $payload;
	}

	/**
	 * Opravný doklad k faktuře (záporné ceny; Fakturoid API v3 nepřijímá záporné množství).
	 *
	 * @param array $r [ refund_id, order_id, invoice_id, date, currency, language, subject_id, items, amount, reason ]
	 */
	public static function correction( array $r ) {
		$lines = self::lines( $r['items'], -1 );
		// Refundace jen částkou (bez položek) nebo s rozdílem proti položkám: dorovnávací řádek.
		$diff = round( abs( (float) $r['amount'] ) - abs( self::lines_total( $lines ) ), 2 );
		if ( $diff > 0.004 ) {
			$lines[] = array(
				'name'       => 'en' === $r['language'] ? 'Refund' : 'Vrácení platby',
				'quantity'   => 1,
				'unit_name'  => 'ks',
				'unit_price' => self::money( -$diff ),
				'vat_rate'   => (int) ( $r['fallback_rate'] ?? 12 ),
			);
		}
		$payload = array(
			'document_type'           => 'correction',
			'correction_id'           => (int) $r['invoice_id'],
			'custom_id'               => 'wc-refund-' . $r['refund_id'],
			'subject_id'              => (int) $r['subject_id'],
			'order_number'            => (string) $r['order_number'],
			'issued_on'               => $r['date'],
			'taxable_fulfillment_due' => $r['date'],
			'due'                     => 0,
			'payment_method'          => 'card',
			'currency'                => strtoupper( (string) $r['currency'] ),
			'language'                => 'en' === $r['language'] ? 'en' : 'cz',
			'vat_price_mode'          => 'from_total_with_vat',
			'lines'                   => $lines,
		);
		if ( ! empty( $r['reason'] ) ) {
			$payload['note'] = (string) $r['reason'];
		}
		return $payload;
	}

	/**
	 * Odběratel. Bez „Chci fakturu“ jde faktura na společného „Koncového zákazníka“ (bez osobních údajů).
	 *
	 * @param array $b [ company, first_name, last_name, street, city, zip, country, ic, dic, email ]
	 */
	public static function subject( array $b ) {
		$person = trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) );
		$name   = '' !== trim( (string) ( $b['company'] ?? '' ) ) ? trim( $b['company'] ) : $person;
		$data   = array(
			'name'            => $name,
			'street'          => (string) ( $b['street'] ?? '' ),
			'city'            => (string) ( $b['city'] ?? '' ),
			'zip'             => (string) ( $b['zip'] ?? '' ),
			'country'         => (string) ( $b['country'] ?? 'CZ' ),
			'registration_no' => (string) ( $b['ic'] ?? '' ),
			'vat_no'          => (string) ( $b['dic'] ?? '' ),
			'email'           => (string) ( $b['email'] ?? '' ),
		);
		if ( '' !== $person && $person !== $name ) {
			$data['full_name'] = $person;
		}
		return array_filter( $data, static function( $v ) { return '' !== $v; } );
	}

	public static function retail_subject() {
		return array(
			'name'      => 'Koncový zákazník',
			'country'   => 'CZ',
			'custom_id' => 'sam-retail',
		);
	}
}
