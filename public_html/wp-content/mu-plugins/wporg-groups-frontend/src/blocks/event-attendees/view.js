/**
 * Event Attendees: keeps the list in step with an RSVP made without a reload.
 *
 * The RSVP block refreshes its own roster after an RSVP and shares the
 * responses it fetched as `wporg-groups-rsvp-responses`. This rebuilds the
 * list the way `render.php` would on the next page load (#2451).
 */

/**
 * @param {string} url A URL from the responses.
 * @return {string} The URL, or an empty string when it isn't http(s).
 */
function safeUrl( url ) {
	return /^https?:\/\//i.test( url || '' ) ? url : '';
}

/**
 * @param {string} format A translated format with one `%s`.
 * @param {number} count  The number to put into it.
 * @return {string} The formatted text.
 */
function formatCount( format, count ) {
	const lang = document.documentElement.lang || undefined;
	let number;
	try {
		number = new Intl.NumberFormat( lang ).format( count );
	} catch {
		number = String( count );
	}
	return format.replace( '%s', number );
}

/**
 * @param {Object} record One attendee from the responses.
 * @return {HTMLLIElement} The list item.
 */
function renderAttendee( record ) {
	const item = document.createElement( 'li' );
	item.className = 'wporg-event-attendees__item';

	const link = document.createElement( 'a' );
	link.className = 'wporg-event-attendees__link';
	link.href = safeUrl( record.profile );
	link.target = '_blank';
	link.rel = 'noopener';

	const avatar = document.createElement( 'img' );
	avatar.className = 'wporg-event-attendees__avatar';
	avatar.src = safeUrl( record.photo );
	avatar.alt = '';
	avatar.width = 40;
	avatar.height = 40;
	avatar.loading = 'lazy';

	const name = document.createElement( 'span' );
	name.className = 'wporg-event-attendees__name';
	name.textContent = record.name || '';

	link.append( avatar, name );
	item.append( link );

	return item;
}

/**
 * @param {Element}  section The block's wrapper.
 * @param {Object[]} records The attending records.
 */
export function render( section, records ) {
	const { headingFormat, showAllFormat } = section.dataset;
	const limit = Number( section.dataset.visibleLimit ) || 12;
	const count = records.length;

	const list = document.createElement( 'ul' );
	list.className = 'wporg-event-attendees__list';
	list.append( ...records.slice( 0, limit ).map( renderAttendee ) );

	const heading = section.querySelector( '.wporg-event-attendees__heading' );
	if ( heading ) {
		heading.textContent = formatCount( headingFormat, count );
	}

	section.querySelector( ':scope > .wporg-event-attendees__list' )?.replaceWith( list );

	const wasOpen = !! section.querySelector( '.wporg-event-attendees__more' )?.open;
	section.querySelector( '.wporg-event-attendees__more' )?.remove();

	const overflow = records.slice( limit );
	if ( overflow.length ) {
		const more = document.createElement( 'details' );
		more.className = 'wporg-event-attendees__more';
		more.open = wasOpen;

		const toggle = document.createElement( 'summary' );
		toggle.className = 'wporg-event-attendees__toggle';
		toggle.textContent = formatCount( showAllFormat, count );

		const rest = document.createElement( 'ul' );
		rest.className = 'wporg-event-attendees__list';
		rest.append( ...overflow.map( renderAttendee ) );

		more.append( toggle, rest );
		section.append( more );
	}

	section.hidden = ! count;
}

/**
 * @param {CustomEvent} event The RSVP block's `wporg-groups-rsvp-responses`.
 */
function onResponses( event ) {
	const { postId, attending } = event.detail || {};
	if ( ! Array.isArray( attending?.records ) ) {
		return;
	}

	document.querySelectorAll( '.wporg-event-attendees[data-heading-format]' ).forEach( ( section ) => {
		if ( postId && Number( section.dataset.postId ) !== postId ) {
			return;
		}
		render( section, attending.records );
	} );
}

document.addEventListener( 'wporg-groups-rsvp-responses', onResponses );
