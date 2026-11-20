/**
 * UPI Direct for WooCommerce: checkout panel behaviour.
 * Vanilla JS, no build step. The browser NEVER decides that a payment succeeded:
 * it only displays the state the server reports.
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : { __: function ( s ) { return s; } };
	var __ = i18n.__;
	var TD = 'upi-direct-for-woocommerce';

	/**
	 * Draw a QR code as an SVG (built with DOM APIs, no innerHTML).
	 */
	function renderQr( el, text ) {
		if ( ! el || ! text || typeof window.qrcode !== 'function' ) {
			return;
		}
		var qr;
		try {
			qr = window.qrcode( 0, 'M' );
			qr.addData( text );
			qr.make();
		} catch ( e ) {
			el.textContent = __( 'QR code unavailable. Use the buttons or UPI ID instead.', TD );
			return;
		}
		var n = qr.getModuleCount();
		var quiet = 2;
		var size = n + quiet * 2;
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
		svg.setAttribute( 'shape-rendering', 'crispEdges' );
		svg.setAttribute( 'aria-hidden', 'true' );
		var bg = document.createElementNS( ns, 'rect' );
		bg.setAttribute( 'width', size );
		bg.setAttribute( 'height', size );
		bg.setAttribute( 'fill', '#fff' );
		svg.appendChild( bg );
		var d = '';
		for ( var r = 0; r < n; r++ ) {
			for ( var c = 0; c < n; c++ ) {
				if ( qr.isDark( r, c ) ) {
					d += 'M' + ( c + quiet ) + ' ' + ( r + quiet ) + 'h1v1h-1z';
				}
			}
		}
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', d );
		path.setAttribute( 'fill', '#000' );
		svg.appendChild( path );
		el.textContent = '';
		el.appendChild( svg );
	}

	function init( root ) {
		var cfg;
		try {
			cfg = JSON.parse( root.getAttribute( 'data-upidw' ) );
		} catch ( e ) {
			return;
		}
		var badge = root.querySelector( '[data-upidw-badge]' );
		var message = root.querySelector( '[data-upidw-message]' );
		var form = root.querySelector( '[data-upidw-form]' );
		var input = form ? form.querySelector( 'input[name="reference"]' ) : null;
		var errorEl = root.querySelector( '[data-upidw-error]' );
		var submit = root.querySelector( '[data-upidw-submit]' );
		var submitLabel = root.querySelector( '[data-upidw-submit-label]' );
		var copyBtn = root.querySelector( '[data-upidw-copy]' );
		var vpaEl = root.querySelector( '[data-upidw-vpa]' );
		var pollTimer = null;
		var pollStarted = Date.now();

		var isAndroid = /android/i.test( navigator.userAgent );
		if ( ! isAndroid ) {
			root.querySelectorAll( '[data-platform="android"]' ).forEach( function ( li ) {
				li.parentNode.removeChild( li );
			} );
		}

		if ( cfg.showQr && cfg.upiUri ) {
			renderQr( root.querySelector( '[data-upidw-qr]' ), cfg.upiUri );
		}

		if ( copyBtn && vpaEl && navigator.clipboard && navigator.clipboard.writeText ) {
			copyBtn.hidden = false;
			copyBtn.addEventListener( 'click', function () {
				navigator.clipboard.writeText( vpaEl.textContent.trim() ).then( function () {
					var old = copyBtn.textContent;
					copyBtn.textContent = __( 'Copied', TD );
					setTimeout( function () {
						copyBtn.textContent = old;
					}, 1800 );
				} );
			} );
		}

		function showError( text ) {
			if ( ! errorEl ) {
				return;
			}
			errorEl.textContent = text || '';
			errorEl.hidden = ! text;
			if ( input ) {
				if ( text ) {
					input.setAttribute( 'aria-invalid', 'true' );
				} else {
					input.removeAttribute( 'aria-invalid' );
				}
			}
		}

		function apply( data ) {
			if ( ! data || ! data.state ) {
				return;
			}
			root.setAttribute( 'data-state', data.state );
			cfg.state = data.state;
			if ( badge ) {
				badge.textContent = data.label;
			}
			if ( message ) {
				message.textContent = data.message;
			}
			if ( 'awaiting_verification' === data.state ) {
				if ( submitLabel ) {
					submitLabel.textContent = __( 'Update reference', TD );
				}
			}
			if ( 'paid' === data.state ) {
				stopPolling();
				// Reload so WooCommerce shows the final order details.
				setTimeout( function () {
					window.location.reload();
				}, 2500 );
			} else if ( 'rejected' === data.state || 'closed' === data.state ) {
				stopPolling();
			}
		}

		function api( path, options ) {
			options = options || {};
			options.credentials = 'same-origin';
			options.headers = Object.assign( { Accept: 'application/json' }, options.headers || {} );
			return fetch( cfg.restUrl + path, options ).then( function ( res ) {
				return res.json().then( function ( body ) {
					if ( ! res.ok ) {
						var err = new Error( ( body && body.message ) || __( 'Something went wrong. Please try again.', TD ) );
						err.status = res.status;
						throw err;
					}
					return body;
				} );
			} );
		}

		function poll() {
			var qs = '?order_key=' + encodeURIComponent( cfg.orderKey ) + '&_=' + Date.now();
			api( 'orders/' + cfg.orderId + '/status' + qs )
				.then( apply )
				.catch( function () {} )
				.then( schedule );
		}

		function schedule() {
			stopPolling();
			if ( 'paid' === cfg.state || 'rejected' === cfg.state || 'closed' === cfg.state || document.hidden ) {
				return;
			}
			// Back off after five minutes, stop after an hour.
			var elapsed = Date.now() - pollStarted;
			if ( elapsed > 3600000 ) {
				return;
			}
			pollTimer = setTimeout( poll, elapsed > 300000 ? cfg.pollMs * 3 : cfg.pollMs );
		}

		function stopPolling() {
			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden && 'awaiting_verification' === cfg.state ) {
				poll();
			}
		} );

		if ( form && input ) {
			input.addEventListener( 'input', function () {
				showError( '' );
			} );
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var value = input.value.replace( /[\s-]+/g, '' );
				if ( ! value ) {
					showError( __( 'Enter the reference number from your payment app.', TD ) );
					input.focus();
					return;
				}
				submit.disabled = true;
				showError( '' );
				api( 'orders/' + cfg.orderId + '/reference', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						order_key: cfg.orderKey,
						nonce: cfg.nonce,
						reference: input.value
					} )
				} )
					.then( function ( data ) {
						input.value = '';
						apply( data );
						pollStarted = Date.now();
						schedule();
					} )
					.catch( function ( err ) {
						showError( err.message );
						input.focus();
					} )
					.then( function () {
						submit.disabled = false;
					} );
			} );
		}

		if ( 'awaiting_verification' === cfg.state && submitLabel ) {
			submitLabel.textContent = __( 'Update reference', TD );
		}
		if ( 'awaiting_verification' === cfg.state || 'awaiting_payment' === cfg.state ) {
			schedule();
		}
	}

	function boot() {
		document.querySelectorAll( '[data-upidw]' ).forEach( init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
