<?php
/**
 * Meta (Facebook/Instagram): pixel v prohlížeči a Conversions API ze serveru, bez pluginu.
 *
 * - Zapne se, když je v prostředí serveru SAM_META_PIXEL_ID (a pro serverové události
 *   SAM_META_CAPI_TOKEN) a není aktivní plugin PixelYourSite – oba naráz by události zdvojily.
 * - Všechno jen se souhlasem s marketingem (cookie sam_consent obsahuje „m“, viz consent.php):
 *   skript pixelu čeká jako type="text/plain" a server bez souhlasu neposílá nic.
 * - Každá událost jde z prohlížeče i ze serveru se stejným ID (event_id), Meta si je spáruje.
 *     PageView          – každá stránka; server přes admin-ajax (stránky jsou ve statické cache)
 *     AddToCart         – po vložení vstupenek z kalkulačky (server hned, prohlížeč na pokladně)
 *     InitiateCheckout  – zobrazení pokladny
 *     Purchase          – server při potvrzení platby (i když se zákazník nevrátí na web),
 *                         prohlížeč na děkovací stránce; ID „purchase.<číslo objednávky>“
 * - Stav objednávky: meta _sam_meta (fbp, fbc, souhlas), _sam_meta_capi (odesláno ze serveru),
 *   _sam_meta_px (vypsáno pro prohlížeč).
 * - wp sam-meta status | wp sam-meta test [--order=<id>]  (test posílá událost SamTest, ne nákup)
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Meta {
	const API        = 'https://graph.facebook.com/v23.0/';
	const ORDER_META = '_sam_meta';
	const HOOK       = 'sam_meta_purchase';
	const RETRIES    = 4;

	/** Události pro prohlížeč na této stránce: [ název, data, ID ]. */
	private static $events = array();

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 20 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'sam-meta', 'SAM_Meta_CLI' );
		}
	}

	public static function pixel_id() {
		$id = (string) getenv( 'SAM_META_PIXEL_ID' );
		return preg_match( '/^\d{6,20}$/', $id ) ? $id : '';
	}

	private static function token() {
		return (string) getenv( 'SAM_META_CAPI_TOKEN' );
	}

	public static function other_plugin_active() {
		return defined( 'PYS_FREE_VERSION' ) || class_exists( '\PixelYourSite\PYS', false );
	}

	public static function active() {
		return '' !== self::pixel_id() && ! self::other_plugin_active();
	}

	public static function boot() {
		if ( ! self::active() ) {
			return;
		}
		add_action( 'wp_footer', array( __CLASS__, 'print_pixel' ), 40 ); // před lištou souhlasu (50), ta skript spouští
		add_action( 'wp_ajax_sam_meta_pv', array( __CLASS__, 'ajax_pageview' ) );
		add_action( 'wp_ajax_nopriv_sam_meta_pv', array( __CLASS__, 'ajax_pageview' ) );
		add_action( 'sam_tickets_added_to_cart', array( __CLASS__, 'added_to_cart' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_context' ), 20 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'queue_purchase' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'send_purchase' ), 10, 2 );
	}

	/* ---------- souhlas a identifikátory návštěvníka ---------- */

	public static function has_consent() {
		return isset( $_COOKIE['sam_consent'] ) && false !== strpos( (string) $_COOKIE['sam_consent'], 'm' );
	}

	private static function clean_fbp( $v ) {
		$v = is_string( $v ) ? trim( $v ) : '';
		return preg_match( '/^fb\.\d\.\d{6,20}\.\d{1,30}$/', $v ) ? $v : '';
	}

	private static function clean_fbc( $v ) {
		$v = is_string( $v ) ? trim( $v ) : '';
		return preg_match( '/^fb\.\d\.\d{6,20}\.[A-Za-z0-9_\-]{1,500}$/', $v ) ? $v : '';
	}

	private static function cookie( $name ) {
		return isset( $_COOKIE[ $name ] ) ? wp_unslash( $_COOKIE[ $name ] ) : '';
	}

	private static function event_id() {
		return 'sam.' . base_convert( (string) time(), 10, 36 ) . '.' . strtolower( wp_generate_password( 12, false ) );
	}

	private static function h( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' === $value ? '' : hash( 'sha256', $value );
	}

	/** user_data aktuálního návštěvníka (z požadavku). */
	private static function visitor( $fbp = '', $fbc = '' ) {
		return array_filter(
			array(
				'client_ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
				'client_user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '',
				'fbp'               => self::clean_fbp( self::cookie( '_fbp' ) ) ?: self::clean_fbp( $fbp ),
				'fbc'               => self::clean_fbc( self::cookie( '_fbc' ) ) ?: self::clean_fbc( $fbc ),
			)
		);
	}

	/* ---------- odeslání na Conversions API ---------- */

	/**
	 * @param array $event    Jedna událost (event_name, event_id, user_data, …).
	 * @param bool  $blocking Čekat na odpověď (nákup ano, ostatní ne – nesmí zdržet stránku).
	 * @return true|WP_Error|null null = neblokující odeslání bez odpovědi
	 */
	public static function send( array $event, $blocking = false ) {
		$token = self::token();
		$pixel = self::pixel_id();
		if ( '' === $token || '' === $pixel ) {
			return new WP_Error( 'sam_meta_config', 'Chybí SAM_META_PIXEL_ID nebo SAM_META_CAPI_TOKEN.' );
		}
		$event += array(
			'event_time'    => time(),
			'action_source' => 'website',
		);
		$body = array(
			'data'         => array( $event ),
			'access_token' => $token,
		);
		$test = (string) getenv( 'SAM_META_TEST_CODE' );
		if ( '' !== $test ) {
			$body['test_event_code'] = $test;
		}
		$response = wp_remote_post(
			self::API . $pixel . '/events',
			array(
				'timeout'  => $blocking ? 8 : 1,
				'blocking' => (bool) $blocking,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
			)
		);
		if ( ! $blocking ) {
			return null;
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $data['events_received'] ) ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'sam_meta_api', $msg );
		}
		return true;
	}

	/* ---------- PageView ze serveru ---------- */

	public static function ajax_pageview() {
		$id  = isset( $_POST['id'] ) ? (string) wp_unslash( $_POST['id'] ) : '';
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$own = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! self::has_consent() || ! preg_match( '/^sam\.[a-z0-9.]{5,40}$/', $id ) || wp_parse_url( $url, PHP_URL_HOST ) !== $own || empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			status_header( 204 );
			exit;
		}
		$fbc = isset( $_POST['fbc'] ) ? (string) wp_unslash( $_POST['fbc'] ) : '';
		if ( '' === self::clean_fbc( self::cookie( '_fbc' ) ) && '' === self::clean_fbc( $fbc ) ) {
			// Cookie _fbc ještě není, ale návštěvník přišel z reklamy: identifikátor kliknutí je v adrese.
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			if ( ! empty( $q['fbclid'] ) && is_string( $q['fbclid'] ) ) {
				$fbc = 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . $q['fbclid'];
			}
		}
		self::send(
			array(
				'event_name'       => 'PageView',
				'event_id'         => $id,
				'event_source_url' => $url,
				'user_data'        => self::visitor( isset( $_POST['fbp'] ) ? (string) wp_unslash( $_POST['fbp'] ) : '', $fbc ),
			)
		);
		status_header( 204 );
		exit;
	}

	/* ---------- košík a pokladna ---------- */

	private static function cart_data() {
		$contents = array();
		$ids      = array();
		$count    = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$qty        = (int) $item['quantity'];
			$ids[]      = (string) $item['product_id'];
			$contents[] = array(
				'id'         => (string) $item['product_id'],
				'quantity'   => $qty,
				'item_price' => $qty ? round( ( (float) $item['line_total'] + (float) $item['line_tax'] ) / $qty, 2 ) : 0,
			);
			$count += $qty;
		}
		return array(
			'value'        => round( (float) WC()->cart->get_total( 'edit' ), 2 ),
			'currency'     => get_woocommerce_currency(),
			'content_type' => 'product',
			'content_ids'  => $ids,
			'contents'     => $contents,
			'num_items'    => $count,
		);
	}

	/** Kalkulačka vložila vstupenky do košíku (AJAX): server hned, prohlížeč až na pokladně. */
	public static function added_to_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session || WC()->cart->is_empty() ) {
			return;
		}
		$id   = self::event_id();
		$data = self::cart_data();
		WC()->session->set( 'sam_meta_atc', array( $id, $data ) );
		if ( self::has_consent() ) {
			self::send(
				array(
					'event_name'       => 'AddToCart',
					'event_id'         => $id,
					'event_source_url' => wp_get_referer() ?: home_url( '/' ),
					'user_data'        => self::visitor(),
					'custom_data'      => $data,
				)
			);
		}
	}

	private static function checkout_events() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$pending = WC()->session ? WC()->session->get( 'sam_meta_atc' ) : null;
		if ( is_array( $pending ) && 2 === count( $pending ) ) {
			self::$events[] = array( 'AddToCart', $pending[1], $pending[0] );
			WC()->session->set( 'sam_meta_atc', null );
		}
		$id             = self::event_id();
		$data           = self::cart_data();
		self::$events[] = array( 'InitiateCheckout', $data, $id );
		if ( self::has_consent() ) {
			self::send(
				array(
					'event_name'       => 'InitiateCheckout',
					'event_id'         => $id,
					'event_source_url' => wc_get_checkout_url(),
					'user_data'        => self::visitor(),
					'custom_data'      => $data,
				)
			);
		}
	}

	/* ---------- nákup ---------- */

	public static function save_order_context( $order ) {
		$order->update_meta_data(
			self::ORDER_META,
			array(
				'm'   => self::has_consent() ? 1 : 0,
				'fbp' => self::clean_fbp( self::cookie( '_fbp' ) ),
				'fbc' => self::clean_fbc( self::cookie( '_fbc' ) ),
			)
		);
	}

	public static function queue_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		$ctx   = $order ? $order->get_meta( self::ORDER_META ) : null;
		if ( ! is_array( $ctx ) || empty( $ctx['m'] ) || $order->get_meta( '_sam_meta_capi' ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( (int) $order_id, 1 ), 'sam-meta' );
		} else {
			self::send_purchase( (int) $order_id, self::RETRIES );
		}
	}

	private static function order_data( WC_Order $order ) {
		$contents = array();
		$ids      = array();
		$count    = 0;
		foreach ( $order->get_items() as $item ) {
			$qty        = (int) $item->get_quantity();
			$ids[]      = (string) $item->get_product_id();
			$contents[] = array(
				'id'         => (string) $item->get_product_id(),
				'quantity'   => $qty,
				'item_price' => $qty ? round( ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $qty, 2 ) : 0,
			);
			$count += $qty;
		}
		return array(
			'value'        => round( (float) $order->get_total(), 2 ),
			'currency'     => $order->get_currency(),
			'content_type' => 'product',
			'content_ids'  => $ids,
			'contents'     => $contents,
			'num_items'    => $count,
			'order_id'     => (string) $order->get_order_number(),
		);
	}

	/** Údaje zákazníka z objednávky pro párování (otevřené; hash dělá volající nebo pixel). */
	private static function order_person( WC_Order $order ) {
		return array_filter(
			array(
				'em'      => strtolower( trim( (string) $order->get_billing_email() ) ),
				'fn'      => mb_strtolower( trim( (string) $order->get_billing_first_name() ) ),
				'ln'      => mb_strtolower( trim( (string) $order->get_billing_last_name() ) ),
				'ct'      => preg_replace( '/\s+/u', '', mb_strtolower( (string) $order->get_billing_city() ) ),
				'zp'      => preg_replace( '/\s+/', '', strtolower( (string) $order->get_billing_postcode() ) ),
				'country' => strtolower( (string) $order->get_billing_country() ),
			)
		);
	}

	public static function purchase_event( WC_Order $order, $name = 'Purchase' ) {
		$ctx   = (array) $order->get_meta( self::ORDER_META );
		$user  = array_map( array( __CLASS__, 'h' ), self::order_person( $order ) );
		$user += array_filter(
			array(
				'client_ip_address' => (string) $order->get_customer_ip_address(),
				'client_user_agent' => substr( (string) $order->get_customer_user_agent(), 0, 500 ),
				'fbp'               => isset( $ctx['fbp'] ) ? self::clean_fbp( $ctx['fbp'] ) : '',
				'fbc'               => isset( $ctx['fbc'] ) ? self::clean_fbc( $ctx['fbc'] ) : '',
			)
		);
		$paid = $order->get_date_paid();
		return array(
			'event_name'       => $name,
			'event_id'         => 'purchase.' . $order->get_id(),
			'event_time'       => $paid ? max( $paid->getTimestamp(), time() - 6 * DAY_IN_SECONDS ) : time(),
			'event_source_url' => $order->get_checkout_order_received_url(),
			'user_data'        => $user,
			'custom_data'      => self::order_data( $order ),
		);
	}

	public static function send_purchase( $order_id, $attempt = 1 ) {
		$order = wc_get_order( $order_id );
		$ctx   = $order ? $order->get_meta( self::ORDER_META ) : null;
		if ( ! $order || ! is_array( $ctx ) || empty( $ctx['m'] ) || $order->get_meta( '_sam_meta_capi' ) || ! $order->is_paid() ) {
			return;
		}
		$result = self::send( self::purchase_event( $order ), true );
		if ( true === $result ) {
			$order->update_meta_data( '_sam_meta_capi', time() );
			$order->save();
			return;
		}
		$attempt = (int) $attempt;
		wc_get_logger()->warning( sprintf( 'Nákup #%d se nepodařilo odeslat Metě (pokus %d): %s', $order_id, $attempt, $result->get_error_message() ), array( 'source' => 'sam-meta' ) );
		if ( $attempt < self::RETRIES && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $attempt * 10 * MINUTE_IN_SECONDS, self::HOOK, array( (int) $order_id, $attempt + 1 ), 'sam-meta' );
		}
	}

	/** Nákup pro prohlížeč na děkovací stránce (jen jednou) + údaje pro pokročilé párování. */
	private static function thankyou_events() {
		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
		$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) || ! $order->is_paid() ) {
			return array();
		}
		if ( ! $order->get_meta( '_sam_meta_px' ) ) {
			$paid = $order->get_date_paid();
			if ( ! $paid || $paid->getTimestamp() > time() - DAY_IN_SECONDS ) {
				self::$events[] = array( 'Purchase', self::order_data( $order ), 'purchase.' . $order->get_id() );
			}
			$order->update_meta_data( '_sam_meta_px', time() );
			$order->save();
		}
		return self::order_person( $order );
	}

	/* ---------- skript pixelu ---------- */

	public static function print_pixel() {
		$match = array();
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			// Stránky pokladny nejsou ve statické cache, smějí nést údaje konkrétního návštěvníka.
			if ( is_order_received_page() ) {
				$match = self::thankyou_events();
			} elseif ( ! is_checkout_pay_page() ) {
				self::checkout_events();
			}
		}
		if ( self::$events || $match ) {
			echo '<script id="sam-meta-data">window.SAM_META=' . wp_json_encode( array( 'am' => (object) $match, 'ev' => self::$events ) ) . ';</script>' . "\n";
		}
		?>
