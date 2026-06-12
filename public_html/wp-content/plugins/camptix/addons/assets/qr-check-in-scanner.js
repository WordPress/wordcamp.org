/* global Html5Qrcode, camptixQRCheckin */
/**
 * CampTix QR check-in scanner.
 *
 * Uses html5-qrcode for live-camera decoding. Each decoded code is reduced to its signed token
 * and POSTed to the capability-checked `tix_qr_checkin` AJAX handler. Falls back to manual entry
 * when the camera is unavailable or denied.
 */
( function () {
	'use strict';

	var cfg     = window.camptixQRCheckin || {};
	var strings = cfg.strings || {};

	var scanner   = null;
	var scanning  = false;
	var busy      = false;
	var inFlight  = false;
	var lastText  = '';
	var lastAt    = 0;

	var els = {};

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		els.reader  = document.getElementById( 'tix-qr-reader' );
		els.status  = document.getElementById( 'tix-qr-status' );
		els.result  = document.getElementById( 'tix-qr-result' );
		els.manual  = document.getElementById( 'tix-qr-manual-input' );
		els.manualBtn = document.getElementById( 'tix-qr-manual-submit' );

		bindManual();
		bindResultAgain();

		setStatus( strings.starting || '' );

		if ( typeof Html5Qrcode === 'undefined' ) {
			cameraUnavailable( strings.noCamera );
			return;
		}

		startCamera();
	}

	function startCamera() {
		scanner = new Html5Qrcode( 'tix-qr-reader' );

		scanner.start(
			{ facingMode: 'environment' },
			{ fps: 10, qrbox: { width: 250, height: 250 } },
			onScan,
			function () { /* Per-frame "no code found" callback; intentionally ignored. */ }
		).then( function () {
			scanning = true;
			setStatus( strings.scanning || '' );
		} ).catch( function () {
			cameraUnavailable( strings.cameraDenied );
		} );
	}

	function cameraUnavailable( message ) {
		scanning = false;
		if ( els.reader ) {
			els.reader.style.display = 'none';
		}
		setStatus( message || '' );
		if ( els.manual ) {
			els.manual.focus();
		}
	}

	function onScan( decodedText ) {
		if ( busy ) {
			return;
		}

		var now = Date.now();
		// Debounce repeated reads of the same code.
		if ( decodedText === lastText && ( now - lastAt ) < 3000 ) {
			return;
		}
		lastText = decodedText;
		lastAt   = now;

		submit( extractToken( decodedText ) );
	}

	/**
	 * Reduce a decoded value to the signed token. Accepts a full check-in URL or a bare token.
	 *
	 * @param {string} text Decoded QR contents.
	 * @return {string} The token, or an empty string.
	 */
	function extractToken( text ) {
		text = ( text || '' ).trim();

		try {
			var url   = new URL( text );
			var token = url.searchParams.get( 'tix_qr_token' );
			if ( token ) {
				return token;
			}
		} catch ( e ) {
			// Not a URL; fall through.
		}

		if ( /^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test( text ) ) {
			return text;
		}

		return '';
	}

	function submit( token ) {
		if ( inFlight ) {
			// A check-in request is already running; ignore duplicate taps/scans so a second
			// response can't race or overwrite the first (e.g. flip a real success to "already").
			return;
		}

		// Pause first so an unrecognized code shows its result once and waits for "Scan next
		// attendee" instead of re-firing every few seconds while it stays in frame.
		busy = true;
		pauseScanning();

		if ( ! token ) {
			showResult( { status: 'invalid', message: strings.invalidCode || '', name: '' } );
			return;
		}

		inFlight = true;
		setStatus( strings.sending || '' );

		var data = new FormData();
		data.append( 'action', 'tix_qr_checkin' );
		data.append( 'nonce', cfg.nonce || '' );
		data.append( 'token', token );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( response ) {
			// A logged-out/expired session makes admin-ajax answer HTTP 400 with a bare "0"/"-1"
			// body: valid JSON, but not a wp_send_json envelope. Treat any non-2xx as an error so
			// the organizer sees a real message instead of a blank "invalid" panel.
			if ( ! response.ok ) {
				inFlight = false;
				var expired = ( 400 === response.status || 403 === response.status );
				showResult( {
					status: 'error',
					message: ( expired && strings.sessionExpired ) ? strings.sessionExpired : ( strings.networkError || '' ),
					name: ''
				} );
				return null;
			}
			return response.json();
		} ).then( function ( json ) {
			if ( null === json ) {
				return; // A non-OK response was already handled above.
			}
			inFlight = false;
			var payload = ( json && typeof json === 'object' && json.data ) ? json.data : {};
			if ( ! payload.status ) {
				payload.status = ( json && json.success ) ? 'success' : 'invalid';
			}
			if ( ! payload.message && 'success' !== payload.status ) {
				payload.message = strings.networkError || '';
			}
			showResult( payload );
		} ).catch( function () {
			inFlight = false;
			showResult( { status: 'error', message: strings.networkError || '', name: '' } );
		} );
	}

	function showResult( payload ) {
		if ( ! els.result ) {
			return;
		}

		setStatus( '' );

		var status = payload.status || 'invalid';
		var ok     = ( status === 'success' || status === 'already' );

		els.result.className = 'tix-qr-result tix-qr-result--' + status;
		els.result.hidden    = false;

		var icon = els.result.querySelector( '.tix-qr-result-icon' );
		if ( icon ) {
			icon.className = 'tix-qr-result-icon dashicons ' + ( ok ? 'dashicons-yes-alt' : 'dashicons-warning' );
		}

		setText( els.result.querySelector( '.tix-qr-result-name' ), payload.name || '' );
		setText( els.result.querySelector( '.tix-qr-result-message' ), payload.message || '' );

		els.result.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	function resetForNextScan() {
		busy     = false;
		lastText = '';
		lastAt   = 0;

		if ( els.result ) {
			els.result.hidden = true;
		}
		if ( els.manual ) {
			els.manual.value = '';
		}

		if ( scanner && scanning ) {
			resumeScanning();
			setStatus( strings.scanning || '' );
		} else if ( els.manual ) {
			els.manual.focus();
		}
	}

	function pauseScanning() {
		try {
			if ( scanner && scanning && typeof scanner.pause === 'function' ) {
				scanner.pause( true );
			}
		} catch ( e ) {}
	}

	function resumeScanning() {
		try {
			if ( scanner && typeof scanner.resume === 'function' ) {
				scanner.resume();
			}
		} catch ( e ) {}
	}

	function bindManual() {
		if ( els.manualBtn ) {
			els.manualBtn.addEventListener( 'click', function () {
				if ( els.manual ) {
					submitManual();
				}
			} );
		}
		if ( els.manual ) {
			els.manual.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					submitManual();
				}
			} );
		}
	}

	function submitManual() {
		if ( inFlight ) {
			return; // Don't fire a second check-in while one is already running.
		}
		busy     = false;
		lastText = '';
		submit( extractToken( els.manual.value ) );
	}

	function bindResultAgain() {
		if ( ! els.result ) {
			return;
		}
		var again = els.result.querySelector( '.tix-qr-result-again' );
		if ( again ) {
			again.addEventListener( 'click', resetForNextScan );
		}
	}

	function setStatus( text ) {
		if ( els.status ) {
			els.status.textContent = text;
		}
	}

	function setText( node, text ) {
		if ( node ) {
			node.textContent = text;
		}
	}
}() );
