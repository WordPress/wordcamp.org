/* global describe, test, expect */

/**
 * External dependencies
 */
import { shallow, mount } from 'enzyme';

/**
 * Internal dependencies
 */
import { ScheduleGrid } from '../schedule-grid';
import { Sessions } from '../sessions';
import rawSessions from './sessions.json';

// todo take a regular schedule like 2018.seattle, and then adjust it to match mark's mockup, so that all use cases are covered
// set that up in 2018.seattle.wordcamp.test, and then export it to json file
	// er, no, leave 2018.seattle like it is, instead replace 2016.misc data w/ the mockup data
// make sure it's clean so it can be committed
// maybe add to docker db but that's annoying


// setup - run data through derive*() from schedulegrid in order to convert to Date objects etc?
// or maybe better to make this import schedulegrid as a whole, and test that

// todo lint

describe( 'ScheduleGrid integration tests', () => {
	// The Date objects were converted to strings in the JSON file, so convert them back.
	const defaultSessions = rawSessions.map( session => {
		session.derived.startTime = new Date( session.derived.startTime );

		return session;
	} );

	const defaultProps = {
		attributes: {},
		entities: {
			wcb_session: defaultSessions,

			// todo ? or not using them anyway?
			wcb_track: [],
			wcb_session_category: []
		}

	};

	// need to test ScheduleGrid so that rest api data is processed to get derived date/times etc
	// also need mount so it's integration tests instead of unit
	const defaultGrid       = mount( <ScheduleGrid { ...defaultProps } /> );
	const defaultGridMarkup = defaultGrid.html();

	//console.log( defaultGrid.debug( {verbose:true} ) );
	//console.log( defaultGridMarkup );


	test.skip( 'track rows right todo', () => {
		const wrapper = shallow( <Sessions session={ defaultSessions[0] } /> );


		console.log( wrapper.debug( {verbose:true} ) );

		//expect( wrapper
	} );


	test.skip( 'single track session has correct track assigned', () => {




		// todo need to pass othere params, entitiy attr, etc

		//expect( 5 ).toBe( 5 );
		//console.log( defaultGrid.debug( {verbose:true} ) );
		console.log( defaultGrid.debug(  ) );
		//console.log( defaultGridMarkup );
		//console.log( wrapper.html() );
		//console.log( wrapper.find( '.wordcamp-schedule-time-slot-header' ) );

		//console.log( 'hi!',  );

		//const singleTrackSession = defaultGrid.find( '[data-wordcamp-schedule-session-id="1783"]' );

		console.log( defaultGrid.at( 0 ) );
		//console.log( defaultGrid.find( '.wordcamp-schedule' ) );
		//console.log( defaultGrid.find( '.wordcamp-schedule-session-type-session' ) );
		//console.log( defaultGrid.find( 'Sessions' ) );
		//console.log( defaultGrid.find( { id: 1783 } ) );    // this would probably need to be
		//console.log( defaultGrid.find('[data-wordcamp-schedule-session-id="1783"]'), defaultGridMarkup );
		//.html();
		//.replace( /\s/, ' ' );
			// find by id instead of position

		//expect( singleTrackSession ).toContain( 'grid-area: time-1030 / wordcamp-schedule-track-16 / time-1120 / wordcamp-schedule-track-16' );
		// also look at the data-...-tracks attribute
		//expect( defaultGridMarkup.find( '.wordcamp-schedule-time-slot-header' ).toContain( 'grid-row: time-1300 / time-1350' ) );


		//expect( wrapper.html().find( '.wordcamp-schedule-session' ).toContain( 'grid-column: wordcamp-schedule-track-38; grid-row: time-1300 / time-1350' ) );
			// fix this once the above one is done


		// todo test lots of different stuff here, have comments to describe what's being tested
			// document that using single test b/c have a single set of markup, no inputs are being changed, and it'd be slow to full-render the same thing in 20 different tests
			// or can maybe save the raw markup as a const in the describe(), and then have lots of test functions that reference it, but test specific things,
			// that does seem nicer

		// todo why is this so freaking slow? i expect mount() to be slower than shallow(), but it shouldn't take 7 seconds
		// iirc shallow() was taking that long too in some cases, so maybe the problem is w/ something else?
	} );

	// todo need to test row values in UTC and non-UTC
		// todo maybe use dataprovider if something like that exists, to avoid duplicating
		// need to set timezone during startup?

	test.skip( 'a session ends at a grid row where no sessions start - that row should still be generated, but shouldn\'t have time printed', () => {} );
	test.skip( 'spanning contiguous tracks supported', () => {} );
	test.skip( 'spanning non-contiguous tracks not supported', () => {} );
	test.skip( 'no tracks assigned, should use implicit track', () => {} );
	test.skip( 'all sessions in implicit grid', () => {} );

	// todo should probably setup unit tests for all these, since testing manually will be tedious
	// use https://codepen.io/mrwweb/pen/ZaONLW as basis since that already has all the test cases documented
	// can add more cases if discover any
	// if run into problems, push to branch and ask kelly for help
	// test spanning 1 and 2 tracks
	// test spanning 1 track in a 2 track event, and in a 3 track event
	// test spanning 2 tracks in a 2 track event, and a 3 track event
	// test spanning non-contiguous tracks - can't support it, but make sure that it fails gracefully


	/*
	Edge-cases currently supported:
		Sessions spanning any length of time. (For ease of reading, this demo only supports 30-minute increments, but this could easily be adapted for the 5-minute increments allowed by WordCamp.org sites.)
		Gaps between sessions during sessions without similar gaps
		Sessions spanning multiple contiguous tracks

	Edge-cases not supported:
		Sessions spanning multiple, non-contiguous tracks (though future improvements to CSS Grid may allow this)
		Overlapping session times within the same track
	 */

	//test( 'should render a heading tag of level 1.', () => {
	//	const component = renderer.create( <ItemTitle title="Example Title" headingLevel={ 1 } /> );
	//	expect( component.toJSON() ).toMatchSnapshot();
	//} );
	//
	//test( 'should render a heading tag with a custom class.', () => {
	//	const component = renderer.create( <ItemTitle title="Example Title" className="my-test-heading" /> );
	//	expect( component.toJSON() ).toMatchSnapshot();
	//} );
	//
	//test( 'should render a heading tag with a set alignment.', () => {
	//	const component = renderer.create( <ItemTitle title="Example Title" align="right" /> );
	//	expect( component.toJSON() ).toMatchSnapshot();
	//} );
	//
	//test( 'should render a heading tag with a link.', () => {
	//	const component = renderer.create( <ItemTitle title="Example Title" link="https://wordpress.com" /> );
	//	expect( component.toJSON() ).toMatchSnapshot();
	//} );
	//
	//test( 'should render a heading tag with a link, custom class name, heading level, and alignment.', () => {
	//	const component = renderer.create(
	//		<ItemTitle
	//			title="Example Title"
	//			headingLevel={ 1 }
	//			className="my-test-heading"
	//			align="right"
	//			link="https://wordpress.com"
	//		/>
	//	);
	//	expect( component.toJSON() ).toMatchSnapshot();
	//} );
} );
