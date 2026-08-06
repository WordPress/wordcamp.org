/**
 * Event RSVP block — Interactivity API store.
 *
 * Handles the avatar-stack click → full-screen modal, RSVP toggling,
 * and attendee list display.
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

let cachedNonce = null;
let activeModalState = null;

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

		get isNotAttending() {
			return getContext().currentUserStatus !== 'attending';
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
			if ( ! ctx.modalOpen || activeModalState?.context !== ctx ) {
				return;
			}

			if ( event.key === 'Escape' ) {
				event.preventDefault();
				closeRsvpModal( ctx );
				return;
			}

			if ( event.key !== 'Tab' ) {
				return;
			}

			const { modal } = activeModalState;
			const focusable = getFocusableElements( modal );
			if ( ! focusable.length ) {
				event.preventDefault();
				modal.focus();
				return;
			}

			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			const activeElement = modal.ownerDocument.activeElement;
			const focusOutsideModal = ! modal.contains( activeElement );

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
			const { ref } = getElement();
			if ( ! ctx.isLoggedIn ) {
				window.location.href = ctx.loginUrl;
				return;
			}
			// Attending already, or there are questions to answer first —
			// either way the modal is where the next step lives.
			if ( ctx.currentUserStatus === 'attending' || ctx.hasQuestions ) {
				openRsvpModal( ctx, ref );
				return;
			}
			return doToggleRsvp( ctx, ref );
		},

		async toggleRsvp() {
			const ctx = getContext();
			return doToggleRsvp( ctx, getElement().ref );
		},

		async saveAnswers() {
			const ctx = getContext();
			return doSaveAnswers( ctx, getElement().ref );
		},
	},
} );

function openRsvpModal( ctx, trigger ) {
	const block = trigger?.closest( '.wp-block-wporg-event-rsvp' );
	const modal = block?.querySelector( '.wporg-event-rsvp__modal' );

	if ( ! modal ) {
		return;
	}

	const previousBodyOverflow = activeModalState?.previousBodyOverflow ?? document.body.style.overflow;
	if ( activeModalState ) {
		// Full-screen, aria-modal dialogs must not remain open behind one another.
		activeModalState.context.modalOpen = false;
	}

	activeModalState = {
		context: ctx,
		modal,
		previousBodyOverflow,
		trigger,
	};
	ctx.modalOpen = true;
	document.body.style.overflow = 'hidden';

	const focusable = getFocusableElements( modal );
	scheduleFocus( focusable[ 0 ] || modal );
}

function closeRsvpModal( ctx ) {
	ctx.modalOpen = false;
	ctx.questionsError = '';
	if ( activeModalState?.context !== ctx ) {
		return;
	}

	const { previousBodyOverflow, trigger } = activeModalState;
	activeModalState = null;
	document.body.style.overflow = previousBodyOverflow;

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

/**
 * Read the custom registration questions out of this block's modal.
 *
 * The inputs are server-rendered from the event's stored questions, so they're
 * read from the DOM rather than mirrored into interactivity state. Scoped to
 * the block so a page listing several events reads the right one.
 *
 * @param {Element} block The `.wp-block-wporg-event-rsvp` element.
 * @return {{answers: Object, missingRequired: boolean}} Collected answers.
 */
function collectAnswers( block ) {
	const answers = {};
	let missingRequired = false;

	block?.querySelectorAll( '.wporg-event-rsvp__question-input' ).forEach( ( input ) => {
		const value = input.value.trim();
		if ( ! value && input.required ) {
			missingRequired = true;
		}
		// Blanks are sent too, so the server can tell "cleared this answer"
		// from "never rendered this question".
		answers[ input.dataset.questionId ] = value;
	} );

	return { answers, missingRequired };
}

async function doToggleRsvp( ctx, actionElement ) {
	const newStatus = ctx.currentUserStatus === 'attending' ? 'not_attending' : 'attending';

	return submitRsvp( ctx, actionElement, newStatus );
}

/**
 * Save edited answers without changing the attendance itself.
 *
 * Re-sending `attending` is idempotent in `Rsvp::save()`, so this updates the
 * answers on the existing RSVP rather than requiring a cancel/re-RSVP round
 * trip — which would delete the answers and, on a capped event, surrender the
 * seat to the waiting list.
 */
