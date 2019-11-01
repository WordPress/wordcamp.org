/**
 * Internal dependencies
 */
import { getCurrentSessions } from '../';

/**
 * Mock data from API
 */
import sessions from './__mock__/sessions';
import tracks from './__mock__/session_track';

// Note: Mocked session times are in UTC, on Nov 1st & 2nd, 2019.

describe( 'getCurrentSessions', () => {
	beforeAll( () => {
		window.WordCampBlocks = {};
		window.WordCampBlocks[ 'live-schedule' ] = {
			nowOverride: false,
		};
	} );

	test( 'should return no sessions running at midnight, Jan 1st 2020', () => {
		const time = Date.parse( '202-01-01T00:00:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		expect( results ).toHaveLength( 0 );
	} );

	test( 'should return 6 sessions running at 10:10am', () => {
		const time = Date.parse( '2019-11-01T10:10:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `now` data.
		expect( results.filter( ( { now } ) => !! now ) ).toHaveLength( 6 );
	} );

	test( 'should return 6 sessions up next at 10:10am', () => {
		const time = Date.parse( '2019-11-01T10:10:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `next` data.
		expect( results.filter( ( { next } ) => !! next ) ).toHaveLength( 6 );
	} );

	test( 'should return "Afternoon Break" as 6 sessions up next at 3:10pm', () => {
		const time = Date.parse( '2019-11-02T15:10:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `next` data.
		expect( results.filter( ( { next } ) => !! next ) ).toHaveLength( 6 );
	} );

	test( 'should return just "Afternoon Break" as 1 session running at 3:30pm', () => {
		const time = Date.parse( '2019-11-02T15:30:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `now` data.
		expect( results.filter( ( { now } ) => !! now ) ).toHaveLength( 1 );
	} );

	// @todo Should return State of The Word, but doesn't.
	/* eslint-disable jest/no-disabled-tests */
	test.skip( 'should return "State of The Word" as 1 session running at 4:10pm', () => {
		const time = Date.parse( '2019-11-02T16:10:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `now` data.
		expect( results.filter( ( { now } ) => !! now ) ).toHaveLength( 1 );
	} );
} );
