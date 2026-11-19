<?php
/**
 * Pokladna jen s e-mailem: e-mail → platba → „Objednat a zaplatit“.
 *
 * - Povinný je jen e-mail (kam přijdou vstupenky).
 * - Zaškrtávátko „Chci fakturu“ odkryje fakturační údaje (firma, IČO, DIČ, jméno, adresa);
 *   faktura se ve Fakturoidu vystaví vždy, odkaz na ni jde zákazníkovi jen při zaškrtnutí
 *   (meta _sam_invoice_requested = yes, čte ji budoucí sam-fakturoid).
 * - Platba (Stripe Payment Element) je zamčená, dokud není vyplněný platný e-mail –
 *   Apple Pay a Google Pay tak nejdou zaplatit bez e-mailu.
 * - Daň se počítá ze sídla obchodu (woocommerce_tax_based_on = base), stát zákazníka není potřeba.
 *
 * Rozložení stránky je v šabloně: themes/steel-art-museum/woocommerce/checkout/form-checkout.php.
 */

defined( 'ABSPATH' ) || exit;

final class SAM_Checkout {
	const INVOICE_FIELDS = array( 'billing_company', 'billing_ic', 'billing_dic', 'billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_city', 'billing_postcode', 'billing_country' );

	public static function init() {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ), 50 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'button_text' ) );
		add_filter( 'woocommerce_checkout_required_field_notice', array( __CLASS__, 'required_notice' ), 10, 3 );
		add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'terms_notice' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_details' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		// Slevový kód je v souhrnu objednávky (coupon_box), ne jako samostatný pruh nad pokladnou.
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
	}

	/**
	 * Pole na slevový kód uvnitř formuláře pokladny. Vnořený <form> nejde, proto bez atributu name
	 * (neodešle se s objednávkou) a kód se uplatní přes wc-ajax=apply_coupon v assets/checkout.js.
	 */
	public static function coupon_box() {
		?>
		<div class="sam-coupon">
			<button type="button" class="sam-coupon-toggle" aria-expanded="false" aria-controls="sam-coupon-row"><?php echo esc_html( self::t( 'coupon_q' ) ); ?></button>
			<div class="sam-coupon-row" id="sam-coupon-row" hidden>
				<label class="sr" for="sam-coupon-code"><?php echo esc_html( self::t( 'coupon_label' ) ); ?></label>
				<input type="text" id="sam-coupon-code" placeholder="<?php echo esc_attr( self::t( 'coupon_label' ) ); ?>" autocomplete="off" autocapitalize="characters">
				<button type="button" class="button sam-coupon-apply"><?php echo esc_html( self::t( 'coupon_apply' ) ); ?></button>
			</div>
			<div class="sam-coupon-msg" aria-live="polite"></div>
		</div>
		<?php
	}

	public static function lang() {
		return 'en' === apply_filters( 'wpml_current_language', null ) ? 'en' : 'cs';
	}

	public static function t( $key ) {
		$texts = array(
			'cs' => array(
				'email'        => 'E-mail',
				'email_ph'     => 'Sem pošleme vstupenky',
				'invoice'      => 'Chci fakturu',
				'invoice_hint' => 'Na firmu nebo na jméno, odkaz na ni přijde e-mailem.',
				'company'      => 'Firma',
				'ic'           => 'IČO',
				'dic'          => 'DIČ',
				'first'        => 'Jméno',
				'last'         => 'Příjmení',
				'street'       => 'Ulice a číslo',
				'city'         => 'Město',
				'postcode'     => 'PSČ',
				'country'      => 'Země',
				'button'       => 'Objednat a zaplatit',
				'terms'        => 'Odesláním objednávky souhlasíte s <a href="%1$s" target="_blank">obchodními podmínkami</a>. Jak zpracováváme osobní údaje, najdete v <a href="%2$s" target="_blank">ochraně osobních údajů</a>.',
				'need_name'    => 'Pro fakturu vyplňte název firmy, nebo jméno a příjmení.',
				'need_address' => 'Pro fakturu vyplňte ulici, město, PSČ a zemi.',
				'bad_ic'       => 'IČO má 8 číslic.',
				'bad_dic'      => 'DIČ zadejte ve tvaru CZ12345678.',
				'locked'       => 'Nejdřív vyplňte e-mail, kam pošleme vstupenky.',
				'admin_inv'    => 'Faktura vyžádána',
				'coupon_q'     => 'Máte slevový kód?',
				'coupon_label' => 'Slevový kód',
				'coupon_apply' => 'Použít',
				'yes'          => 'ano',
				'no'           => 'ne',
			),
			'en' => array(
				'email'        => 'E-mail',
				'email_ph'     => 'We will send your tickets here',
				'invoice'      => 'I need an invoice',
				'invoice_hint' => 'For a company or a person, the link arrives by e-mail.',
				'company'      => 'Company',
				'ic'           => 'Company ID (IČO)',
				'dic'          => 'VAT ID',
				'first'        => 'First name',
				'last'         => 'Last name',
				'street'       => 'Street and number',
				'city'         => 'City',
				'postcode'     => 'Postcode',
				'country'      => 'Country',
				'button'       => 'Order and pay',
				'terms'        => 'By placing the order you agree to the <a href="%1$s" target="_blank">terms and conditions</a>. See the <a href="%2$s" target="_blank">privacy policy</a> for how we process personal data.',
				'need_name'    => 'For an invoice, enter a company name or your first and last name.',
				'need_address' => 'For an invoice, enter street, city, postcode and country.',
				'bad_ic'       => 'The Czech company ID (IČO) has 8 digits.',
				'bad_dic'      => 'Enter the VAT ID with its country prefix, e.g. CZ12345678.',
				'locked'       => 'First enter the e-mail where we should send your tickets.',
				'admin_inv'    => 'Invoice requested',
				'coupon_q'     => 'Have a discount code?',
				'coupon_label' => 'Discount code',
				'coupon_apply' => 'Apply',
				'yes'          => 'yes',
				'no'           => 'no',
			),
		);
		return $texts[ self::lang() ][ $key ] ?? $texts['cs'][ $key ];
	}

	public static function fields( $fields ) {
		$inv = array( 'sam-invoice-field' );
		$billing = array(
			'billing_email'      => array( 'type' => 'email', 'label' => self::t( 'email' ), 'placeholder' => self::t( 'email_ph' ), 'required' => true, 'class' => array( 'form-row-wide' ), 'autocomplete' => 'email', 'priority' => 10, 'validate' => array( 'email' ) ),
			'billing_invoice'    => array( 'type' => 'checkbox', 'label' => self::t( 'invoice' ), 'required' => false, 'class' => array( 'form-row-wide', 'sam-invoice-toggle' ), 'priority' => 20, 'description' => self::t( 'invoice_hint' ) ),
			'billing_company'    => array( 'label' => self::t( 'company' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-wide' ) ), 'autocomplete' => 'organization', 'priority' => 30 ),
			'billing_ic'         => array( 'label' => self::t( 'ic' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-first' ) ), 'priority' => 40, 'custom_attributes' => array( 'inputmode' => 'numeric', 'maxlength' => '8' ) ),
			'billing_dic'        => array( 'label' => self::t( 'dic' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-last' ) ), 'priority' => 50, 'custom_attributes' => array( 'maxlength' => '14' ) ),
			'billing_first_name' => array( 'label' => self::t( 'first' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-first' ) ), 'autocomplete' => 'given-name', 'priority' => 60 ),
			'billing_last_name'  => array( 'label' => self::t( 'last' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-last' ) ), 'autocomplete' => 'family-name', 'priority' => 70 ),
			'billing_address_1'  => array( 'label' => self::t( 'street' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-wide' ) ), 'autocomplete' => 'address-line1', 'priority' => 80 ),
			'billing_city'       => array( 'label' => self::t( 'city' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-first' ) ), 'autocomplete' => 'address-level2', 'priority' => 90 ),
			'billing_postcode'   => array( 'label' => self::t( 'postcode' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-last' ) ), 'autocomplete' => 'postal-code', 'priority' => 100 ),
			'billing_country'    => array( 'type' => 'country', 'label' => self::t( 'country' ), 'required' => false, 'class' => array_merge( $inv, array( 'form-row-wide' ) ), 'autocomplete' => 'country', 'priority' => 110, 'default' => 'CZ' ),
		);
		$fields['billing'] = $billing;
		unset( $fields['order']['order_comments'] );
		$fields['shipping'] = array();
		return $fields;
	}

	private static function wants_invoice( $data = null ) {
		if ( null === $data ) {
			return ! empty( $_POST['billing_invoice'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return ! empty( $data['billing_invoice'] );
	}

	/** Bez faktury se fakturační údaje neukládají (mohly zůstat vyplněné ze dřívějška). */
	public static function posted_data( $data ) {
		if ( ! self::wants_invoice( $data ) ) {
			foreach ( self::INVOICE_FIELDS as $key ) {
				$data[ $key ] = '';
			}
		} else {
			$data['billing_ic']  = preg_replace( '/\s+/', '', (string) ( $data['billing_ic'] ?? '' ) );
			$data['billing_dic'] = strtoupper( preg_replace( '/\s+/', '', (string) ( $data['billing_dic'] ?? '' ) ) );
		}
		return $data;
	}

	public static function validate( $data, $errors ) {
		if ( ! self::wants_invoice( $data ) ) {
			return;
		}
		if ( '' === trim( (string) $data['billing_company'] ) && ( '' === trim( (string) $data['billing_first_name'] ) || '' === trim( (string) $data['billing_last_name'] ) ) ) {
			$errors->add( 'sam_invoice_name', self::t( 'need_name' ) );
		}
		foreach ( array( 'billing_address_1', 'billing_city', 'billing_postcode', 'billing_country' ) as $key ) {
			if ( '' === trim( (string) $data[ $key ] ) ) {
				$errors->add( 'sam_invoice_address', self::t( 'need_address' ) );
				break;
			}
		}
		if ( '' !== $data['billing_ic'] && 'CZ' === $data['billing_country'] && ! preg_match( '/^\d{8}$/', $data['billing_ic'] ) ) {
			$errors->add( 'sam_invoice_ic', self::t( 'bad_ic' ) );
		}
		if ( '' !== $data['billing_dic'] && ! preg_match( '/^[A-Z]{2}[0-9A-Z]{2,13}$/', $data['billing_dic'] ) ) {
			$errors->add( 'sam_invoice_dic', self::t( 'bad_dic' ) );
		}
	}

	public static function save( $order, $data ) {
		$wants = self::wants_invoice( $data );
		$order->update_meta_data( '_sam_invoice_requested', $wants ? 'yes' : 'no' );
		$order->delete_meta_data( '_billing_invoice' );
		if ( $wants ) {
			$order->update_meta_data( '_billing_ic', $data['billing_ic'] );
			$order->update_meta_data( '_billing_dic', $data['billing_dic'] );
		}
	}

	/** Místo „Fakturační E-mail je povinné pole“. */
	public static function required_notice( $notice, $label, $key = '' ) {
		return 'billing_email' === $key ? self::t( 'locked' ) : $notice;
	}

	public static function button_text() {
		return self::t( 'button' );
	}

	public static function terms_notice() {
		$terms   = get_permalink( (int) apply_filters( 'wpml_object_id', 1147, 'page', true ) );
		$privacy = get_permalink( (int) apply_filters( 'wpml_object_id', 1148, 'page', true ) );
		echo '<p class="sam-terms">' . wp_kses_post( sprintf( self::t( 'terms' ), esc_url( $terms ), esc_url( $privacy ) ) ) . '</p>';
	}

	public static function admin_details( $order ) {
		$wants = 'yes' === $order->get_meta( '_sam_invoice_requested' );
		echo '<p><strong>Faktura vyžádána:</strong> ' . ( $wants ? 'ano' : 'ne' ) . '</p>';
		foreach ( array( '_billing_ic' => 'IČO', '_billing_dic' => 'DIČ' ) as $key => $label ) {
			$value = $order->get_meta( $key );
			if ( $value ) {
				echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>';
			}
		}
	}

	public static function assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		$file = __DIR__ . '/assets/checkout.js';
		wp_enqueue_script( 'sam-checkout', content_url( 'mu-plugins/sam-core/assets/checkout.js' ), array( 'jquery', 'wc-checkout' ), (string) filemtime( $file ), true );
		wp_localize_script( 'sam-checkout', 'samCheckout', array( 'locked' => self::t( 'locked' ) ) );
	}
}
SAM_Checkout::init();
