<?php
/**
 * Kontaktní formulář: shortcode [sam_contact_form], odeslání přes admin-post.php.
 *
 * Nahrazuje formulář Gutena Forms (plugin už na webu nebyl, formulář neodesílal).
 * Stránka je ve statické cache, proto bez nonce: ochranu dělá skryté pole proti robotům,
 * pole vyplněné JavaScriptem a limit odeslání na IP adresu.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Contact_Form {
	const ACTION    = 'sam_contact';
	const RECIPIENT = 'info@steelartmuseum.eu';
	const ANCHOR    = 'kontaktni-formular';
	const LIMIT     = 5; // odeslání za hodinu z jedné IP adresy

	public static function init() {
		add_shortcode( 'sam_contact_form', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	private static function lang() {
		return 'en' === apply_filters( 'wpml_current_language', null ) ? 'en' : 'cs';
	}

	private static function t( $key, $lang = null ) {
		$lang  = $lang ?: self::lang();
		$texts = array(
			'cs' => array(
				'name'     => 'Jméno',
				'email'    => 'E-mail',
				'message'  => 'Váš dotaz',
				'send'     => 'Odeslat dotaz',
				'privacy'  => 'Odpověď pošleme na zadaný e-mail. Jak s údaji zacházíme, najdete v <a href="%s">ochraně osobních údajů</a>.',
				'ok'       => 'Děkujeme, dotaz jsme dostali. Ozveme se co nejdřív.',
				'missing'  => 'Vyplňte prosím jméno, platný e-mail a dotaz.',
				'limit'    => 'Odeslali jste už několik zpráv za sebou. Zkuste to prosím za chvíli, nebo napište na info@steelartmuseum.eu.',
				'error'    => 'Zprávu se nepodařilo odeslat. Napište nám prosím přímo na info@steelartmuseum.eu.',
				'website'  => 'Nechte prázdné',
			),
			'en' => array(
				'name'     => 'Name',
				'email'    => 'E-mail',
				'message'  => 'Your question',
				'send'     => 'Send',
				'privacy'  => 'We will reply to the e-mail you enter. See our <a href="%s">privacy policy</a> for how we handle your data.',
				'ok'       => 'Thank you, we have received your message and will reply soon.',
				'missing'  => 'Please fill in your name, a valid e-mail and your question.',
				'limit'    => 'You have sent several messages in a row. Please try again later or write to info@steelartmuseum.eu.',
				'error'    => 'Your message could not be sent. Please write to us at info@steelartmuseum.eu.',
				'website'  => 'Leave empty',
			),
		);
		return $texts[ $lang ][ $key ];
	}

	public static function render() {
		$status  = isset( $_GET['kontakt'] ) ? sanitize_key( wp_unslash( $_GET['kontakt'] ) ) : '';
		$notices = array( 'ok' => 'ok', 'udaje' => 'missing', 'limit' => 'limit', 'chyba' => 'error' );
		$privacy = get_permalink( (int) apply_filters( 'wpml_object_id', 1148, 'page', true ) );

		ob_start();
		?>
		<form class="sam-form" id="<?php echo esc_attr( self::ANCHOR ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php if ( isset( $notices[ $status ] ) ) : ?>
			<p class="sam-form-notice sam-form-notice--<?php echo 'ok' === $status ? 'ok' : 'err'; ?>" role="status"><?php echo esc_html( self::t( $notices[ $status ] ) ); ?></p>
			<?php endif; ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="lang" value="<?php echo esc_attr( self::lang() ); ?>">
			<input type="hidden" name="page_id" value="<?php echo esc_attr( get_queried_object_id() ); ?>">
			<input type="hidden" name="sam_js" value="">
			<p class="sam-form-hp" aria-hidden="true"><label><?php echo esc_html( self::t( 'website' ) ); ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
			<p><label for="sam-c-name"><?php echo esc_html( self::t( 'name' ) ); ?></label><input id="sam-c-name" type="text" name="contact_name" required maxlength="120" autocomplete="name"></p>
			<p><label for="sam-c-email"><?php echo esc_html( self::t( 'email' ) ); ?></label><input id="sam-c-email" type="email" name="contact_email" required maxlength="190" autocomplete="email"></p>
			<p><label for="sam-c-msg"><?php echo esc_html( self::t( 'message' ) ); ?></label><textarea id="sam-c-msg" name="contact_message" required rows="6" maxlength="5000"></textarea></p>
			<p class="sam-form-privacy"><?php echo wp_kses_post( sprintf( self::t( 'privacy' ), esc_url( $privacy ) ) ); ?></p>
			<p><button class="pill" type="submit"><?php echo esc_html( self::t( 'send' ) ); ?></button></p>
		</form>
		<script>document.querySelectorAll('.sam-form [name="sam_js"]').forEach(function(i){i.value='ok';});</script>
		<?php
		return ob_get_clean();
	}

	public static function handle() {
		$lang    = isset( $_POST['lang'] ) && 'en' === $_POST['lang'] ? 'en' : 'cs';
		$page_id = absint( $_POST['page_id'] ?? 0 );
		$back    = $page_id ? get_permalink( $page_id ) : home_url( '/kontakt/' );

		// Robot: tváříme se, že se odeslalo.
		if ( ! empty( $_POST['website'] ) || 'ok' !== ( $_POST['sam_js'] ?? '' ) ) {
			self::back( $back, 'ok' );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['contact_message'] ?? '' ) );
		if ( '' === $name || ! is_email( $email ) || '' === trim( $message ) ) {
			self::back( $back, 'udaje' );
		}

		$key   = 'sam_contact_' . md5( self::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= self::LIMIT ) {
			self::back( $back, 'limit' );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$subject = sprintf( '[Steel Art Museum] Dotaz z webu – %s', $name );
		$body    = implode( "\n", array(
			'Jméno: ' . $name,
			'E-mail: ' . $email,
			'Jazyk webu: ' . ( 'en' === $lang ? 'angličtina' : 'čeština' ),
			'Stránka: ' . $back,
			'Odesláno: ' . wp_date( 'j. n. Y H:i' ),
			'',
			$message,
		) );
		$headers = array( sprintf( 'Reply-To: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', $name ), $email ) );
		$sent    = wp_mail( self::RECIPIENT, $subject, $body, $headers );

		self::back( $back, $sent ? 'ok' : 'chyba' );
	}

	private static function back( $url, $status ) {
		wp_safe_redirect( add_query_arg( 'kontakt', $status, $url ) . '#' . self::ANCHOR );
		exit;
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
SAM_Contact_Form::init();
