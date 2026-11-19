/* Pokladna: fakturační údaje jen při „Chci fakturu“, platba odemčená až po platném e-mailu. */
( function ( $ ) {
	'use strict';
	var cfg = window.samCheckout || {};
	var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

	function invoice() {
		var on = $( '#billing_invoice' ).is( ':checked' );
		$( '.sam-invoice-field' ).toggle( on ).find( 'input, select' ).prop( 'disabled', false );
		$( '.sam-invoice-fields' ).toggleClass( 'is-open', on );
	}

	function emailOk() {
		return EMAIL.test( ( $( '#billing_email' ).val() || '' ).trim() );
	}

	function lock() {
		var $pay = $( '.sam-co-pay' );
		var locked = ! emailOk();
		$pay.toggleClass( 'is-locked', locked ).attr( 'aria-disabled', locked ? 'true' : null );
		if ( ! $pay.find( '.sam-co-lock' ).length ) {
			$pay.children( 'h2' ).first().after( $( '<p class="sam-co-lock" role="status"></p>' ).text( cfg.locked || '' ) );
		}
		$pay.find( '#place_order' ).prop( 'disabled', locked );
	}

	$( document.body ).on( 'change', '#billing_invoice', invoice );
	$( document.body ).on( 'input change blur', '#billing_email', lock );
	$( document.body ).on( 'updated_checkout', lock );

	// Pojistka: bez e-mailu objednávku neodesílat (Apple/Google Pay by jinak otevřely platební okno).
	$( 'form.checkout' ).on( 'checkout_place_order', function () {
		if ( emailOk() ) {
			return true;
		}
		$( '#billing_email' ).trigger( 'focus' );
		lock();
		return false;
	} );

	// Slevový kód v souhrnu objednávky (stejné volání jako formulář kuponu WooCommerce).
	$( document.body ).on( 'click', '.sam-coupon-toggle', function () {
		var $row = $( '#sam-coupon-row' ), open = $row.prop( 'hidden' );
		$row.prop( 'hidden', ! open );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			$( '#sam-coupon-code' ).trigger( 'focus' );
		}
	} );

	function applyCoupon() {
		var params = window.wc_checkout_params, code = ( $( '#sam-coupon-code' ).val() || '' ).trim();
		var $box = $( '.sam-coupon' ), $msg = $box.find( '.sam-coupon-msg' );
		if ( ! code || ! params ) {
			return;
		}
		$box.addClass( 'is-busy' );
		$.post( params.wc_ajax_url.toString().replace( '%%endpoint%%', 'apply_coupon' ), {
			security: params.apply_coupon_nonce,
			coupon_code: code
		} ).done( function ( html ) {
			$msg.html( html );
			if ( $msg.find( '.woocommerce-error' ).length === 0 ) {
				$( '#sam-coupon-code' ).val( '' );
			}
			$( document.body ).trigger( 'applied_coupon_in_checkout', [ code ] ).trigger( 'update_checkout', { update_shipping_method: false } );
		} ).always( function () {
			$box.removeClass( 'is-busy' );
		} );
	}
	$( document.body ).on( 'click', '.sam-coupon-apply', applyCoupon );
	$( document.body ).on( 'keydown', '#sam-coupon-code', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault(); // jinak by se odeslala celá objednávka
			applyCoupon();
		}
	} );

	$( function () {
		invoice();
		lock();
	} );
}( jQuery ) );
