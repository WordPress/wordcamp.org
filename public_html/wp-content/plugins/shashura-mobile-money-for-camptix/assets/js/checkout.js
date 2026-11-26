/* Payment pages: double-submit guard, number formatting and status polling. */
( function () {
	'use strict';

	function el( id ) {
		return document.getElementById( id );
	}

	/* Forms */
	document.querySelectorAll( 'form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			var button = form.querySelector( 'button[type="submit"]' );
			if ( button ) {
				button.disabled = true;
				button.classList.add( 'is-loading' );
			}
		} );
	} );

	/* Number screen */
	var PREFIXES = {
		mtn: [ '76', '77', '78', '79' ],
		airtel: [ '70', '74', '75' ]
	};
	var phone = el( 'sc-phone' );

	function national( value ) {
		var digits = String( value ).replace( /\D+/g, '' );
		if ( 0 === digits.indexOf( '256' ) ) {
			digits = digits.slice( 3 );
		} else if ( 0 === digits.indexOf( '0' ) ) {
			digits = digits.slice( 1 );
		}
		return digits.slice( 0, 9 );
	}

	function format( digits ) {
		return [ digits.slice( 0, 3 ), digits.slice( 3, 6 ), digits.slice( 6, 9 ) ].filter( Boolean ).join( ' ' );
	}

	function detect( digits ) {
		var start = digits.slice( 0, 2 );
		return Object.keys( PREFIXES ).filter( function ( key ) {
			return -1 !== PREFIXES[ key ].indexOf( start );
		} )[ 0 ] || '';
	}

	function update() {
		var digits = national( phone.value );
		var match = digits.length >= 2 ? detect( digits ) : '';
		var box = el( 'sc-detected' );
		var text = el( 'sc-detected-text' );
		var names = { mtn: 'MTN Mobile Money', airtel: 'Airtel Money' };
		var shown = false;

		if ( box ) {
			box.querySelectorAll( 'img[data-network]' ).forEach( function ( img ) {
				var on = img.getAttribute( 'data-network' ) === match;
				img.hidden = ! on;
				shown = shown || on;
			} );
			box.className = 'sc-phone-detected' + ( match ? ' is-' + match : '' ) + ( match && ! shown ? ' is-text' : '' );
			box.setAttribute( 'data-label', match && ! shown ? names[ match ].split( ' ' )[ 0 ] : '' );
		}
		if ( text ) {
			text.textContent = match ? names[ match ] : '';
		}
		return digits;
	}

	if ( phone ) {
		phone.addEventListener( 'input', function () {
			var digits = update();
			var formatted = format( digits );
			if ( formatted !== phone.value ) {
				phone.value = formatted;
			}
		} );
		phone.value = format( update() );
	}

	/* Waiting screen */
	var cfg = window.shasmomoCheckout;
	if ( ! cfg || 'wait' !== cfg.screen || ! cfg.statusUrl || ! window.fetch ) {
		return;
	}

	var labels = cfg.labels || {};
	var started = Date.now();
	var windowSecs = Math.max( 1, cfg.window || 150 );
	var remaining = Math.max( 0, cfg.remaining || 0 );
	var deadline = Date.now() + remaining * 1000;
	var slowShown = false;
	var finished = false;

	function message( text ) {
		var node = el( 'sc-msg-text' );
		if ( node && text ) {
			node.textContent = text;
		}
	}

	function renderTimer() {
		var left = Math.max( 0, Math.round( ( deadline - Date.now() ) / 1000 ) );
		var bar = el( 'sc-timer-bar' );
		var text = el( 'sc-timer-text' );
		if ( bar ) {
			bar.style.strokeDashoffset = String( 100 - ( left / windowSecs ) * 100 );
		}
		if ( text ) {
			text.textContent = Math.floor( left / 60 ) + ':' + ( '0' + ( left % 60 ) ).slice( -2 );
		}
		if ( 0 === left ) {
			var timer = document.querySelector( '.sc-timer' );
			if ( timer ) {
				timer.classList.add( 'is-expired' );
			}
			el( 'sc-retry' ).hidden = false;
			if ( ! slowShown ) {
				message( labels.expired );
				slowShown = true;
			}
			return;
		}
		if ( ! finished ) {
			setTimeout( renderTimer, 1000 );
		}
	}

	function done( data ) {
		finished = true;
		var list = el( 'sc-det' );
		[ 'name', 'phone', 'network', 'amount', 'reference' ].forEach( function ( key ) {
			if ( ! data.details || ! data.details[ key ] ) {
				return;
			}
			var row = document.createElement( 'div' );
			var dt = document.createElement( 'dt' );
			var dd = document.createElement( 'dd' );
			row.className = 'sc-row' + ( 'amount' === key ? ' sc-row--total' : '' );
			dt.textContent = labels[ key ] || key;
			dd.textContent = data.details[ key ];
			row.appendChild( dt );
			row.appendChild( dd );
			list.appendChild( row );
		} );
		el( 'sc-next' ).href = data.next;
		el( 'sc-wait' ).hidden = true;
		el( 'sc-done' ).hidden = false;
		var cancel = document.querySelector( '.sc-cancel' );
		if ( cancel ) {
			cancel.hidden = true;
		}
		document.querySelectorAll( '.sc-dots li' ).forEach( function ( dot ) {
			dot.className = 'is-done';
		} );
		setTimeout( function () {
			window.location.href = data.next;
		}, 5000 );
	}

	function later() {
		var age = Date.now() - started;
		if ( age > 240000 && slowShown ) {
			message( labels.slow );
		}
		setTimeout( tick, age > 300000 ? 15000 : 4000 );
	}

	function tick() {
		fetch( cfg.statusUrl, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( 'paid' === data.state && data.next ) {
					return done( data );
				}
				if ( ( 'failed' === data.state || 'closed' === data.state || 'reconciliation' === data.state ) && data.next ) {
					finished = true;
					message( 'reconciliation' === data.state ? labels.reconciliation : ( data.message || labels.failed ) );
					return setTimeout( function () {
						window.location.href = data.next;
					}, 1500 );
				}
				later();
			} )
			.catch( later );
	}

	renderTimer();
	setTimeout( tick, 3000 );
}() );
