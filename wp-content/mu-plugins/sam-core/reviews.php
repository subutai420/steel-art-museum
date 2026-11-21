<?php
/**
 * Recenze bez pluginu: souhrn hodnocení (Google, Tripadvisor) a vybrané recenze pro web.
 *
 * - Data jsou ve volbách sam_reviews (souhrn + nastavení) a sam_reviews_items (recenze).
 * - Při prvním spuštění se recenze převezmou z tabulky pluginu WP Review Slider Pro
 *   (wpfb_reviews, 50 recenzí Google), plugin pak může jít pryč.
 * - Google se obnovuje jednou denně přes Places API (New), když je v prostředí
 *   SAM_GOOGLE_PLACES_KEY a v nastavení ID místa. Bez klíče zůstávají uložená data.
 * - Tripadvisor (hodnocení, počet, odkaz) se zadává ručně v Nastavení → Recenze;
 *   automaticky jen se schváleným Tripadvisor Content API.
 * - Na stránce se nic nenačítá zvenku: žádný JavaScript ani požadavky na Google.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Reviews {
	const OPTION       = 'sam_reviews';
	const ITEMS_OPTION = 'sam_reviews_items';
	const CRON         = 'sam_reviews_refresh';
	const MAX_ITEMS    = 60;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 50 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'refresh_google' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_sam_reviews_save', array( __CLASS__, 'admin_save' ) );
	}

	public static function defaults() {
		return array(
			'google_rating'   => 4.8,
			'google_count'    => 6388,
			'google_url'      => 'https://www.google.com/maps/search/?api=1&query=Steel+Art+Museum+Celetn%C3%A1+15+Praha',
			'google_place_id' => '',
			// Tripadvisor: profil „Steel Art Museum Prague“ (dříve Gallery of Steel Figures). Počet recenzí ověřit na profilu.
			'ta_rating'       => 4.8,
			'ta_count'        => '',
			'ta_url'          => 'https://www.tripadvisor.com/Attraction_Review-g274707-d14200357-Reviews-Steel_Art_Museum_Prague-Prague_Bohemia.html',
			'ta_reviews'      => '',
			'updated'         => '',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/** Souhrn pro web: jen zdroje, které mají vyplněné hodnocení. */
	public static function sources() {
		$s   = self::settings();
		$out = array();
		if ( (float) $s['google_rating'] > 0 ) {
			$out['google'] = array( 'name' => 'Google', 'rating' => (float) $s['google_rating'], 'count' => (int) $s['google_count'], 'url' => $s['google_url'] );
		}
		if ( (float) $s['ta_rating'] > 0 ) {
			$out['tripadvisor'] = array( 'name' => 'Tripadvisor', 'rating' => (float) $s['ta_rating'], 'count' => (int) $s['ta_count'], 'url' => $s['ta_url'] );
		}
		return $out;
	}

	/**
	 * Recenze pro web: pětihvězdičkové, rozumně dlouhé, nejnovější první.
	 * Jazyk webu má přednost (české recenze na české verzi), doplní se ostatními.
	 */
	public static function items( $limit = 12, $lang = 'cs' ) {
		$items = array_values( array_filter( (array) get_option( self::ITEMS_OPTION, array() ), static function( $r ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $r['text'] ) : strlen( $r['text'] );
			return (int) $r['rating'] >= 5 && $len >= 60 && $len <= 420 && empty( $r['hidden'] ) && ! self::mentions_protected( $r['text'] );
		} ) );
		usort( $items, static function( $a, $b ) use ( $lang ) {
			$la = ( $a['lang'] ?? '' ) === $lang ? 0 : 1;
			$lb = ( $b['lang'] ?? '' ) === $lang ? 0 : 1;
			return $la <=> $lb ?: strcmp( $b['date'], $a['date'] );
		} );
		// Ručně vložené recenze z Tripadvisoru se střídají s Googlem (G, T, G, T…).
		$ta  = array_values( array_filter( self::tripadvisor_items(), static function( $r ) { return ! self::mentions_protected( $r['text'] ); } ) );
		$out = array();
		while ( ( $items || $ta ) && count( $out ) < $limit ) {
			if ( $items ) {
				$out[] = array_shift( $items );
			}
			if ( $ta && count( $out ) < $limit ) {
				$out[] = array_shift( $ta );
			}
		}
		return $out;
	}

	/**
	 * Recenze, které jmenují postavy chráněné autorským právem (nebo jméno zakladatele), se na webu
	 * neukazují – muzeum na postavy nemá práva a texty webu je záměrně nejmenují.
	 */
	public static function mentions_protected( $text ) {
		$pattern = '/olejnik|mariusz|\bthor|hulk|marvel|avenger|spider|kapit[aá]n ameri|captain america|iron ?man|batman|superman|optimus|bumblebee|transformer|star ?wars|darth|vader|yoda|stormtrooper|homer|simpson|shrek|mimo[nň]|minion|wall-?e|pinocchio|pinokio|disney|pixar|mickey|e\.t\.|alien|predator|terminator|groot|deadpool|wolverine|harry potter|jack sparrow|iron throne|železn[yý] tr[uů]n|game of thrones|hra o tr[uů]ny|elvis|jackson|lamborghini|ferrari|porsche|bugatti/iu';
		return (bool) preg_match( $pattern, (string) $text );
	}

	/**
	 * Recenze z Tripadvisoru zadané v administraci. Formát: bloky oddělené prázdným řádkem,
	 * první řádek bloku = jméno autora, zbytek = text recenze.
	 */
	public static function tripadvisor_items() {
		$raw   = str_replace( "\r", '', (string) self::settings()['ta_reviews'] );
		$items = array();
		foreach ( preg_split( "/\n\s*\n/", trim( $raw ) ) as $block ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $block ) ) ) );
			if ( count( $lines ) < 2 ) {
				continue;
			}
			$items[] = array( 'source' => 'tripadvisor', 'author' => array_shift( $lines ), 'rating' => 5, 'text' => implode( ' ', $lines ), 'date' => '', 'lang' => '', 'hidden' => false );
		}
		return $items;
	}

	public static function maybe_seed() {
		if ( false !== get_option( self::ITEMS_OPTION, false ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wpfb_reviews';
		$items = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$rows = $wpdb->get_results( "SELECT type, reviewer_name, rating, review_text, created_time, language_code, hide FROM {$table} ORDER BY created_time DESC" ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$items[] = array(
					'source' => strtolower( (string) $r->type ),
					'author' => (string) $r->reviewer_name,
					'rating' => (int) $r->rating,
					'text'   => trim( wp_strip_all_tags( (string) $r->review_text ) ),
					'date'   => substr( (string) $r->created_time, 0, 10 ),
					'lang'   => (string) $r->language_code,
					'hidden' => 'yes' === $r->hide,
				);
			}
			$summary = json_decode( (string) get_option( 'wppro_total_avg_reviews' ), true );
			if ( is_array( $summary ) ) {
				$g = reset( $summary );
				if ( ! empty( $g['avg'] ) && ! empty( $g['total'] ) ) {
					update_option( self::OPTION, array_merge( self::settings(), array( 'google_rating' => (float) $g['avg'], 'google_count' => (int) $g['total'] ) ), false );
				}
			}
		}
		update_option( self::ITEMS_OPTION, array_slice( $items, 0, self::MAX_ITEMS ), false );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** Places API (New): hodnocení, počet a až 5 nejnovějších recenzí. */
	public static function refresh_google() {
		$key = getenv( 'SAM_GOOGLE_PLACES_KEY' ) ?: ( defined( 'SAM_GOOGLE_PLACES_KEY' ) ? SAM_GOOGLE_PLACES_KEY : '' );
		$s   = self::settings();
		if ( ! $key || ! $s['google_place_id'] ) {
			return false;
		}
		$response = wp_remote_get(
			'https://places.googleapis.com/v1/places/' . rawurlencode( $s['google_place_id'] ) . '?languageCode=cs',
			array(
				'timeout' => 15,
				'headers' => array( 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'rating,userRatingCount,googleMapsUri,reviews' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['rating'] ) ) {
			$s['google_rating'] = (float) $data['rating'];
			$s['google_count']  = (int) ( $data['userRatingCount'] ?? $s['google_count'] );
			$s['google_url']    = $data['googleMapsUri'] ?? $s['google_url'];
			$s['updated']       = gmdate( 'c' );
			update_option( self::OPTION, $s, false );
		}
		$items = (array) get_option( self::ITEMS_OPTION, array() );
		$known = array();
		foreach ( $items as $r ) {
			$known[ $r['author'] . '|' . $r['date'] ] = true;
		}
		foreach ( (array) ( $data['reviews'] ?? array() ) as $r ) {
			$text = $r['originalText']['text'] ?? ( $r['text']['text'] ?? '' );
			$item = array(
				'source' => 'google',
				'author' => (string) ( $r['authorAttribution']['displayName'] ?? '' ),
				'rating' => (int) ( $r['rating'] ?? 0 ),
				'text'   => trim( (string) $text ),
				'date'   => substr( (string) ( $r['publishTime'] ?? '' ), 0, 10 ),
				'lang'   => (string) ( $r['originalText']['languageCode'] ?? '' ),
				'hidden' => false,
			);
			if ( '' !== $item['text'] && empty( $known[ $item['author'] . '|' . $item['date'] ] ) ) {
				array_unshift( $items, $item );
			}
		}
		update_option( self::ITEMS_OPTION, array_slice( $items, 0, self::MAX_ITEMS ), false );
		return true;
	}

	/* ---------------- administrace: Nastavení → Recenze ---------------- */

	public static function admin_menu() {
		add_options_page( 'Recenze', 'Recenze', 'manage_options', 'sam-reviews', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_page() {
		$s     = self::settings();
		$items = (array) get_option( self::ITEMS_OPTION, array() );
		$field = static function( $key, $label, $help = '' ) use ( $s ) {
			printf( '<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" name="%1$s" value="%3$s">%4$s</td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( (string) $s[ $key ] ), $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' );
		};
		echo '<div class="wrap"><h1>Recenze na webu</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success"><p>Uloženo.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sam_reviews_save">';
		wp_nonce_field( 'sam_reviews_save' );
		echo '<h2>Google</h2><table class="form-table">';
		$field( 'google_rating', 'Hodnocení', 'Např. 4.8. S klíčem SAM_GOOGLE_PLACES_KEY a ID místa se obnovuje samo jednou denně.' );
		$field( 'google_count', 'Počet recenzí' );
		$field( 'google_url', 'Odkaz na recenze' );
		$field( 'google_place_id', 'ID místa (Place ID)', 'Najdete v Google Place ID Finderu, začíná ChIJ…' );
		echo '</table><h2>Tripadvisor</h2><table class="form-table">';
		$field( 'ta_rating', 'Hodnocení', 'Např. 4.5. Prázdné = na webu se Tripadvisor neukazuje.' );
		$field( 'ta_count', 'Počet recenzí' );
		$field( 'ta_url', 'Odkaz na profil' );
		printf(
			'<tr><th><label for="ta_reviews">Vybrané recenze</label></th><td><textarea class="large-text" rows="10" id="ta_reviews" name="ta_reviews">%s</textarea><p class="description">Zkopírujte z Tripadvisoru pětihvězdičkové recenze. Každá recenze je blok oddělený prázdným řádkem: na prvním řádku jméno autora, pod ním text. Na webu se střídají s recenzemi z Googlu.</p></td></tr>',
			esc_textarea( (string) $s['ta_reviews'] )
		);
		echo '</table>';
		submit_button( 'Uložit' );
		printf( '</form><p>Uložených recenzí: %d (na webu se ukazují pětihvězdičkové). Poslední obnova z Google: %s</p></div>', count( $items ), esc_html( $s['updated'] ?: 'ještě nebyla' ) );
	}

	public static function admin_save() {
		check_admin_referer( 'sam_reviews_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemáte oprávnění.' );
		}
		$s = self::settings();
		foreach ( array( 'google_rating', 'ta_rating' ) as $k ) {
			$s[ $k ] = '' === trim( (string) ( $_POST[ $k ] ?? '' ) ) ? '' : round( (float) str_replace( ',', '.', wp_unslash( $_POST[ $k ] ) ), 1 );
		}
		foreach ( array( 'google_count', 'ta_count' ) as $k ) {
			$s[ $k ] = '' === trim( (string) ( $_POST[ $k ] ?? '' ) ) ? '' : absint( preg_replace( '/\D/', '', wp_unslash( $_POST[ $k ] ) ) );
		}
		foreach ( array( 'google_url', 'ta_url' ) as $k ) {
			$s[ $k ] = esc_url_raw( wp_unslash( $_POST[ $k ] ?? '' ) );
		}
		$s['google_place_id'] = sanitize_text_field( wp_unslash( $_POST['google_place_id'] ?? '' ) );
		$s['ta_reviews']      = sanitize_textarea_field( wp_unslash( $_POST['ta_reviews'] ?? '' ) );
		update_option( self::OPTION, $s, false );
		self::refresh_google();
		wp_safe_redirect( admin_url( 'options-general.php?page=sam-reviews&saved=1' ) );
		exit;
	}
}
SAM_Reviews::init();
