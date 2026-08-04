/**
 * Event RSVP block — Interactivity API store.
 *
 * Handles the avatar-stack click → full-screen modal, RSVP toggling,
 * and attendee list display.
 */

// WordPress provides this as a script-module dependency.
// eslint-disable-next-line import/no-extraneous-dependencies
import { getContext, getElement, store } from '@wordpress/interactivity';

let cachedNonce = null;
let activeModal = null;
let modalTrigger = null;
let previousBodyOverflow = '';

const focusableSelector = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
].join( ',' );

store( 'wporg/event-rsvp', {
	state: {
		get isAttending() {
			return getContext().currentUserStatus === 'attending';
		},

		get countLabel() {
			const count = getContext().attendingCount;
			return formatLabel( label( 1 === count ? 'countSingular' : 'countPlural' ), [
				formatNumber( count ),
			] );
		},

		get modalTitle() {
			const ctx = getContext();
			return formatLabel( label( 1 === ctx.attendingCount ? 'modalTitleSingular' : 'modalTitlePlural' ), [
				formatNumber( ctx.attendingCount ),
				ctx.eventTitle,
			] );
		},

		get rsvpButtonLabel() {
			const ctx = getContext();
			if ( ctx.rsvpLoading ) {
				return label( 'loading' );
			}
			if ( ctx.currentUserStatus === 'attending' ) {
				return label( 'attending' );
			}
			return ctx.isMember ? label( 'rsvp' ) : label( 'joinRsvp' );
		},

		get isMember() {
			return getContext().isMember;
		},

		get statusText() {
			const ctx = getContext();
			if ( ctx.currentUserStatus === 'attending' ) {
				return label( 'statusAttending' );
			}
			return label( 'statusNotAttending' );
		},

		get modalRsvpLabel() {
			const ctx = getContext();
			if ( ctx.rsvpLoading ) {
				return label( 'loading' );
			}
			if ( ctx.currentUserStatus === 'attending' ) {
				return label( 'cancelRsvp' );
			}
			return label( 'attend' );
		},
	},

	actions: {
		openModal() {
			openRsvpModal( getContext(), getElement().ref );
		},

		closeModal() {
			closeRsvpModal( getContext() );
		},

		handleBackdropClick( event ) {
			const { ref } = getElement();
			if ( event.target === ref ) {
				closeRsvpModal( getContext() );
			}
		},

		handleModalKeydown( event ) {
			const ctx = getContext();
			if ( ! ctx.modalOpen ) {
				return;
			}

			if ( event.key === 'Escape' ) {
				event.preventDefault();
				closeRsvpModal( ctx );
				return;
			}

			if ( event.key !== 'Tab' || ! activeModal ) {
				return;
			}

			const focusable = getFocusableElements( activeModal );
			if ( ! focusable.length ) {
				event.preventDefault();
				activeModal.focus();
				return;
			}

			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			const activeElement = activeModal.ownerDocument.activeElement;
			const focusOutsideModal = ! activeModal.contains( activeElement );

			if (
				( event.shiftKey && ( activeElement === first || focusOutsideModal ) ) ||
				( ! event.shiftKey && ( activeElement === last || focusOutsideModal ) )
			) {
				event.preventDefault();
				( event.shiftKey ? last : first ).focus();
			}
		},

		async handleRsvpButton() {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				window.location.href = ctx.loginUrl;
				return;
			}
			if ( ctx.currentUserStatus === 'attending' ) {
				openRsvpModal( ctx, getElement().ref );
				return;
			}
			return doToggleRsvp( ctx );
		},

		async toggleRsvp() {
			const ctx = getContext();
			return doToggleRsvp( ctx );
		},
	},
} );

function openRsvpModal( ctx, trigger ) {
	const block = trigger?.closest( '.wp-block-wporg-event-rsvp' );
	const modal = block?.querySelector( '.wporg-event-rsvp__modal' );

	if ( ! modal ) {
		return;
	}

	modalTrigger = trigger;
	activeModal = modal;
	previousBodyOverflow = document.body.style.overflow;
	ctx.modalOpen = true;
	document.body.style.overflow = 'hidden';

	const focusable = getFocusableElements( modal );
	scheduleFocus( focusable[ 0 ] || modal );
}

function closeRsvpModal( ctx ) {
	const trigger = modalTrigger;

	ctx.modalOpen = false;
	document.body.style.overflow = previousBodyOverflow;
	activeModal = null;
	modalTrigger = null;
	previousBodyOverflow = '';

	scheduleFocus( trigger );
}

function getFocusableElements( modal ) {
	return Array.from( modal.querySelectorAll( focusableSelector ) ).filter(
		( element ) => ! element.hasAttribute( 'hidden' ) && element.getAttribute( 'aria-hidden' ) !== 'true'
	);
}

function scheduleFocus( element ) {
	if ( ! element ) {
		return;
	}

	window.requestAnimationFrame( () => {
		if ( document.contains( element ) ) {
			element.focus();
		}
	} );
}

