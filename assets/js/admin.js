/**
 * UPI Direct for WooCommerce: admin actions (confirm / reject) via the REST API.
 */
( function () {
	'use strict';

	var apiFetch = window.wp && window.wp.apiFetch;
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) { return s; };
	var TD = 'upi-direct-for-woocommerce';
	if ( ! apiFetch ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-upidw-action]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var action = button.getAttribute( 'data-upidw-action' );
		var orderId = button.getAttribute( 'data-order' );
		var box = button.closest( '.upidw-box' );
		var feedback = ( box || document ).querySelector( '.upidw-feedback' );
		var data = {};

		if ( 'verify' === action ) {
			var confirmText = button.getAttribute( 'data-confirm' ) ||
				__( 'Confirm only if this exact amount is credited in your bank account. Continue?', TD );
			if ( ! window.confirm( confirmText ) ) {
				return;
			}
			var note = box ? box.querySelector( '.upidw-note' ) : null;
			data.note = note ? note.value : '';
		} else {
			var reason = box && box.querySelector( '.upidw-note' ) ? box.querySelector( '.upidw-note' ).value : window.prompt( __( 'Reason for rejecting (optional):', TD ), '' );
			if ( null === reason ) {
				return;
			}
			data.reason = reason;
		}

		var group = button.parentNode.querySelectorAll( 'button' );
		group.forEach( function ( b ) { b.disabled = true; } );

		apiFetch( {
			path: '/upi-direct/v1/admin/orders/' + encodeURIComponent( orderId ) + '/' + ( 'verify' === action ? 'verify' : 'reject' ),
			method: 'POST',
			data: data
		} ).then( function ( res ) {
			if ( feedback ) {
				feedback.textContent = res.message;
			}
			// Reload so the order screen and list show the new state.
			setTimeout( function () { window.location.reload(); }, 900 );
		} ).catch( function ( err ) {
			group.forEach( function ( b ) { b.disabled = false; } );
			var text = ( err && err.message ) || __( 'Something went wrong.', TD );
			if ( feedback ) {
				feedback.textContent = text;
				feedback.classList.add( 'upidw-feedback--error' );
			} else {
				window.alert( text );
			}
		} );
	} );
} )();
