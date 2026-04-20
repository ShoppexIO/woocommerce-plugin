/**
 * Shoppex Pay — WooCommerce Blocks checkout integration.
 *
 * Registers the Shoppex payment method so it appears in the block-based
 * checkout. The actual redirect to the Shoppex hosted checkout happens on
 * the server side in process_payment(), so this only needs to render the
 * label + description.
 */
( function ( wc, wp ) {
	'use strict';

	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { decodeEntities } = wp.htmlEntities;
	const { createElement } = wp.element;
	const { __ } = wp.i18n;

	const settings =
		( window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting ?
			window.wc.wcSettings.getSetting( 'shoppex_data', {} ) : {} ) || {};

	const label = decodeEntities( settings.title || __( 'Shoppex Pay', 'shoppex-pay' ) );
	const description = decodeEntities(
		settings.description ||
			__(
				'Pay with credit card, PayPal, cryptocurrencies, and more via Shoppex.',
				'shoppex-pay'
			)
	);

	const Content = function () {
		return createElement( 'div', null, description );
	};

	const Label = function ( props ) {
		const { PaymentMethodLabel } = props.components || {};
		if ( PaymentMethodLabel ) {
			return createElement( PaymentMethodLabel, { text: label } );
		}
		return createElement( 'span', null, label );
	};

	registerPaymentMethod( {
		name: 'shoppex',
		label: createElement( Label, {} ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: ( settings.supports && settings.supports.length ) ? settings.supports : [ 'products' ],
		},
	} );
} )( window.wc || {}, window.wp || {} );
