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

			observer.observe( ref );
		},
	},
} );
