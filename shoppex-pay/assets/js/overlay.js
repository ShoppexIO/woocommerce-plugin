/**
 * Shoppex Pay — overlay checkout on the WooCommerce order payment page.
 *
 * Opens the order's Shoppex invoice in the embed window. The browser events
 * only navigate the customer; the order is marked paid by the signed webhook.
 * When the embed script did not load, the page says so and the full payment
 * page link stays the way forward.
 */
( function () {
	'use strict';

	var config = window.shoppexPayOverlay;
	var root = document.getElementById( 'shoppex-pay-overlay' );
	if ( ! config || ! root ) {
		return;
	}

	var payButton = root.querySelector( '[data-shoppex-pay-open]' );
	var error = root.querySelector( '[data-shoppex-pay-error]' );

	function showFallback() {
		error.hidden = false;
		payButton.hidden = true;
	}

	function open() {
		if ( ! window.Shoppex || typeof window.Shoppex.open !== 'function' ) {
			showFallback();
			return;
		}
		window.Shoppex.init( { checkoutBaseUrl: config.checkoutBaseUrl } );
		window.Shoppex.open( { invoiceId: config.invoiceId } );
	}

	function goToOrderReceived( event ) {
		if ( event.type === 'shoppex:success' || ( event.detail && event.detail.completed ) ) {
			window.location.assign( config.returnUrl );
		}
	}

	document.addEventListener( 'shoppex:success', goToOrderReceived );
	document.addEventListener( 'shoppex:close', goToOrderReceived );
	payButton.addEventListener( 'click', open );
	open();
} )();
