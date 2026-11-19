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

	$( function () {
		invoice();
		lock();
	} );
}( jQuery ) );
