/**
 * Mounts a venue's interactive map when it scrolls into view, not at page load.
 *
 * `defer_venue_map()` in functions.php points the map block at this store,
 * which runs GatherPress's own `init` once the map is on screen.
 */
// eslint-disable-next-line import/no-extraneous-dependencies -- WordPress serves this through its import map.
import { getElement, store, withScope } from '@wordpress/interactivity';

/**
 * Moves keyboard focus to the live map when the swap removes the focused
 * element. Tabbing to the static map's link is what scrolls it into view, and
 * focus would otherwise fall back to the top of the page.
 *
 * @param {HTMLElement} map The venue map's wrapper.
 */
function keepFocusThroughSwap( map ) {
	// This script loads late, so focus can be inside the map already.
	const current = map.ownerDocument.activeElement;
	let focused = map.contains( current ) ? current : null;

	map.addEventListener( 'focusin', ( event ) => {
		focused = event.target;
	} );

	// Forget an element the visitor left. Chrome also fires this for a removal,
	// before it happens, so the check waits for the removing script to finish.
	map.addEventListener( 'focusout', ( { target } ) => {
		window.queueMicrotask( () => {
			const { activeElement } = map.ownerDocument;

			if ( focused === target && target.isConnected && activeElement !== target ) {
				focused = null;
			}
		} );
	} );

	new window.MutationObserver( () => {
		if ( ! focused || focused.isConnected ) {
			return;
		}

		focused = null;

		const { activeElement, body } = map.ownerDocument;

		// Focus was already put somewhere else on purpose.
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

			// Keeps this element and context for GatherPress's callback to find later.
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
