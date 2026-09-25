/**
 * Group Members block — Interactivity API store.
 *
 * Backs the self-serve role switcher, which only renders on the beta
 * testing groups listed in `Capabilities\SELF_SERVE_ROLE_GROUPS`.
 *
 * @package WordCamp\Groups\Frontend
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'wporg/group-members', {
	actions: {
		async switchRole( event ) {
			const ctx = getContext();

			// Read the target role before the first `await` — `currentTarget`
			// is only set while the event is being dispatched, and the label
			// and description spans mean `target` may be a child element.
			const role = event.currentTarget?.dataset?.role;

			if ( ! role || role === ctx.currentRole || ctx.saving ) {
				return;
			}

			ctx.saving = true;
			ctx.message = '';
			ctx.isError = false;

			try {
				const resp = await fetch( ctx.roleApi, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ctx.restNonce,
					},
					body: JSON.stringify( { role } ),
				} );

				const data = await resp.json();

				if ( ! resp.ok ) {
					throw new Error( data?.message || ctx.errorLabel );
				}

				ctx.currentRole = data.role;

				/*
				 * A role change reaches far more of the page than this block —
				 * the event management buttons, the group settings tab, the
				 * membership badge in the sidebar — and all of it is rendered
				 * server side. Reload rather than patch, the same call
				 * `group-membership` makes for join and leave.
				 *
				 * `saving` deliberately stays true: the buttons should remain
				 * disabled for the moment between here and the new document.
				 */
				window.location.reload();
			} catch ( error ) {
				ctx.message = error.message || ctx.errorLabel;
				ctx.isError = true;
				ctx.saving = false;
			}
		},
	},
} );
