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
					headers: { 'X-WP-Nonce': await getNonce( ctx ) },
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
					headers: { 'X-WP-Nonce': await getNonce( ctx ) },
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

		async updateNotificationPreference( event ) {
			const ctx = getContext();

			if ( ! ctx.isMember || ctx.preferenceSaving ) {
				return;
			}

			const previousValue = ctx.notificationOptIn;
			const optIn = Boolean( event.target.checked );

			ctx.notificationOptIn = optIn;
			ctx.preferenceSaving = true;
			ctx.preferenceMessage = '';
			ctx.preferenceNoticeSuccess = false;
			ctx.preferenceNoticeError = false;

			try {
				const resp = await fetch( ctx.preferenceApi, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': await getNonce( ctx ),
					},
					body: JSON.stringify( { opt_in: optIn } ),
				} );

				const data = await resp.json();

				if ( ! resp.ok || ! data.success ) {
					throw new Error( 'Unable to save email preference.' );
				}

				ctx.notificationOptIn = Boolean( data.optIn );
				ctx.preferenceMessage = ctx.preferenceSavedLabel;
				ctx.preferenceNoticeSuccess = true;
			} catch {
				ctx.notificationOptIn = previousValue;
				ctx.preferenceMessage = ctx.preferenceErrorLabel;
				ctx.preferenceNoticeError = true;
			} finally {
				ctx.preferenceSaving = false;
			}
		},
	},
} );

let cachedNonce = null;

async function getNonce( ctx ) {
	if ( cachedNonce ) {
		return cachedNonce;
	}
	if ( ctx.restNonce ) {
		cachedNonce = ctx.restNonce;
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
