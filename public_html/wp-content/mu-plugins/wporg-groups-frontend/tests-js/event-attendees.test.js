/**
 * The Attendees list follows an RSVP made without a reload. #2451.
 */

function record( number ) {
	return {
		name: 'Attendee ' + number,
		profile: 'https://profiles.wordpress.org/attendee' + number + '/',
		photo: 'https://secure.gravatar.com/avatar/' + number,
	};
}

function records( count ) {
	return Array.from( { length: count }, ( _value, index ) => record( index + 1 ) );
}

function mountSection( { count = 0, postId = 12 } = {} ) {
	document.body.innerHTML =
		'<section class="wporg-event-attendees" data-post-id="' +
		postId +
		'" data-heading-format="Attendees (%s)" data-show-all-format="Show all %s attendees" data-visible-limit="12"' +
		( count ? '' : ' hidden' ) +
		'>' +
		'<h2 class="wporg-event-attendees__heading">Attendees (' +
		count +
		')</h2>' +
		'<ul class="wporg-event-attendees__list"></ul>' +
		'</section>';
	return document.querySelector( 'section' );
}

function announce( attendingRecords, postId = 12 ) {
	document.dispatchEvent(
		new CustomEvent( 'wporg-groups-rsvp-responses', {
			detail: { postId, attending: { count: attendingRecords.length, records: attendingRecords } },
		} )
	);
}

describe( 'event attendees list', () => {
	beforeAll( () => {
		require( '../src/blocks/event-attendees/view' );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'adds the person who just RSVPd', () => {
		const section = mountSection( { count: 4 } );

		announce( records( 5 ) );

		expect( section.querySelector( 'h2' ).textContent ).toBe( 'Attendees (5)' );
		expect( section.querySelectorAll( '.wporg-event-attendees__item' ) ).toHaveLength( 5 );
		expect( section.querySelector( '.wporg-event-attendees__link' ).getAttribute( 'href' ) ).toBe(
			'https://profiles.wordpress.org/attendee1/'
		);
		expect( section.hidden ).toBe( false );
	} );

	test( 'shows the hidden section for the first RSVP', () => {
		const section = mountSection( { count: 0 } );

		announce( records( 1 ) );

		expect( section.hidden ).toBe( false );
		expect( section.querySelector( 'h2' ).textContent ).toBe( 'Attendees (1)' );
	} );

	test( 'hides the section when the last attendee cancels', () => {
		const section = mountSection( { count: 1 } );

		announce( [] );

		expect( section.hidden ).toBe( true );
	} );

	test( 'puts attendees past the limit behind the disclosure', () => {
		const section = mountSection( { count: 12 } );

		announce( records( 14 ) );

		const more = section.querySelector( '.wporg-event-attendees__more' );
		expect( more.querySelector( 'summary' ).textContent ).toBe( 'Show all 14 attendees' );
		expect( more.querySelectorAll( '.wporg-event-attendees__item' ) ).toHaveLength( 2 );
		expect( section.querySelectorAll( ':scope > ul .wporg-event-attendees__item' ) ).toHaveLength( 12 );

		announce( records( 12 ) );

		expect( section.querySelector( '.wporg-event-attendees__more' ) ).toBeNull();
	} );

	test( 'treats names as text and drops non-http links', () => {
		const section = mountSection( { count: 1 } );

		announce( [ { name: '<img src=x onerror=alert(1)>', profile: 'javascript:alert(1)', photo: '' } ] );

		expect( section.querySelector( '.wporg-event-attendees__name' ).textContent ).toBe(
			'<img src=x onerror=alert(1)>'
		);
		expect( section.querySelector( '.wporg-event-attendees__name img' ) ).toBeNull();
		expect( section.querySelector( '.wporg-event-attendees__link' ).getAttribute( 'href' ) ).toBe( '' );
	} );

	test( 'leaves another event alone', () => {
		const section = mountSection( { count: 4, postId: 99 } );

		announce( records( 5 ), 12 );

		expect( section.querySelector( 'h2' ).textContent ).toBe( 'Attendees (4)' );
	} );
} );
