/**
 * Sponsors block — Interactivity API store.
 *
 * The list renders capped at the block's `limit`; this expands it in place.
 * The "Show all" button is server-rendered `hidden` and only revealed once
 * this store hydrates, so it never appears without a working handler.
 *
 * @package WordCamp\Groups\Frontend
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'wporg/sponsors', {
	actions: {
		expand() {
			getContext().isExpanded = true;
		},
	},
} );
