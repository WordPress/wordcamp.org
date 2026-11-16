/**
 * Saves the sponsor when vetting is requested, and redraws the Sponsor Vetting box after the block editor saves.
 *
 * The block editor saves without reloading the page, so the box would otherwise keep showing what it said on load.
 */
( function () {
	let latestRedraw = 0;

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
				response.notices.forEach( ( notice ) => {
					window.wp.data
						.dispatch( 'core/notices' )
						.createNotice( notice.type, notice.message );
				} );

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
	 * Whether a Contact Information field vetting needs is empty on the page.
	 *
	 * A field that isn't on the page is left to the server, which checks what's saved.
	 *
	 * @param {HTMLButtonElement} button The Request vetting button, naming the fields.
	 * @return {boolean} Whether one is empty.
	 */
	function isMissingContactInfo( button ) {
		return button.dataset.requiredFields.split( ' ' ).some( ( name ) => {
			const input = document.getElementsByName( name )[ 0 ];

			return input && '' === input.value.trim();
		} );
	}

	/**
	 * Saves the sponsor with the request, so the request carries what was just entered.
	 *
	 * In the block editor, missing Contact Information is caught before saving, so the organizer hears about it as
	 * they click. A field stands in for a submit button, which pressing Enter in any other field could trigger.
	 *
	 * @param {MouseEvent} event The click.
	 */
	function requestVetting( event ) {
		const button = event.target.closest( '#sv-vetting .inside button' );
		const isBlockEditor = document.body.classList.contains( 'block-editor-page' );

		if ( ! button ) {
			return;
		}

		if ( isBlockEditor && isMissingContactInfo( button ) ) {
			// One ID, so clicking again doesn't stack copies.
			window.wp.data
				.dispatch( 'core/notices' )
				.createErrorNotice( button.dataset.missingMessage, {
					id: 'sv-missing-contact-info',
				} );
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
