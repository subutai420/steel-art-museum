<?php
/**
 * Google Analytics 4 a Google Ads napřímo přes Google tag (gtag.js), bez GTM a bez pluginu.
 *
 * - Knihovnu gtag.js načítá až lišta souhlasu (consent.php) po souhlasu s analytikou nebo
 *   marketingem; Consent Mode v2 pak určuje, co smí GA4 a Ads ukládat a posílat.
 *   Bez souhlasu se knihovna nenačte a nic se neodešle – příkazy gtag() jen čekají ve frontě.
 * - Zobrazení stránky posílá gtag('config') samo. Z pokladny (není ve statické cache):
 *     add_to_cart     – po vložení vstupenek z kalkulačky (vypíše se na pokladně)
 *     begin_checkout  – zobrazení pokladny
 *     purchase        – děkovací stránka, jen jednou (meta objednávky _sam_google_px)
 *     conversion      – konverze nákupu v Google Ads (ADS_PURCHASE), s číslem objednávky
 * - Hodnoty jsou včetně DPH, stejně jako u pixelu Mety (meta.php).
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Google {
	const GA4          = 'G-MTVJGV6BPM';
	const ADS          = 'AW-799183979';
	const ADS_PURCHASE = 'AW-799183979/d7VsCLjh54MZEOuoiv0C';
	const ORDER_FLAG   = '_sam_google_px';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'config' ), 1 ); // hned po výchozím stavu souhlasu (0)
		add_action( 'wp_footer', array( __CLASS__, 'events' ), 41 );
		add_action( 'sam_tickets_added_to_cart', array( __CLASS__, 'added_to_cart' ) );
	}

	/** Nastavení značek; do Googlu se nic neodešle, dokud lišta souhlasu nenačte gtag.js. */
	public static function config() {
		?>
<script id="sam-google-tag">
window.SAM_GTAG=<?php echo wp_json_encode( self::GA4 ); ?>;
gtag('js',new Date());gtag('config',<?php echo wp_json_encode( self::GA4 ); ?>);gtag('config',<?php echo wp_json_encode( self::ADS ); ?>);
</script>
		<?php
	}

	public static function added_to_cart() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'sam_google_atc', 1 );
		}
	}

	private static function item( $product, $qty, $line_total ) {
		return array(
			'item_id'   => (string) ( $product ? $product->get_id() : 0 ),
			'item_name' => $product ? wp_strip_all_tags( $product->get_name() ) : '',
			'price'     => $qty ? round( $line_total / $qty, 2 ) : 0,
			'quantity'  => (int) $qty,
		);
	}

	private static function cart_data() {
		$items = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			$items[] = self::item( $line['data'], (int) $line['quantity'], (float) $line['line_total'] + (float) $line['line_tax'] );
		}
		$data = array(
			'currency' => get_woocommerce_currency(),
			'value'    => round( (float) WC()->cart->get_total( 'edit' ), 2 ),
			'items'    => $items,
		);
		$coupons = WC()->cart->get_applied_coupons();
		if ( $coupons ) {
			$data['coupon'] = implode( ',', $coupons );
		}
		return $data;
	}

	private static function order_data( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $line ) {
			$items[] = self::item( $line->get_product(), (int) $line->get_quantity(), (float) $line->get_total() + (float) $line->get_total_tax() );
		}
		$data = array(
			'transaction_id' => (string) $order->get_order_number(),
			'currency'       => $order->get_currency(),
			'value'          => round( (float) $order->get_total(), 2 ),
			'tax'            => round( (float) $order->get_total_tax(), 2 ),
			'items'          => $items,
		);
		$coupons = $order->get_coupon_codes();
		if ( $coupons ) {
			$data['coupon'] = implode( ',', $coupons );
		}
		return $data;
	}

	/** Události pokladny a nákupu. Stránky pokladny nejsou ve statické cache. */
	public static function events() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		$calls = array();
		if ( is_order_received_page() ) {
			$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
			$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
			// woocommerce_thankyou neproběhne, když WooCommerce po hostovi chce ověření e-mailu – pak nic nevypisovat.
			if ( ! did_action( 'woocommerce_thankyou' ) || ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) || ! $order->is_paid() || $order->get_meta( self::ORDER_FLAG ) ) {
				return;
			}
			$paid = $order->get_date_paid();
			if ( ! $paid || $paid->getTimestamp() > time() - DAY_IN_SECONDS ) {
				$data  = self::order_data( $order );
				$email = strtolower( trim( (string) $order->get_billing_email() ) );
				if ( $email ) {
					// Pro rozšířené konverze; gtag údaj sám zahashuje a pošle jen se souhlasem (ad_user_data).
					$calls[] = array( 'set', 'user_data', array( 'email' => $email ) );
				}
				$calls[] = array( 'event', 'purchase', $data );
				$calls[] = array(
					'event',
					'conversion',
					array(
						'send_to'        => self::ADS_PURCHASE,
						'value'          => $data['value'],
						'currency'       => $data['currency'],
						'transaction_id' => $data['transaction_id'],
					),
				);
			}
			$order->update_meta_data( self::ORDER_FLAG, time() );
			$order->save();
		} elseif ( ! is_checkout_pay_page() && WC()->cart && ! WC()->cart->is_empty() ) {
			$data = self::cart_data();
			if ( WC()->session && WC()->session->get( 'sam_google_atc' ) ) {
				$calls[] = array( 'event', 'add_to_cart', $data );
				WC()->session->set( 'sam_google_atc', null );
			}
			$calls[] = array( 'event', 'begin_checkout', $data );
		}
		if ( ! $calls ) {
			return;
		}
		echo '<script id="sam-google-events">';
		foreach ( $calls as $call ) {
			echo 'gtag(' . implode( ',', array_map( 'wp_json_encode', $call ) ) . ');';
		}
		echo '</script>' . "\n";
	}
}

SAM_Google::init();
