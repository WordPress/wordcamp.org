/**
 * Mounts a venue's interactive map when it scrolls into view.
 *
 * GatherPress renders the map as a static image and swaps it for a live map
 * as soon as the page loads. On an event page the map sits below the fold on
 * most screens, so every visitor downloaded the map library and its tiles —
 * about 440KB with Google Maps — whether or not they scrolled that far.
 *
 * `defer_venue_map()` in functions.php points the map's `data-wp-init` at
 * this store instead of GatherPress's. This store waits until the map is on
 * screen, then runs GatherPress's own `init` with the element and context it
 * would have had at page load. The image stays in place until then, and stays
 * for good if GatherPress ever renames that callback.
 */
// eslint-disable-next-line import/no-extraneous-dependencies -- WordPress serves this through its import map.
import { getElement, store, withScope } from '@wordpress/interactivity';

/**
 * Hands keyboard focus to the live map when the swap removes the element
 * that had it.
 *
 * The static map ends in attribution links, and tabbing to one is what
 * scrolls the map into view for someone using a keyboard. The live map then
 * replaces the link they have just reached. A browser doesn't say when it
 * removes a focused element: focus falls back to the page, and a screen
 * reader starts again from the top.
 *
 * So this remembers what was last focused inside the map. When the map's
 * contents are replaced and that element is gone, focus moves to the live
 * map: Leaflet's container, which is named after the venue, or the embedded
 * map's frame, or failing those the wrapper itself.
 *
 * @param {HTMLElement} map The venue map's wrapper.
 */
function keepFocusThroughSwap( map ) {
	let focused = null;

	map.addEventListener( 'focusin', ( event ) => {
		focused = event.target;
	} );

	// Focus moving on is not the same as its element being taken away. Chrome
	// reports both here, but only the first names where focus went.
	map.addEventListener( 'focusout', ( event ) => {
		if ( event.relatedTarget ) {
			focused = null;
		}
	} );

	new window.MutationObserver( () => {
		if ( ! focused || focused.isConnected ) {
			return;
		}

		focused = null;

		const { activeElement, body } = map.ownerDocument;

		// Anywhere but the page itself means focus has already been put
		// somewhere on purpose.
		if ( activeElement !== body ) {
			return;
		}

		const target = map.querySelector( '[tabindex="0"], iframe' ) ?? map;

		if ( target === map ) {
			map.tabIndex = -1;
		}

		target.focus( { preventScroll: true } );
	} ).observe( map, { childList: true } );
}

store( 'groups-site/venue-map', {
	callbacks: {
		init() {
			const { ref } = getElement();

			// `withScope` keeps this element and its context for later, so
			// GatherPress's callback finds them when it finally runs.
			const mount = withScope( () => {
				store( 'gatherpress/venue-map' ).callbacks?.init?.();
			} );

			const observer = new window.IntersectionObserver( ( entries ) => {
				if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
					observer.disconnect();
					mount();
				}
			} );

			keepFocusThroughSwap( ref );
			observer.observe( ref );
		},
	},
} );