<script type="text/plain" data-sam-consent="m" id="sam-meta-pixel">
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
(function(){var q=window.SAM_META||{},pv='sam.'+Date.now().toString(36)+'.'+Math.random().toString(36).slice(2,14);
fbq('init',<?php echo wp_json_encode( self::pixel_id() ); ?>,q.am||{});
fbq('track','PageView',{},{eventID:pv});
(q.ev||[]).forEach(function(e){fbq('track',e[0],e[1],{eventID:e[2]});});
setTimeout(function(){if(!navigator.sendBeacon)return;var c=function(n){return(document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'))||[])[1]||'';},d=new FormData();
d.append('action','sam_meta_pv');d.append('id',pv);d.append('url',location.href);d.append('fbp',c('_fbp'));d.append('fbc',c('_fbc'));
navigator.sendBeacon(<?php echo wp_json_encode( admin_url( 'admin-ajax.php', 'relative' ) ); ?>,d);},1500);
})();
</script>
		<?php
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * wp sam-meta status            – nastavení a stav
	 * wp sam-meta test [--order=ID] – pošle zkušební událost SamTest (ne nákup) a vypíše odpověď Mety
	 */
	class SAM_Meta_CLI {
		public function status() {
			WP_CLI::line( 'pixel: ' . ( SAM_Meta::pixel_id() ?: '(chybí SAM_META_PIXEL_ID)' ) );
			WP_CLI::line( 'token: ' . ( getenv( 'SAM_META_CAPI_TOKEN' ) ? 'nastaven' : '(chybí SAM_META_CAPI_TOKEN)' ) );
			WP_CLI::line( 'testovací kód: ' . ( getenv( 'SAM_META_TEST_CODE' ) ? 'ANO – události jdou jako testovací' : 'ne' ) );
			WP_CLI::line( 'PixelYourSite: ' . ( SAM_Meta::other_plugin_active() ? 'aktivní → tento modul je vypnutý' : 'neaktivní' ) );
			WP_CLI::line( 'modul: ' . ( SAM_Meta::active() ? 'ZAPNUTÝ' : 'vypnutý' ) );
		}

		public function test( $args, $assoc_args ) {
			if ( ! empty( $assoc_args['order'] ) ) {
				$order = wc_get_order( absint( $assoc_args['order'] ) );
				if ( ! $order ) {
					WP_CLI::error( 'Objednávka neexistuje.' );
				}
				$event               = SAM_Meta::purchase_event( $order, 'SamTest' );
				$event['event_id']   = 'samtest.' . $order->get_id() . '.' . time();
				$event['event_time'] = time();
			} else {
				$event = array(
					'event_name'       => 'SamTest',
					'event_id'         => 'samtest.' . time(),
					'event_source_url' => home_url( '/' ),
					'user_data'        => array(
						'client_ip_address' => '185.58.41.93',
						'client_user_agent' => 'sam-meta-test',
					),
				);
			}
			$shown = $event;
			foreach ( $shown['user_data'] as $k => $v ) {
				$shown['user_data'][ $k ] = is_string( $v ) && strlen( $v ) > 12 ? substr( $v, 0, 6 ) . '…' : $v;
			}
			WP_CLI::line( wp_json_encode( $shown, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$result = SAM_Meta::send( $event, true );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( 'Meta událost přijala.' );
		}
	}
}

SAM_Meta::init();
