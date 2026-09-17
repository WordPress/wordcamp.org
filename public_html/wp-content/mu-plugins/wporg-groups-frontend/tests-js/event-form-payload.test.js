import buildEventPayload from '../src/components/event-form/build-payload';

const form = {
	title: 'Meetup',
	date: '2099-03-04',
	time_start: '12:00',
	time_end: '13:00',
	venue_select: '42',
	is_online: false,
	online_event_link: 'https://example.test/join',
	rsvp_questions: [],
};

const recurrence = {
	available: true,
	locked: false,
	frequency: 'weekly',
	interval: 1,
	weekdays: [ 'WE' ],
	monthly_mode: 'day',
	monthly_day: 4,
	monthly_order: 'first',
	monthly_weekday: 'WE',
	end_type: 'never',
	until: '',
	count: 12,
};

function build( overrides = {} ) {
	return buildEventPayload( {
		form,
		description: '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
		featuredImageId: 7,
		recurrence,
		...overrides,
	} );
}

describe( 'buildEventPayload', () => {
	test( 'maps the form fields and parses the venue id', () => {
		const payload = build();

		expect( payload ).toMatchObject( {
			title: 'Meetup',
			description: '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			date: '2099-03-04',
			time_start: '12:00',
			time_end: '13:00',
			venue_id: 42,
			is_online: false,
			featured_image_id: 7,
		} );
	} );

	test( 'sends venue_id 0 when no venue is selected', () => {
		expect( build( { form: { ...form, venue_select: '' } } ).venue_id ).toBe( 0 );
	} );

	test( 'clears the online link unless the event is online', () => {
		expect( build().online_event_link ).toBe( '' );
		expect( build( { form: { ...form, is_online: true } } ).online_event_link ).toBe( 'https://example.test/join' );
	} );

	test( 'sends recurrence while it is unlocked', () => {
		expect( build().recurrence ).toBe( recurrence );
	} );

	test( 'omits recurrence once it is locked', () => {
		expect( build( { recurrence: { ...recurrence, locked: true } } ) ).not.toHaveProperty( 'recurrence' );
	} );

	test( 'omits recurrence when the extension is unavailable', () => {
		expect( build( { recurrence: null } ) ).not.toHaveProperty( 'recurrence' );
	} );

	test( 'drops RSVP questions with a blank label', () => {
		const rsvpQuestions = [
			{ label: 'Dietary needs?', required: false },
			{ label: '   ', required: true },
			{ label: '', required: false },
		];

		expect( build( { form: { ...form, rsvp_questions: rsvpQuestions } } ).rsvp_questions ).toEqual( [ rsvpQuestions[ 0 ] ] );
	} );

	test( 'tolerates a form with no rsvp_questions key', () => {
		const { rsvp_questions: unused, ...withoutQuestions } = form; // eslint-disable-line no-unused-vars

		expect( build( { form: withoutQuestions } ).rsvp_questions ).toEqual( [] );
	} );
} );
