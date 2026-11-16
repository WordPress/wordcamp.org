/**
 * Saves the sponsor when vetting is requested, and redraws the Sponsor Vetting box after the block editor saves.
 *
 * The block editor saves without reloading the page, so the box would otherwise keep showing what it said on load.
 */
( function () {
	// One for every notice about the request, so a new one replaces the last instead of piling up.
	const NOTICE_ID = 'sv-vetting-request';

	let latestRedraw = 0;

	/**
	 * Shows a notice about the vetting request, as a snackbar like the editor's own "Draft saved."
	 *
	 * @param {string} status  `success`, `warning` or `error`.
	 * @param {string} message The message.
	 */
	function showNotice( status, message ) {
		window.wp.data.dispatch( 'core/notices' ).createNotice( status, message, {
			id: NOTICE_ID,
			type: 'snackbar',
		} );
	}

	/**
	 * Fetches what the box says now and swaps it in, and shows the notices the save queued, like whether vetting
	 * was requested.
	 *
	 * Not returned to the hook, so the save doesn't wait on it. A failed fetch leaves the box as it was.
	 *
	 * @param {Object}  post               The saved post.
	 * @param {number}  post.id            Its ID.
	 * @param {Object}  options            How it was saved.
	 * @param {boolean} options.isAutosave Whether it was an autosave, which doesn't save the meta boxes.
	 */
	function redrawVettingBox( post, options ) {
		if ( options.isAutosave ) {
			return;
		}

		const redraw = ++latestRedraw;

		window.wp
			.apiFetch( {
				path: `/sponsor-vetting/v1/vetting-box/${ post.id }`,
			} )
			.then( ( response ) => {
				// Shown whichever answer arrives last: each save's notices come back only once.
				response.notices.forEach( ( notice ) => showNotice( notice.type, notice.message ) );

				// Looked up now: the editor moves the meta boxes after this script loads.
				const box = document.querySelector( '#sv-vetting .inside' );

				// A slower answer to an earlier save would put back what the box said before.
				if ( box && redraw === latestRedraw ) {
					box.innerHTML = response.html;
				}
			} )
			.catch( () => {} );
	}

	/**
	 * Whether a website has a domain the vetting team can find the company by, as the server's normalize_domain()
	 * decides it.
	 *
	 * @param {string} value The website.
	 * @return {boolean} Whether it has one.
	 */
	function hasDomain( value ) {
		let url = value.trim().toLowerCase();

		if ( ! /^[a-z][a-z0-9+.-]*:\/\//.test( url ) ) {
			url = `http://${ url }`;
		}

		try {
			const host = new URL( url ).hostname.replace( /\.$/, '' ).replace( /^www\./, '' );

			return /^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/.test( host );
		} catch {
			return false;
		}
	}

	/**
	 * Whether an email address is one the server's is_email() accepts, which unlike the browser wants a dot in the
	 * domain.
	 *
	 * @param {string} value The email address.
	 * @return {boolean} Whether it's valid.
	 */
	function isEmail( value ) {
		return /^[a-z0-9!#$%&'*+/=?^_`{|}~.-]+@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]*[a-z0-9]$/i.test(
			value.trim()
		);
	}

	/**
	 * Finds what in the Contact Information on the page keeps vetting from being requested, checked as the server
	 * checks it: an empty field first, then the website, then the email address.
	 *
	 * A field that isn't on the page is left to the server, which checks what's saved.
	 *
	 * @param {HTMLButtonElement} button The Request vetting button, naming the fields and the messages.
	 * @return {{input: HTMLInputElement, message: string}|undefined} The field and what to tell organizers, if any.
	 */
	function findContactProblem( button ) {
		const messages = JSON.parse( button.dataset.messages );
		const field = ( name ) => document.getElementsByName( name )[ 0 ];
		const empty = button.dataset.requiredFields
			.split( ' ' )
			.map( field )
			.find( ( input ) => input && '' === input.value.trim() );
		const website = field( '_wcpt_sponsor_website' );
		const email = field( '_wcpt_sponsor_email_address' );

		if ( empty ) {
			return { input: empty, message: messages.missing };
		}

		if ( website && ! hasDomain( website.value ) ) {
			return { input: website, message: messages.website };
		}

		if ( email && ! isEmail( email.value ) ) {
			return { input: email, message: messages.email };
		}
	}

	/**
	 * Opens the block editor's meta box pane and the Sponsor Info box, and puts the cursor in the field to fix.
	 *
	 * @param {HTMLInputElement} input The field.
	 */
	function showContactField( input ) {
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'metaBoxesMainIsOpen', true );

		const box = input.closest( '.postbox' );

		// Core's own toggle, so the box's open state is saved as when organizers click it.
		if ( box?.classList.contains( 'closed' ) ) {
			box.querySelector( '.handlediv' )?.click();
		}

		// After the pane has rendered open.
		window.requestAnimationFrame( () => input.focus() );
	}

	/**
	 * Saves the sponsor with the request, so the request carries what was just entered.
	 *
	 * In the block editor, Contact Information that keeps vetting from being requested is caught before saving, so
	 * the organizer hears about it as they click. A field stands in for a submit button, which pressing Enter in any other field could trigger.
	 *
	 * @param {MouseEvent} event The click.
	 */
	function requestVetting( event ) {
		const button = event.target.closest( '#sv-vetting .inside button' );
		const isBlockEditor = document.body.classList.contains( 'block-editor-page' );

		if ( ! button ) {
			return;
		}

		const problem = isBlockEditor && findContactProblem( button );

		if ( problem ) {
			showNotice( 'error', problem.message );
			showContactField( problem.input );
			return;
		}

		const field = Object.assign( document.createElement( 'input' ), {
			type: 'hidden',
			name: 'sv_request_vetting',
			value: '1',
		} );

		button.after( field );
		button.disabled = true;

		if ( ! isBlockEditor ) {
			// Vetting can be requested before the details an invoice needs are known.
			button.form.noValidate = true;
			button.form.requestSubmit();
			return;
		}

		window.wp.data.dispatch( 'core/notices' ).removeNotice( NOTICE_ID );

		// A field left behind by a failed save would request vetting on the next one.
		window.wp.data
			.dispatch( 'core/editor' )
			.savePost()
			.finally( () => {
				field.remove();
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', requestVetting );

	// After core saves the meta boxes, at the default priority, since the website is in one.
	window.wp.hooks?.addAction(
		'editor.savePost',
		'sponsor-vetting/redraw-vetting-box',
		redrawVettingBox,
		20
	);
} )();
