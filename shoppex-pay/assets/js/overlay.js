/**
 * Shoppex Pay — overlay checkout on the WooCommerce order payment page.
 *
 * Opens the order's Shoppex invoice in the embed window. The browser events
 * only navigate the customer; the order is marked paid by the signed webhook.
 * When the embed script did not load, or is an older one without invoice
 * mode (it would open nothing), the buyer goes on to the full payment page.
 */
( function () {
	'use strict';

	var config = window.shoppexPayOverlay;
	var root = document.getElementById( 'shoppex-pay-overlay' );
	if ( ! config || ! root ) {
		return;
	}

	var payButton = root.querySelector( '[data-shoppex-pay-open]' );
	var redirecting = root.querySelector( '[data-shoppex-pay-redirecting]' );

	function goToPaymentPage() {
		redirecting.hidden = false;
		payButton.hidden = true;
		// Replace this page in the history: it is sent no-store, so Back would
		// reload it and redirect forward again.
		window.location.replace( config.paymentUrl );
	}

	function open() {
		window.Shoppex.open( { invoiceId: config.invoiceId } );
	}

	function goToOrderReceived( event ) {
		if ( event.type === 'shoppex:success' || ( event.detail && event.detail.completed ) ) {
			window.location.assign( config.returnUrl );
		}
	}

	var shoppex = window.Shoppex;
	if ( ! shoppex || typeof shoppex.open !== 'function' || ! shoppex.supports || shoppex.supports.invoiceId !== true ) {
		goToPaymentPage();
		return;
	}

	window.Shoppex.init( { checkoutBaseUrl: config.checkoutBaseUrl } );
	document.addEventListener( 'shoppex:success', goToOrderReceived );
	document.addEventListener( 'shoppex:close', goToOrderReceived );
	payButton.addEventListener( 'click', open );
	open();
} )();
