/**
 * Event flyer link: the flyer's "Print flyer" button.
 *
 * The button is server-rendered `hidden` so it never shows without a working
 * handler; this reveals it and opens the browser's print dialog on click.
 * On the event page, where the block is just a link, there's no button and
 * this does nothing.
 */

/**
 * Reveals and wires every flyer print button under `root`.
 *
 * @param {Document|Element} root Where to look.
 */
export function init( root = document ) {
	root.querySelectorAll( '.wporg-event-flyer-link__print' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => window.print() );
		button.hidden = false;
	} );
}

init();
