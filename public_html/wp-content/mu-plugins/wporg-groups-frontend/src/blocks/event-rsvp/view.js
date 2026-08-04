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
			return formatLabel(
				label( 1 === count ? 'countSingular' : 'countPlural' ),
				[ formatNumber( count ) ]
			);
		},

		get modalTitle() {
			const ctx = getContext();
			return formatLabel(
				label(
					1 === ctx.attendingCount
						? 'modalTitleSingular'
						: 'modalTitlePlural'
				),
				[ formatNumber( ctx.attendingCount ), ctx.eventTitle ]
			);
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
			openModal( getContext() );
		},

		closeModal() {
			closeModal( getContext() );
		},

		handleBackdropClick( event ) {
			const { ref } = getElement();
			if ( event.target === ref ) {
				closeModal( getContext() );
			}
		},

		handleEscape( event ) {
			if ( event.key === 'Escape' ) {
				const ctx = getContext();
				if ( ctx.modalOpen ) {
					closeModal( ctx );
				}
			}
		},

		handleRsvpButton() {
			const ctx = getContext();
			if ( ! ctx.isLoggedIn ) {
				window.location.href = ctx.loginUrl;
				return;
			}
			// Attending already, or there are questions to answer first —
			// either way the modal is where the next step lives.
			if ( ctx.currentUserStatus === 'attending' || ctx.hasQuestions ) {
				openModal( ctx );
				return;
			}
			doToggleRsvp( ctx );
		},

		handleSummaryKeydown( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				openModal( getContext() );
			}
		},

		async toggleRsvp() {
			const ctx = getContext();
			doToggleRsvp( ctx );
		},
	},
} );

function openModal( ctx ) {
	ctx.modalOpen = true;
	document.body.style.overflow = 'hidden';
}

function closeModal( ctx ) {
	ctx.modalOpen = false;
	ctx.rsvpError = '';
	document.body.style.overflow = '';
}

/**
 * Read the custom registration questions out of the modal.
 *
 * The inputs are server-rendered from the event's stored questions, so they're
 * read straight from the DOM rather than mirrored into interactivity state.
 *
 * @return {{answers: Object, missingRequired: boolean}} Collected answers.
 */
function collectAnswers() {
	const answers = {};
	let missingRequired = false;

	document
		.querySelectorAll( '.wporg-event-rsvp__question-input' )
		.forEach( ( input ) => {
			const value = input.value.trim();
			if ( ! value ) {
				if ( input.required ) {
					missingRequired = true;
				}
				return;
			}
			answers[ input.dataset.questionId ] = value;
		} );

	return { answers, missingRequired };
}

async function doToggleRsvp( ctx ) {
	if ( ! ctx.isLoggedIn ) {
		window.location.href = ctx.loginUrl;
		return;
	}

	if ( ctx.isPastEvent || ctx.rsvpLoading ) {
		return;
	}

	ctx.rsvpError = '';

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
			if ( joinData.success ) {
				ctx.isMember = true;
			} else {
				ctx.rsvpLoading = false;
				return;
			}
		} catch {
			ctx.rsvpLoading = false;
			return;
		}
	}

	const newStatus =
		ctx.currentUserStatus === 'attending' ? 'not_attending' : 'attending';

	const { answers, missingRequired } = ctx.hasQuestions
		? collectAnswers()
		: { answers: {}, missingRequired: false };

	// The server rejects this too — checking here just saves a round trip and
	// keeps whatever the attendee already typed on screen.
	if ( newStatus === 'attending' && missingRequired ) {
		ctx.rsvpError = labelFromContext( ctx, 'missingAnswers' );
		openModal( ctx );
		return;
	}

	const oldStatus = ctx.currentUserStatus;
	const oldCount = ctx.attendingCount;
	ctx.currentUserStatus = newStatus;
	ctx.attendingCount += newStatus === 'attending' ? 1 : -1;
	ctx.rsvpLoading = true;

	try {
		const data = await sendRsvp( ctx, newStatus, answers );

		if ( data && data.success ) {
			ctx.currentUserStatus = data.status;
			ctx.attendingCount = data.responses.attending.count;

			// Organizers see the answers inline in the attendee list, which
			// the client-side refresh below can't rebuild — reload instead so
			// their view stays complete.
			if ( ctx.canViewAnswers ) {
				window.location.reload();
				return;
			}

			refreshAttendees( ctx );
		} else {
			ctx.currentUserStatus = oldStatus;
			ctx.attendingCount = oldCount;
			ctx.rsvpError =
				( data && data.message ) ||
				labelFromContext( ctx, 'rsvpFailed' );
			openModal( ctx );
		}
	} catch {
		ctx.currentUserStatus = oldStatus;
		ctx.attendingCount = oldCount;
		ctx.rsvpError = labelFromContext( ctx, 'rsvpFailed' );
		openModal( ctx );
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
				: '<p class="wporg-event-rsvp__empty">' +
					escHtml( labelFromContext( ctx, 'emptyAttendees' ) ) +
					'</p>';

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
