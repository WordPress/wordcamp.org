/**
 * Redraws the Sponsor Vetting box once the block editor has saved the sponsor and its meta boxes.
 *
 * The block editor saves without reloading the page, so the box would otherwise keep showing what it said on load.
 */
( function () {
	const { data } = window.wp;
	const settings = window.svVettingBox;
	let wasSaving = false;

	/**
	 * Replaces the box's contents with what the server renders for the sponsor now.
	 *
	 * @param {number} sponsorId The camp's sponsor.
	 */
	function redraw( sponsorId ) {
		const url = new URL( settings.ajaxUrl );

		url.searchParams.set( 'action', 'sv_vetting_box' );
		url.searchParams.set( 'sponsor_id', sponsorId );
		url.searchParams.set( '_ajax_nonce', settings.nonce );

		window
			.fetch( url, { credentials: 'same-origin' } )
			.then( ( response ) => response.json() )
			.then( ( result ) => {
				// Looked up now: the editor moves the meta boxes after this script loads.
				const box = document.querySelector( '#sv-vetting .inside' );

				if ( box && result.success ) {
					box.innerHTML = result.data;
				}
			} )
			// A failed redraw leaves the box as it was, which a reload fixes.
			.catch( () => {} );
	}

	data.subscribe( () => {
		const editor = data.select( 'core/editor' );

		// The website is in a meta box, which saves in its own request after the post.
		const isSaving =
			( editor.isSavingPost() && ! editor.isAutosavingPost() ) ||
			data.select( 'core/edit-post' ).isSavingMetaBoxes();

		if ( wasSaving && ! isSaving && editor.didPostSaveRequestSucceed() ) {
			redraw( editor.getCurrentPostId() );
		}

		wasSaving = isSaving;
	} );
} )();
