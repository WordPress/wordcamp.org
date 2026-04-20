/**
 * Event RSVP block — Interactivity API store.
 *
 * Handles the avatar-stack click → full-screen modal, RSVP toggling,
 * and attendee list display.
 *
 * @package WordCamp\Groups\Frontend
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

let cachedNonce = null;

const { state } = store( 'wporg/event-rsvp', {
	state: {
		get isAttending() {
			return getContext().currentUserStatus === 'attending';
		},

		get countLabel() {
			const count = getContext().attendingCount;
			return count === 1 ? '1 going' : count + ' going';
		},

		get modalTitle() {
			const ctx = getContext();
			return ctx.attendingCount + ' Attending ' + ctx.eventTitle;
		},

		get rsvpButtonLabel() {
			const ctx = getContext();
			if ( ctx.rsvpLoading ) {
				return '\u2026';
			}
			if ( ctx.currentUserStatus === 'attending' ) {
				return '\u2713 Attending';
			}
			return 'RSVP';
		},

		get statusText() {
			const ctx = getContext();
			if ( ctx.currentUserStatus === 'attending' ) {
				return 'You are attending this event.';
			}
			return 'You have not RSVPed to this event.';
		},

		get modalRsvpLabel() {
			const ctx = getContext();
			if ( ctx.rsvpLoading ) {
				return '\u2026';
			}
			if ( ctx.currentUserStatus === 'attending' ) {
				return 'Cancel RSVP';
			}
			return 'Attend';
		},
	},

	actions: {
		openModal() {
			const ctx = getContext();
			ctx.modalOpen = true;
			document.body.style.overflow = 'hidden';
		},

		closeModal() {
			const ctx = getContext();
			ctx.modalOpen = false;
			document.body.style.overflow = '';
		},

		handleBackdropClick( event ) {
			const { ref } = getElement();
			if ( event.target === ref ) {
				const ctx = getContext();
				ctx.modalOpen = false;
				document.body.style.overflow = '';
			}
		},

		handleEscape( event ) {
			if ( event.key === 'Escape' ) {
				const ctx = getContext();
				if ( ctx.modalOpen ) {
					ctx.modalOpen = false;
					document.body.style.overflow = '';
				}
			}
		},

		handleRsvpButton() {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				window.location.href = ctx.loginUrl;
				return;
			}
			if ( ctx.currentUserStatus === 'attending' ) {
				ctx.modalOpen = true;
				document.body.style.overflow = 'hidden';
				return;
			}
			doToggleRsvp( ctx );
		},

		handleSummaryKeydown( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				const ctx = getContext();
				ctx.modalOpen = true;
				document.body.style.overflow = 'hidden';
			}
		},

		async toggleRsvp() {
			const ctx = getContext();
			doToggleRsvp( ctx );
		},
	},
} );

async function doToggleRsvp( ctx ) {
	if ( ! ctx.isLoggedIn ) {
		window.location.href = ctx.loginUrl;
		return;
	}

	if ( ctx.isPastEvent || ctx.rsvpLoading ) {
		return;
	}

	const newStatus =
		ctx.currentUserStatus === 'attending' ? 'not_attending' : 'attending';

	const oldStatus = ctx.currentUserStatus;
	const oldCount = ctx.attendingCount;
	ctx.currentUserStatus = newStatus;
	ctx.attendingCount += newStatus === 'attending' ? 1 : -1;
	ctx.rsvpLoading = true;

	try {
		const data = await sendRsvp( ctx, newStatus );

		if ( data && data.success ) {
			ctx.currentUserStatus = data.status;
			ctx.attendingCount = data.responses.attending.count;
			refreshAttendees( ctx );
		} else {
			ctx.currentUserStatus = oldStatus;
			ctx.attendingCount = oldCount;
		}
	} catch {
		ctx.currentUserStatus = oldStatus;
		ctx.attendingCount = oldCount;
	} finally {
		ctx.rsvpLoading = false;
	}
}

async function getNonce( apiBase ) {
	if ( cachedNonce ) {
		return cachedNonce;
	}
	const resp = await fetch( apiBase + '/nonce', {
		credentials: 'same-origin',
	} );
	const data = await resp.json();
	cachedNonce = data.nonce;
	return cachedNonce;
}

async function sendRsvp( ctx, status, retry = false ) {
	const nonce = await getNonce( ctx.apiBase );
	const resp = await fetch( ctx.apiBase + '/rsvp', {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		body: JSON.stringify( {
			post_id: ctx.postId,
			status,
			guests: 0,
			anonymous: 0,
		} ),
	} );

	if ( resp.status === 403 && ! retry ) {
		cachedNonce = null;
		return sendRsvp( ctx, status, true );
	}

	return resp.json();
}

async function refreshAttendees( ctx ) {
	try {
		const resp = await fetch(
			ctx.apiBase + '/rsvp-responses?post_id=' + ctx.postId
		);
		const data = await resp.json();

		if ( data.success && data.data?.attending?.records ) {
			const list = document.querySelector(
				'.wporg-event-rsvp__attendee-list'
			);
			if ( ! list ) {
				return;
			}

			const records = data.data.attending.records;
			ctx.attendingCount = data.data.attending.count;

			list.innerHTML = records.length
				? records
						.map(
							( r ) =>
								'<a class="wporg-event-rsvp__attendee" href="' +
								escAttr( r.profile ) +
								'" target="_blank" rel="noopener">' +
								'<img class="wporg-event-rsvp__attendee-avatar" src="' +
								escAttr( r.photo ) +
								'" alt="" width="48" height="48" loading="lazy" />' +
								'<div class="wporg-event-rsvp__attendee-info">' +
								'<span class="wporg-event-rsvp__attendee-name">' +
								escHtml( r.name ) +
								'</span>' +
								'</div></a>'
						)
						.join( '' )
				: '<p class="wporg-event-rsvp__empty">No attendees yet. Be the first to RSVP!</p>';

			const avatars = document.querySelector(
				'.wporg-event-rsvp__avatars'
			);
			if ( avatars ) {
				const maxAvatars = 12;
				const visible = records.slice( 0, maxAvatars );
				const overflow = Math.max(
					0,
					data.data.attending.count - maxAvatars
				);

				avatars.innerHTML =
					visible
						.map(
							( r ) =>
								'<img class="wporg-event-rsvp__avatar" src="' +
								escAttr( r.photo ) +
								'" alt="' +
								escAttr( r.name ) +
								'" width="40" height="40" loading="lazy" />'
						)
						.join( '' ) +
					( overflow > 0
						? '<span class="wporg-event-rsvp__overflow">+' +
							overflow +
							'</span>'
						: '' );
			}
		}
	} catch {
		// The optimistic context update already reflects the new state.
	}
}

function escHtml( str ) {
	const el = document.createElement( 'span' );
	el.textContent = str;
	return el.innerHTML;
}

function escAttr( str ) {
	return str
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}
