/**
 * Redraws the Sponsor Vetting box after the block editor saves the sponsor, and saves it to request vetting.
 *
 * The block editor saves without reloading the page, so the box would otherwise keep showing what it said on load.
 */
( function () {
	let latestRedraw = 0;

	/**
	 * Fetches what the box says now and swaps it in.
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
	 * Saves the sponsor with the request, as the button does by itself in the classic editor.
	 *
	 * @param {MouseEvent} event The click.
	 */
	function requestVetting( event ) {
		const button = event.target.closest( '#sv-vetting button[type="submit"]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		// The editor posts the meta boxes' fields but not their buttons. The redraw clears the field again.
		button.after(
			Object.assign( document.createElement( 'input' ), {
				type: 'hidden',
				name: 'sv_request_vetting',
				value: '1',
			} )
		);

		window.wp.data.dispatch( 'core/editor' ).savePost();
	}

	document.addEventListener( 'click', requestVetting );

	// After core saves the meta boxes, at the default priority, since the website is in one.
	window.wp.hooks.addAction(
		'editor.savePost',
		'sponsor-vetting/redraw-vetting-box',
		redrawVettingBox,
		20
	);
} )();