async function doToggleRsvp( ctx ) {
	if ( ! ctx.isLoggedIn ) {
		window.location.href = ctx.loginUrl;
		return;
	}

	if ( ctx.isPastEvent || ctx.rsvpLoading ) {
		return;
	}

	ctx.rsvpNotice = '';

	// Join group first if not a member.
	if ( ! ctx.isMember && ctx.joinApi ) {
		ctx.rsvpLoading = true;
		try {
			const nonce = await getNonce( ctx.apiBase );
			const joinResp = await fetch( ctx.joinApi, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce },
			} );
			const joinData = await joinResp.json();
			if ( ! joinResp.ok || ! joinData.success ) {
				throw new Error( 'Unable to join the group.' );
			}
			ctx.isMember = true;
		} catch {
			ctx.rsvpLoading = false;
			ctx.rsvpNotice = labelFromContext( ctx, 'rsvpError' );
			return;
		}
	}

	const newStatus = ctx.currentUserStatus === 'attending' ? 'not_attending' : 'attending';

	const oldStatus = ctx.currentUserStatus;
	const oldCount = ctx.attendingCount;
	ctx.currentUserStatus = newStatus;
	ctx.attendingCount += newStatus === 'attending' ? 1 : -1;
	ctx.rsvpLoading = true;

	try {
		const data = await sendRsvp( ctx, newStatus );

		ctx.currentUserStatus = data.status;
		ctx.attendingCount = data.responses.attending.count;
		ctx.rsvpNotice = getRsvpSuccessNotice( ctx, data.status );
		refreshAttendees( ctx );
	} catch {
		ctx.currentUserStatus = oldStatus;
		ctx.attendingCount = oldCount;
		ctx.rsvpNotice = labelFromContext( ctx, 'rsvpError' );
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

	const data = await resp.json();
	if ( ! resp.ok || ! data?.success ) {
		throw new Error( data?.message || resp.statusText );
	}

	return data;
}

function getRsvpSuccessNotice( ctx, status ) {
	if ( status === 'attending' ) {
		return labelFromContext( ctx, 'rsvpSuccessAttending' );
	}
	if ( status === 'waiting_list' ) {
		return labelFromContext( ctx, 'rsvpSuccessWaitingList' );
	}
	return labelFromContext( ctx, 'rsvpSuccessNotAttending' );
}

async function refreshAttendees( ctx ) {
	try {
		const resp = await fetch( ctx.apiBase + '/rsvp-responses?post_id=' + ctx.postId );
		const data = await resp.json();

		if ( data.success && data.data?.attending?.records ) {
			const list = document.querySelector( '.wporg-event-rsvp__attendee-list' );
			if ( ! list ) {
				return;
			}

			const records = data.data.attending.records;
			ctx.attendingCount = data.data.attending.count;

			list.innerHTML = records.length
				? records
						.map(
							( record ) =>
								'<a class="wporg-event-rsvp__attendee" href="' +
								escAttr( record.profile ) +
								'" target="_blank" rel="noopener">' +
								'<img class="wporg-event-rsvp__attendee-avatar" src="' +
								escAttr( record.photo ) +
								'" alt="" width="48" height="48" loading="lazy" />' +
								'<div class="wporg-event-rsvp__attendee-info">' +
								'<span class="wporg-event-rsvp__attendee-name">' +
								escHtml( record.name ) +
								'</span>' +
								'</div></a>'
						)
						.join( '' )
				: '<p class="wporg-event-rsvp__empty">' +
				  escHtml( labelFromContext( ctx, 'emptyAttendees' ) ) +
				  '</p>';

			const avatars = document.querySelector( '.wporg-event-rsvp__avatars' );
			if ( avatars ) {
				const maxAvatars = 12;
				const visible = records.slice( 0, maxAvatars );
				const overflow = Math.max( 0, data.data.attending.count - maxAvatars );

				avatars.innerHTML =
					visible
						.map(
							( record ) =>
								'<img class="wporg-event-rsvp__avatar" src="' +
								escAttr( record.photo ) +
								'" alt="" width="40" height="40" loading="lazy" />'
						)
						.join( '' ) +
					( overflow > 0 ? '<span class="wporg-event-rsvp__overflow">+' + overflow + '</span>' : '' );
			}
		}
	} catch {
		// The optimistic context update already reflects the new state.
	}
}

function label( key ) {
	return labelFromContext( getContext(), key );
}

function labelFromContext( ctx, key ) {
	return ctx.labels?.[ key ] || '';
}

function formatNumber( value ) {
	return Number( value || 0 ).toLocaleString();
}

function formatLabel( format, values ) {
	let index = 0;

	return String( format ).replace( /%(\d+\$)?s/g, ( match, position ) => {
		const valueIndex = position ? parseInt( position, 10 ) - 1 : index++;
		return values[ valueIndex ] ?? '';
	} );
}

function escHtml( str ) {
	const element = document.createElement( 'span' );
	element.textContent = str;
	return element.innerHTML;
}

function escAttr( str ) {
	return str.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
}
