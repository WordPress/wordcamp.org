import { checkInPath, filterAttendees, withCheckIn } from '../src/blocks/event-manage/check-in-helpers';

const list = {
	attendees: [
		{ commentId: 1, name: 'Ada Lovelace', login: 'ada', checkedIn: false },
		{ commentId: 2, name: 'Grace Hopper', login: 'grace', checkedIn: true },
	],
	checkedInCount: 1,
	isOpen: true,
};

describe( 'check-in helpers', () => {
	it( 'builds the route path, with the date when there is one', () => {
		expect( checkInPath( 5 ) ).toBe( '/wporg-groups/v1/event/5/check-in' );
		expect( checkInPath( 5, '/9' ) ).toBe( '/wporg-groups/v1/event/5/check-in/9' );
		expect( checkInPath( 5, '', '2026-10-01 18:00' ) ).toBe(
			'/wporg-groups/v1/event/5/check-in?recurrence_id=2026-10-01%2018%3A00'
		);
		expect( checkInPath( 5, '', '', 'walk-in' ) ).toBe( '/wporg-groups/v1/event/5/walk-in' );
	} );

	it( 'checks one attendee in and keeps the count in step', () => {
		const updated = withCheckIn( list, 1, true );

		expect( updated.attendees[ 0 ].checkedIn ).toBe( true );
		expect( updated.checkedInCount ).toBe( 2 );
		expect( list.attendees[ 0 ].checkedIn ).toBe( false );
	} );

	it( 'rolls back by applying the opposite state', () => {
		const rolledBack = withCheckIn( withCheckIn( list, 1, true ), 1, false );

		expect( rolledBack.attendees ).toEqual( list.attendees );
		expect( rolledBack.checkedInCount ).toBe( 1 );
	} );

	it( 'leaves the count alone when nothing changes', () => {
		expect( withCheckIn( list, 2, true ).checkedInCount ).toBe( 1 );
		expect( withCheckIn( list, 99, true ).checkedInCount ).toBe( 1 );
	} );

	it( 'filters by name or username, ignoring case', () => {
		expect( filterAttendees( list.attendees, 'HOP' ).map( ( row ) => row.commentId ) ).toEqual( [ 2 ] );
		expect( filterAttendees( list.attendees, 'ada' ).map( ( row ) => row.commentId ) ).toEqual( [ 1 ] );
		expect( filterAttendees( list.attendees, '  ' ) ).toBe( list.attendees );
	} );
} );
