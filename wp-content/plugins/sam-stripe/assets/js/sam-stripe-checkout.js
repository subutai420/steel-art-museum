/* Steel Art Museum – Stripe Payment Element na klasické pokladně WooCommerce.
 *
 * 1. Prvek se připojí do #sam-stripe-element (WooCommerce sekci platby při každé
 *    obnově pokladny vykreslí znovu, proto se po „updated_checkout“ připojuje znovu).
 * 2. Odeslání objednávky s platbou Stripe: nejdřív elements.submit() (validace karty,
 *    u Apple/Google Pay otevře platební okno – musí proběhnout v rámci kliknutí),
 *    teprve pak WooCommerce vytvoří objednávku.
 * 3. Server vrátí #sam-stripe-confirm=…, tady se zavolá stripe.confirmPayment()
 *    a Stripe zákazníka vrátí na děkovací stránku.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.samStripe || {};
	if ( ! cfg.publishableKey || typeof window.Stripe !== 'function' ) {
		return;
	}

	var stripe = window.Stripe( cfg.publishableKey, { locale: cfg.locale || 'cs' } );
	var elements = null;
	var paymentElement = null;
	var mountedNode = null;
	var lastAmount = null;
	var lastCurrency = null;
	var validated = false;
	var confirming = false;

	function form() {
		var $checkout = $( 'form.checkout' );
		return $checkout.length ? $checkout : $( 'form#order_review' );
	}

	function node() {
		return document.getElementById( 'sam-stripe-element' );
	}

	function showError( message ) {
		var box = document.getElementById( 'sam-stripe-errors' );
		if ( box ) {
			box.textContent = message || cfg.i18n.generic;
		}
		var $form = form();
		$form.removeClass( 'processing' );
		if ( typeof $form.unblock === 'function' ) {
			$form.unblock();
		}
		$( document.body ).trigger( 'checkout_error', [ message ] );
	}

	function clearError() {
		var box = document.getElementById( 'sam-stripe-errors' );
		if ( box ) {
			box.textContent = '';
		}
	}

	function mount() {
		var target = node();
		if ( ! target ) {
			return;
		}
		var amount = parseInt( target.getAttribute( 'data-amount' ), 10 ) || 0;
		var currency = target.getAttribute( 'data-currency' ) || 'czk';
		if ( amount <= 0 ) {
			return;
		}

		if ( ! elements ) {
			elements = stripe.elements( {
				mode: 'payment',
				amount: amount,
				currency: currency,
				appearance: cfg.appearance || {}
			} );
			paymentElement = elements.create( 'payment', {
				layout: { type: 'tabs' },
				fields: { billingDetails: { email: 'never' } },
				wallets: { applePay: 'auto', googlePay: 'auto' }
			} );
			paymentElement.on( 'change', clearError );
		} else if ( amount !== lastAmount || currency !== lastCurrency ) {
			elements.update( { amount: amount, currency: currency } );
		}
		lastAmount = amount;
		lastCurrency = currency;

		if ( mountedNode !== target ) {
			if ( mountedNode ) {
				try {
					paymentElement.unmount();
				} catch ( e ) {}
			}
			paymentElement.mount( target );
			mountedNode = target;
		}
	}

	function selected() {
		return $( '#payment_method_sam_stripe' ).is( ':checked' );
	}

	function email() {
		return ( $( '#billing_email' ).val() || '' ).trim();
	}

	// Před odesláním objednávky ověřit platební údaje (a u peněženek otevřít jejich okno).
	form().on( 'checkout_place_order_sam_stripe', function () {
		if ( validated ) {
			return true;
		}
		if ( ! elements ) {
			showError( cfg.i18n.generic );
			return false;
		}
		clearError();
		elements.submit().then( function ( result ) {
			if ( result.error ) {
				showError( result.error.message );
				return;
			}
			validated = true;
			form().trigger( 'submit' );
		} );
		return false;
	} );

	// Server vytvořil objednávku a PaymentIntent → potvrdit platbu.
	function onHash() {
		var match = window.location.hash.match( /^#sam-stripe-confirm=(.+)$/ );
		if ( ! match || confirming ) {
			return;
		}
		confirming = true;
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.pathname + window.location.search );
		}
		var data;
		try {
			data = JSON.parse( window.atob( decodeURIComponent( match[ 1 ] ) ) );
		} catch ( e ) {
			confirming = false;
			validated = false;
			showError( cfg.i18n.generic );
			return;
		}
		stripe.confirmPayment( {
			elements: elements,
			clientSecret: data.cs,
			confirmParams: {
				return_url: data.ret,
				payment_method_data: { billing_details: { email: email() || data.email || '' } }
			}
		} ).then( function ( result ) {
			// Sem se dostaneme jen při chybě (jinak Stripe přesměruje na return_url).
			confirming = false;
			validated = false;
			showError( result.error ? result.error.message : cfg.i18n.generic );
		} );
	}

	window.addEventListener( 'hashchange', onHash );
	$( document.body ).on( 'updated_checkout payment_method_selected', mount );
	$( document.body ).on( 'checkout_error', function () {
		validated = false;
	} );
	$( function () {
		mount();
		onHash();
	} );

	// Při přepnutí na jinou platební metodu nic nedržet.
	$( document.body ).on( 'payment_method_selected', function () {
		if ( ! selected() ) {
			validated = false;
		}
	} );
}( jQuery ) );
