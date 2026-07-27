/**
 * Group Membership block — Interactivity API store.
 *
 * @package WordCamp\Groups\Frontend
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'wporg/group-membership', {
	state: {
		get buttonLabel() {
			const ctx = getContext();
			if ( ctx.loading ) {
				return '\u2026';
			}
			return ctx.isMember ? ctx.roleLabel : ctx.joinLabel;
		},

		get countLabel() {
			return getContext().countLabel;
		},

		get isMember() {
			return getContext().isMember;
		},

		get memberCount() {
			return getContext().memberCount;
		},
	},

	actions: {
		async join() {
			const ctx = getContext();

			if ( ! ctx.isLoggedIn ) {
				window.location.href = ctx.loginUrl;
				return;
			}

			if ( ctx.isMember || ctx.loading ) {
				return;
			}

			ctx.loading = true;

			try {
				const resp = await fetch( ctx.joinApi, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );

				const data = await resp.json();

				if ( ! resp.ok ) {
					throw new Error( data?.message || resp.statusText );
				}

				if ( data.success ) {
					ctx.isMember = true;
					ctx.roleLabel = ctx.memberLabel;
					ctx.memberCount = data.memberCount;
					// Reload to get the full member UI server-rendered.
					window.location.reload();
				}
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Group join failed:', error );
			} finally {
				ctx.loading = false;
			}
		},

		async leave() {
			const ctx = getContext();

			if ( ! ctx.isMember || ctx.loading ) {
				return;
			}

			if ( ! window.confirm( ctx.leaveConfirm ) ) {
				return;
			}

			ctx.loading = true;

			try {
				const resp = await fetch( ctx.leaveApi, {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': ctx.nonce },
				} );

				const data = await resp.json();

				if ( ! resp.ok ) {
					throw new Error( data?.message || resp.statusText );
				}

				if ( data.success ) {
					ctx.isMember = false;
					ctx.memberCount = Math.max( 0, ctx.memberCount - 1 );
					window.location.reload();
				}
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Group leave failed:', error );
			} finally {
				ctx.loading = false;
			}
		},
	},
} );
