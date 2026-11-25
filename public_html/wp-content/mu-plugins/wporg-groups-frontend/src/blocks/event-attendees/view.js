/**
 * Event Attendees block: redraw the list after an RSVP made without a reload.
 *
 * The list is rendered on the server. The RSVP block saves in place and
 * announces it with `wporg-groups-rsvp-changed`, carrying the attending
 * records from the same `Rsvp::responses()` this block renders from, so the
 * list is rebuilt from those rather than fetched again (#2451).
 *
 * Only upcoming events opt in, through the data attributes `render.php` sets.
 * The markup here mirrors `render.php`; change both together.
 */

const SECTION_SELECTOR = '.wporg-event-attendees[data-post-id]';

document.addEventListener( 'wporg-groups-rsvp-changed', ( event ) => {
	const { postId, attending } = event.detail || {};

	// Absent on a response without records: leave the list as it is.
	if ( ! Array.isArray( attending ) ) {
		return;
	}

	document.querySelectorAll( SECTION_SELECTOR ).forEach( ( section ) => {
		if ( postId && Number( section.dataset.postId ) !== postId ) {
			return;
		}

		renderAttendees( section, attending );
	} );
} );

/**
 * Rebuild the heading, the visible list and the "Show all" disclosure.
 *
 * @param {HTMLElement} section The block's `<section>`.
 * @param {Object[]}    records Attending records: `{ name, photo, profile }`.
 */
function renderAttendees( section, records ) {
	const count = records.length;

	// Same as the server: no attendees, no section.
	section.hidden = 0 === count;
	if ( ! count ) {
		return;
	}

	const limit = Number( section.dataset.visibleLimit ) || 12;
	const wasOpen = Boolean( section.querySelector( '.wporg-event-attendees__more' )?.open );

	const heading = section.querySelector( '.wporg-event-attendees__heading' );
	if ( heading ) {
		heading.textContent = formatCount( section.dataset.headingFormat, count );
	}

	section
		.querySelectorAll( ':scope > .wporg-event-attendees__list, :scope > .wporg-event-attendees__more' )
		.forEach( ( element ) => element.remove() );

	section.append( createList( records.slice( 0, limit ) ) );

	const overflow = records.slice( limit );
	if ( overflow.length ) {
		const details = document.createElement( 'details' );
		details.className = 'wporg-event-attendees__more';
		details.open = wasOpen;

		const summary = document.createElement( 'summary' );
		summary.className = 'wporg-event-attendees__toggle';
		summary.textContent = formatCount( section.dataset.moreFormat, count );

		details.append( summary, createList( overflow ) );
		section.append( details );
	}
}

function createList( records ) {
	const list = document.createElement( 'ul' );
	list.className = 'wporg-event-attendees__list';
	list.append( ...records.map( createItem ) );

	return list;
}

function createItem( record ) {
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
 * Keep only web URLs, as `esc_url()` does for the server-rendered list.
 *
 * @param {string} url A URL from the RSVP response.
 * @return {string} The URL, or '' when it isn't http(s).
 */
function safeUrl( url ) {
	return /^https?:\/\//i.test( String( url || '' ) ) ? url : '';
}

/**
 * Fill a translated `%s` (or `%1$s`) format with a count.
 *
 * Script modules can't depend on `wp-i18n`, so the formats arrive translated
 * in data attributes, like the RSVP block's labels.
 *
 * @param {string} format Translated format.
 * @param {number} count  The number to put in.
 * @return {string} The formatted text.
 */
function formatCount( format, count ) {
	return String( format || '' ).replace( /%(1\$)?s/, Number( count ).toLocaleString() );
}
