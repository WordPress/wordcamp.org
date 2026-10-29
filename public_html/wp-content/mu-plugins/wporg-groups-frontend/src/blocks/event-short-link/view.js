/**
 * Event short link: the "Copy" button.
 *
 * The button is server-rendered `hidden` so it never shows without a working
 * handler; this reveals it and copies the link on click. The outcome is said
 * in words in the status region next to it, which screen readers announce.
 */

/**
 * Reveals and wires every short link copy button under `root`.
 *
 * @param {Document|Element} root Where to look.
 */
export function init( root = document ) {
	root.querySelectorAll( '.wporg-event-short-link' ).forEach( ( wrapper ) => {
		const button = wrapper.querySelector( '.wporg-event-short-link__copy' );
		const status = wrapper.querySelector( '.wporg-event-short-link__status' );

		if ( ! button || ! status || ! window.navigator.clipboard ) {
			return;
		}

		button.addEventListener( 'click', async () => {
			// Emptied first so a second copy is announced again.
			status.textContent = '';

			try {
				await window.navigator.clipboard.writeText( button.dataset.url );
				status.textContent = wrapper.dataset.copied;
			} catch {
				status.textContent = wrapper.dataset.failed;
			}
		} );
		button.hidden = false;
	} );
}

init();