async function doSaveAnswers( ctx, actionElement ) {
	return submitRsvp( ctx, actionElement, 'attending' );
}

async function submitRsvp( ctx, actionElement, newStatus ) {
	if ( ! ctx.isLoggedIn ) {
		window.location.href = ctx.loginUrl;
		return;
	}

	if ( ctx.isPastEvent || ctx.rsvpLoading ) {
		return;
	}

	ctx.rsvpNotice = '';
	ctx.questionsError = '';

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

	const { answers, missingRequired } = ctx.hasQuestions
		? collectAnswers( actionElement?.closest( '.wp-block-wporg-event-rsvp' ) )
		: { answers: {}, missingRequired: false };

	// The server rejects this too — checking here just saves a round trip and
	// keeps whatever the attendee already typed on screen.
	if ( newStatus === 'attending' && missingRequired ) {
		const message = labelFromContext( ctx, 'missingAnswers' );
		ctx.questionsError = message;
		ctx.rsvpNotice = message;
		openRsvpModal( ctx, actionElement );
		return;
	}

	const oldStatus = ctx.currentUserStatus;
	const oldCount = ctx.attendingCount;
	const statusChanged = newStatus !== oldStatus;
	ctx.currentUserStatus = newStatus;
	if ( statusChanged ) {
		ctx.attendingCount += newStatus === 'attending' ? 1 : -1;
	}
	ctx.rsvpLoading = true;

	try {
		const data = await sendRsvp( ctx, newStatus, answers );

		ctx.currentUserStatus = data.status;
		ctx.attendingCount = data.responses.attending.count;
		ctx.rsvpNotice = statusChanged
			? getRsvpSuccessNotice( ctx, data.status )
			: labelFromContext( ctx, 'answersSaved' );

		// Organizers see the answers inline in the attendee list, which the
		// client-side refresh can't rebuild — reload so their view stays
		// complete.
		if ( ctx.canViewAnswers ) {
			window.location.reload();
			return;
		}

		refreshAttendees( ctx, actionElement );
	} catch ( error ) {
		ctx.currentUserStatus = oldStatus;
		ctx.attendingCount = oldCount;

		// Our own validation failures carry a message the attendee can act on
		// ("Please answer: Dietary requirements"). Anything else — a network
		// blip, a 500 — gets the generic retry wording.
		const ours = error?.code?.startsWith?.( 'wporg_groups_' ) && error.message;
		ctx.rsvpNotice = ours ? error.message : labelFromContext( ctx, 'rsvpError' );
		if ( ours ) {
			ctx.questionsError = error.message;
			openRsvpModal( ctx, actionElement );
		}
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

/**
 * Save the RSVP through our own endpoint rather than GatherPress's, so the
 * status and the answers to the event's custom registration questions are
 * written in one request and required questions can block the RSVP.
 */
async function sendRsvp( ctx, status, answers, retry = false ) {
	const nonce = await getNonce( ctx.apiBase );
	const resp = await fetch( ctx.rsvpApi, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		body: JSON.stringify( { status, answers: answers || {} } ),
	} );

	if ( resp.status === 403 && ! retry ) {
		cachedNonce = null;
		return sendRsvp( ctx, status, answers, true );
	}

	const data = await resp.json();
	if ( ! resp.ok || ! data?.success ) {
		const error = new Error( data?.message || resp.statusText );
		// Keep the code so the caller can tell a message meant for the
		// attendee ("Please answer: …") from an opaque transport failure.
		error.code = data?.code || '';
		throw error;
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

async function refreshAttendees( ctx, actionElement ) {
	const block = actionElement?.closest( '.wp-block-wporg-event-rsvp' );
	if ( ! block ) {
		return;
	}

	try {
		const resp = await fetch( ctx.apiBase + '/rsvp-responses?post_id=' + ctx.postId );
		const data = await resp.json();

		if ( data.success && data.data?.attending?.records ) {
			const list = block.querySelector( '.wporg-event-rsvp__attendee-list' );
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

			const avatars = block.querySelector( '.wporg-event-rsvp__avatars' );
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
