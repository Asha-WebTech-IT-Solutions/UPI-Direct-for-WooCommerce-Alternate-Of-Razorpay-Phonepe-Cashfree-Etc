/**
 * UPI Direct for WooCommerce: Cart & Checkout Blocks payment method.
 * Plain JS (no build step). Payment itself happens on the order-received screen.
 */
( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! wcSettings ) {
		return;
	}

	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var __ = window.wp.i18n.__;
	var TD = 'upi-direct-for-woocommerce';

	var settings = wcSettings.getSetting( 'upidw_data', {} );
	var title = decode( settings.title || __( 'Pay with UPI', TD ) );

	var Label = function ( props ) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;
		return el( PaymentMethodLabel, { text: title } );
	};

	var Content = function () {
		return el(
			'div',
			{ className: 'upidw-blocks-content' },
			settings.description ? el( 'p', null, decode( settings.description ) ) : null,
			el( 'p', null, __( 'After you place your order, you will see the UPI payment options.', TD ) )
		);
	};

	registry.registerPaymentMethod( {
		name: 'upidw',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		ariaLabel: title,
		canMakePayment: function () {
			var currency = wcSettings.getSetting( 'currency', {} );
			return ! currency.code || 'INR' === currency.code;
		},
		supports: { features: settings.supports || [ 'products' ] }
	} );
} )();
