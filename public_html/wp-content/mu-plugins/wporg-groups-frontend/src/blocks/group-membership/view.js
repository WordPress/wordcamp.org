/**
 * Group Membership block — Interactivity API store.
 *
 * @package WordCamp\Groups\Frontend
 */

import { store, getContext } from '@wordpress/interactivity';
import { __, _n, sprintf } from '@wordpress/i18n';

store( 'wporg/group-membership', {
	state: {
		get buttonLabel() {
			const ctx = getContext();
			if ( ctx.loading ) {
				return '\u2026';
			}
			return ctx.isMember ? ctx.roleLabel : __( 'Join this group', 'wporg-groups-frontend' );
		},

		get countLabel() {
			const count = getContext().memberCount;
			return sprintf(
				_n( '%d member', '%d members', count, 'wporg-groups-frontend' ),
				count
			);
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
					headers: { 'X-WP-Nonce': await getNonce() },
				} );

				const data = await resp.json();

				if ( data.success ) {
					ctx.isMember = true;
					ctx.roleLabel = 'Member';
					ctx.memberCount = data.memberCount;
					// Reload to get the full member UI server-rendered.
					window.location.reload();
				}
			} catch {
				// Silently fail.
			} finally {
				ctx.loading = false;
			}
		},

		async leave() {
			const ctx = getContext();

			if ( ! ctx.isMember || ctx.loading ) {
				return;
			}

			if ( ! window.confirm( __( 'Leave this group?', 'wporg-groups-frontend' ) ) ) {
				return;
			}

			ctx.loading = true;

			try {
				const resp = await fetch( ctx.leaveApi, {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': await getNonce() },
				} );

				const data = await resp.json();

				if ( data.success ) {
					ctx.isMember = false;
					ctx.memberCount = Math.max( 0, ctx.memberCount - 1 );
					window.location.reload();
				}
			} catch {
				// Silently fail.
			} finally {
				ctx.loading = false;
			}
		},
	},
} );

let cachedNonce = null;

async function getNonce() {
	if ( cachedNonce ) {
		return cachedNonce;
	}
	if ( window.wpApiSettings?.nonce ) {
		cachedNonce = window.wpApiSettings.nonce;
		return cachedNonce;
	}
	// Fallback: use the GatherPress nonce endpoint.
	const apiBase = window.GatherPress?.urls?.eventApiUrl;
	if ( apiBase ) {
		const nonceResp = await fetch( apiBase + '/nonce', {
			credentials: 'same-origin',
		} );
		const nonceData = await nonceResp.json();
		cachedNonce = nonceData.nonce;
		return cachedNonce;
	}
	return '';
}
