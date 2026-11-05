/**
 * Redraws the Sponsor Vetting box after the block editor saves the sponsor.
 *
 * The block editor saves without reloading the page, so the box would otherwise keep showing what it said on load.
 */

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

	window.wp
		.apiFetch( { path: `/sponsor-vetting/v1/vetting-box/${ post.id }` } )
		.then( ( response ) => {
			// Looked up now: the editor moves the meta boxes after this script loads.
			const box = document.querySelector( '#sv-vetting .inside' );

			if ( box ) {
				box.innerHTML = response.html;
			}
		} )
		.catch( () => {} );
}

// After core saves the meta boxes, at the default priority, since the website is in one.
window.wp.hooks.addAction(
	'editor.savePost',
	'sponsor-vetting/redraw-vetting-box',
	redrawVettingBox,
	20
);
