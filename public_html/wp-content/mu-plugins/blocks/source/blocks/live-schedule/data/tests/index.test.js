/**
 * Internal dependencies
 */
import { getCurrentSessions } from '../';

/**
 * Mock data from API
 */
import sessions from './__mock__/sessions';
import tracks from './__mock__/session_track';

// Note: Mocked session times are in UTC, on Nov 1st, 2019.

describe( 'getCurrentSessions', () => {
	beforeAll( () => {
		window.WordCampBlocks = {};
		window.WordCampBlocks[ 'live-schedule' ] = {
			nowOverride: false,
			fallbackDuration: 3000,
		};
	} );

	test( 'should return no sessions running at midnight, Jan 1st 2020', () => {
		const time = Date.parse( '2020-01-01T00:00:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		expect( results ).toHaveLength( 0 );
	} );

	test( 'should return no sessions running at midnight, Jan 1st 2019', () => {
		const time = Date.parse( '2019-01-01T00:00:00.000Z' );
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
		const titles = results.map( ( { now } ) => now.slug );
		expect( titles[ 0 ] ).toEqual( 'grow-your-meetup' );
		expect( titles[ 1 ] ).toEqual( 'open-source-open-process-open-web' );
		expect( titles[ 2 ] ).toEqual( 'how-to-perform-a-quality-ux-audit-on-a-budget' );
		expect( titles[ 3 ] ).toEqual( 'contributing-to-core-no-coding-necessary' );
		expect( titles[ 4 ] ).toEqual( 'align-seo-efforts-with-your-target-market-and-todays-search-learn-how-to-perform-keyword-research-and-map-them-to-content' );
		expect( titles[ 5 ] ).toEqual( 'automating-your-qa-with-visual-regression-testing' );
	} );

	test( 'should return 6 sessions up next at 10:10am', () => {
		const time = Date.parse( '2019-11-01T10:10:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `next` data.
		expect( results.filter( ( { next } ) => !! next ) ).toHaveLength( 6 );
		const titles = results.map( ( { next } ) => next.slug );
		expect( titles[ 0 ] ).toEqual( 'lunch' );
		expect( titles[ 1 ] ).toEqual( 'user-personas-as-an-inclusive-design-and-development-tool' );
		expect( titles[ 2 ] ).toEqual( 'a-mom-a-lesbian-and-an-entrepreneur-walk-into-a-wordcamp' );
		expect( titles[ 3 ] ).toEqual( 'using-wordpress-to-do_action' );
		expect( titles[ 4 ] ).toEqual( 'lunch' );
		expect( titles[ 5 ] ).toEqual( 'lunch' );
	} );

	test( 'should return "Afternoon Break" as 6 sessions up next at 2:40pm', () => {
		const time = Date.parse( '2019-11-01T14:40:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `next` data.
		expect( results.filter( ( { next } ) => !! next ) ).toHaveLength( 6 );
		results.forEach( ( { next } ) => {
			expect( next.slug ).toEqual( 'afternoon-break-sponsored-by-cloudways' );
		} );
	} );

	test( 'should return just "Afternoon Break" as 6 sessions running at 2:50pm', () => {
		const time = Date.parse( '2019-11-01T14:50:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `now` data.
		expect( results.filter( ( { now } ) => !! now ) ).toHaveLength( 6 );
		results.forEach( ( { now } ) => {
			expect( now.slug ).toEqual( 'afternoon-break-sponsored-by-cloudways' );
		} );
	} );

	test( 'should return "WordFest" as 6 sessions running at 7:10pm', () => {
		const time = Date.parse( '2019-11-01T19:10:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		// filtering out just the tracks with `now` data.
		expect( results.filter( ( { now } ) => !! now ) ).toHaveLength( 6 );
		results.forEach( ( { now } ) => {
			expect( now.slug ).toEqual( 'wordfest' );
		} );
	} );

	test( 'should return "Session B" coming up next, nothing on now, at 10:45am Nov 2nd', () => {
		const time = Date.parse( '2019-11-02T10:45:00.000Z' );
		window.WordCampBlocks[ 'live-schedule' ].nowOverride = time;
		const results = getCurrentSessions( { sessions, tracks } );
		expect( results ).toHaveLength( 1 );
		expect( results[ 0 ].now ).toBeUndefined();
		expect( results[ 0 ].next.slug ).toEqual( 'session-b' );
	} );
} );
