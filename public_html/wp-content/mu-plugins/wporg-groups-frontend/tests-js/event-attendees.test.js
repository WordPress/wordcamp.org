/**
 * The Attendees block redraws its list when the RSVP block announces a
 * change, so an RSVP made without a reload shows up in it (#2451).
 */

function record( index ) {
	return {
		name: `Attendee ${ index }`,
		photo: `https://example.test/avatar-${ index }.png`,
		profile: `https://example.test/profile-${ index }`,
	};
}

function records( count ) {
	return Array.from( { length: count }, ( unused, index ) => record( index + 1 ) );
}

// The markup `render.php` gives an upcoming event.
function renderSection( { postId = 12, count = 0, open = false } = {} ) {
	const items = records( count )
		.slice( 0, 2 )
		.map(
			( item ) =>
				`<li class="wporg-event-attendees__item"><a class="wporg-event-attendees__link" href="${ item.profile }"><span class="wporg-event-attendees__name">${ item.name }</span></a></li>`
		)
		.join( '' );

	document.body.innerHTML = `
		<section class="wp-block-wporg-event-attendees wporg-event-attendees"
			data-post-id="${ postId }"
			data-heading-format="Attendees (%s)"
			data-more-format="Show all %s attendees"
			data-visible-limit="2"
			${ count ? '' : 'hidden' }>
			<h2 class="wporg-event-attendees__heading">Attendees (${ count })</h2>
			<ul class="wporg-event-attendees__list">${ items }</ul>
			${
				open
					? '<details class="wporg-event-attendees__more" open><summary class="wporg-event-attendees__toggle">Show all</summary><ul class="wporg-event-attendees__list"></ul></details>'
					: ''
			}
		</section>
	`;

	return document.querySelector( 'section' );
}

function announce( detail ) {
	document.dispatchEvent( new CustomEvent( 'wporg-groups-rsvp-changed', { detail } ) );
}

function names( element ) {
	return Array.from( element.querySelectorAll( '.wporg-event-attendees__name' ), ( name ) => name.textContent );
}

describe( 'event attendees list', () => {
	beforeAll( () => {
		require( '../src/blocks/event-attendees/view' );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'adds the new attendee and updates the count', () => {
		const section = renderSection( { count: 1 } );

		announce( { postId: 12, status: 'attending', attending: records( 2 ) } );

		expect( section.querySelector( '.wporg-event-attendees__heading' ).textContent ).toBe( 'Attendees (2)' );
		expect( names( section ) ).toEqual( [ 'Attendee 1', 'Attendee 2' ] );
		expect( section.querySelector( '.wporg-event-attendees__link' ).getAttribute( 'href' ) ).toBe(
			'https://example.test/profile-1'
		);
		expect( section.querySelector( '.wporg-event-attendees__avatar' ).getAttribute( 'src' ) ).toBe(
			'https://example.test/avatar-1.png'
		);
	} );

	test( 'shows the section for the first attendee', () => {
		const section = renderSection( { count: 0 } );

		announce( { postId: 12, status: 'attending', attending: records( 1 ) } );

		expect( section.hidden ).toBe( false );
		expect( names( section ) ).toEqual( [ 'Attendee 1' ] );
	} );

	test( 'removes a cancelled attendee, and hides the section once nobody is left', () => {
		const section = renderSection( { count: 2 } );

		announce( { postId: 12, status: 'not_attending', attending: records( 1 ) } );
		expect( names( section ) ).toEqual( [ 'Attendee 1' ] );
		expect( section.querySelector( '.wporg-event-attendees__heading' ).textContent ).toBe( 'Attendees (1)' );

		announce( { postId: 12, status: 'not_attending', attending: [] } );
		expect( section.hidden ).toBe( true );
	} );

	test( 'puts attendees past the limit behind "Show all", keeping it open if it was', () => {
		const section = renderSection( { count: 2, open: true } );

		announce( { postId: 12, status: 'attending', attending: records( 3 ) } );

		const lists = section.querySelectorAll( '.wporg-event-attendees__list' );
		const details = section.querySelector( '.wporg-event-attendees__more' );

		expect( lists ).toHaveLength( 2 );
		expect( names( lists[ 0 ] ) ).toEqual( [ 'Attendee 1', 'Attendee 2' ] );
		expect( names( details ) ).toEqual( [ 'Attendee 3' ] );
		expect( details.querySelector( 'summary' ).textContent ).toBe( 'Show all 3 attendees' );
		expect( details.open ).toBe( true );
	} );

	test( 'leaves another event’s list alone', () => {
		const section = renderSection( { postId: 34, count: 1 } );

		announce( { postId: 12, status: 'attending', attending: records( 2 ) } );

		expect( names( section ) ).toEqual( [ 'Attendee 1' ] );
	} );

	test( 'leaves the list alone when the change carries no records', () => {
		const section = renderSection( { count: 1 } );

		announce( { postId: 12, status: 'attending' } );

		expect( names( section ) ).toEqual( [ 'Attendee 1' ] );
		expect( section.querySelector( '.wporg-event-attendees__heading' ).textContent ).toBe( 'Attendees (1)' );
	} );

	test( 'treats names as text and drops non-web URLs', () => {
		const section = renderSection( { count: 0 } );

		announce( {
			postId: 12,
			status: 'attending',
			attending: [
				{
					name: '<img src=x onerror=alert(1)>',
					photo: 'javascript:alert(1)',
					profile: 'javascript:alert(1)',
				},
			],
		} );

		expect( names( section ) ).toEqual( [ '<img src=x onerror=alert(1)>' ] );
		expect( section.querySelectorAll( 'img[onerror]' ) ).toHaveLength( 0 );
		expect( section.querySelector( '.wporg-event-attendees__link' ).getAttribute( 'href' ) ).toBe( '' );
		expect( section.querySelector( '.wporg-event-attendees__avatar' ).getAttribute( 'src' ) ).toBe( '' );
	} );
} );
