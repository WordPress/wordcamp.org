/* eslint-disable id-length -- Fetch Response fixtures use the native `ok` property. */

let mockContext;
let mockElement;
let mockStoreConfig;

jest.mock(
	'@wordpress/interactivity',
	() => ( {
		store: jest.fn( ( namespace, config ) => {
			mockStoreConfig = config;
			return config;
		} ),
		getContext: jest.fn( () => mockContext ),
		getElement: jest.fn( () => ( { ref: mockElement } ) ),
	} ),
	{ virtual: true }
);

function loadStore() {
	jest.resetModules();
	require( '../src/blocks/event-rsvp/view' );
	return mockStoreConfig;
}

function createContext() {
	return {
		apiBase: '/wp-json/gatherpress/v1/event',
		attendingCount: 0,
		currentUserStatus: 'no_status',
		isLoggedIn: true,
		isMember: true,
		isPastEvent: false,
		labels: {
			rsvpError: 'Your RSVP could not be updated. Please try again.',
			rsvpSuccessAttending: 'You are now attending this event.',
			rsvpSuccessNotAttending: 'Your RSVP has been cancelled.',
		},
		modalOpen: false,
		rsvpLoading: false,
		rsvpNotice: '',
	};
}

function renderModal() {
	document.body.innerHTML = `
		<div class="wp-block-wporg-event-rsvp">
			<button class="wporg-event-rsvp__summary" type="button">View attendees</button>
			<div class="wporg-event-rsvp__modal">
				<button class="wporg-event-rsvp__modal-close" type="button">Close</button>
				<button class="wporg-event-rsvp__modal-rsvp-btn" type="button">Attend</button>
				<a class="wporg-event-rsvp__attendee" href="https://example.com/member">Member</a>
			</div>
		</div>
	`;

	return {
		attendee: document.querySelector( '.wporg-event-rsvp__attendee' ),
		close: document.querySelector( '.wporg-event-rsvp__modal-close' ),
		modal: document.querySelector( '.wporg-event-rsvp__modal' ),
		summary: document.querySelector( '.wporg-event-rsvp__summary' ),
	};
}

function renderTwoModals() {
	document.body.innerHTML = `
		<div class="wp-block-wporg-event-rsvp" data-instance="first">
			<button class="wporg-event-rsvp__summary" type="button">
				<span class="wporg-event-rsvp__avatars">First avatars untouched</span>
				View first attendees
			</button>
			<div class="wp-block-button">
				<button class="wp-block-button__link wp-element-button" type="button">RSVP first</button>
			</div>
			<div class="wporg-event-rsvp__modal">
				<button class="wporg-event-rsvp__modal-close" type="button">Close first</button>
				<div class="wporg-event-rsvp__attendee-list">First list untouched</div>
			</div>
		</div>
		<div class="wp-block-wporg-event-rsvp" data-instance="second">
			<button class="wporg-event-rsvp__summary" type="button">
				<span class="wporg-event-rsvp__avatars">Second avatars pending</span>
				View second attendees
			</button>
			<div class="wp-block-button">
				<button class="wp-block-button__link wp-element-button" type="button">RSVP second</button>
			</div>
			<div class="wporg-event-rsvp__modal">
				<button class="wporg-event-rsvp__modal-close" type="button">Close second</button>
				<div class="wporg-event-rsvp__attendee-list">Second list pending</div>
			</div>
		</div>
	`;

	return Array.from( document.querySelectorAll( '.wp-block-wporg-event-rsvp' ) ).map( ( block ) => ( {
		avatars: block.querySelector( '.wporg-event-rsvp__avatars' ),
		close: block.querySelector( '.wporg-event-rsvp__modal-close' ),
		list: block.querySelector( '.wporg-event-rsvp__attendee-list' ),
		rsvp: block.querySelector( '.wp-block-button button' ),
		summary: block.querySelector( '.wporg-event-rsvp__summary' ),
	} ) );
}

describe( 'event RSVP accessibility', () => {
	beforeEach( () => {
		mockContext = createContext();
		mockElement = null;
		mockStoreConfig = null;
		global.fetch = jest.fn();
		document.body.style.overflow = '';
		window.requestAnimationFrame = jest.fn( ( callback ) => {
			callback();
			return 1;
		} );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		document.body.style.overflow = '';
		jest.restoreAllMocks();
	} );

	test( 'moves focus into the modal and restores it to the trigger', () => {
		const elements = renderModal();
		const { actions } = loadStore();

		mockElement = elements.summary;
		elements.summary.focus();
		actions.openModal();

		expect( mockContext.modalOpen ).toBe( true );
		expect( document.activeElement ).toBe( elements.close );

		actions.closeModal();

		expect( mockContext.modalOpen ).toBe( false );
		expect( document.activeElement ).toBe( elements.summary );
	} );

	test( 'keeps focus and scroll state consistent when a second block opens its modal', () => {
		const [ first, second ] = renderTwoModals();
		const firstContext = createContext();
		const secondContext = createContext();
		const { actions } = loadStore();

		document.body.style.overflow = 'clip';
		mockContext = firstContext;
		mockElement = first.summary;
		actions.openModal();

		mockContext = secondContext;
		mockElement = second.summary;
		actions.openModal();

		expect( firstContext.modalOpen ).toBe( false );
		expect( secondContext.modalOpen ).toBe( true );
		expect( document.activeElement ).toBe( second.close );
		expect( document.body.style.overflow ).toBe( 'hidden' );

		mockContext = firstContext;
		actions.closeModal();
		expect( document.activeElement ).toBe( second.close );
		expect( document.body.style.overflow ).toBe( 'hidden' );

		mockContext = secondContext;
		actions.closeModal();
		expect( document.activeElement ).toBe( second.summary );
		expect( document.body.style.overflow ).toBe( 'clip' );
	} );

	test( 'refreshes attendees only in the block whose RSVP changed', async () => {
		const [ first, second ] = renderTwoModals();
		const { actions } = loadStore();

		mockElement = second.rsvp;
		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { nonce: 'nonce' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				status: 200,
				json: async () => ( {
					success: true,
					status: 'attending',
					responses: { attending: { count: 1 } },
				} ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( {
					success: true,
					data: {
						attending: {
							count: 1,
							records: [
								{
									name: 'Second attendee',
									photo: 'https://example.com/second.jpg',
									profile: 'https://example.com/second',
								},
							],
						},
					},
				} ),
			} );

		await actions.handleRsvpButton();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( first.list.textContent ).toBe( 'First list untouched' );
		expect( first.avatars.textContent ).toBe( 'First avatars untouched' );
		expect( second.list.textContent ).toContain( 'Second attendee' );
		expect( second.avatars.querySelector( 'img' ).getAttribute( 'src' ) ).toBe(
			'https://example.com/second.jpg'
		);
	} );

	test( 'traps Tab and Shift+Tab at the modal boundaries', () => {
		const elements = renderModal();
		const { actions } = loadStore();

		mockElement = elements.summary;
		actions.openModal();

		const backwards = new window.KeyboardEvent( 'keydown', {
			key: 'Tab',
			shiftKey: true,
			cancelable: true,
		} );
		actions.handleModalKeydown( backwards );

		expect( backwards.defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( elements.attendee );

		const forwards = new window.KeyboardEvent( 'keydown', {
			key: 'Tab',
			cancelable: true,
		} );
		actions.handleModalKeydown( forwards );

		expect( forwards.defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( elements.close );
	} );

	test( 'restores focus after Escape and backdrop dismissal', () => {
		const elements = renderModal();
		const { actions } = loadStore();

		mockElement = elements.summary;
		elements.summary.focus();
		actions.openModal();
		actions.handleModalKeydown(
			new window.KeyboardEvent( 'keydown', {
				key: 'Escape',
				cancelable: true,
			} )
		);
		expect( document.activeElement ).toBe( elements.summary );

		actions.openModal();
		mockElement = elements.modal;
		actions.handleBackdropClick( { target: elements.modal } );
		expect( document.activeElement ).toBe( elements.summary );
	} );

	test( 'announces a successful RSVP update', async () => {
		const { actions } = loadStore();

		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { nonce: 'nonce' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				status: 200,
				json: async () => ( {
					success: true,
					status: 'attending',
					responses: { attending: { count: 1 } },
				} ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { success: false } ),
			} );

		await actions.handleRsvpButton();

		expect( mockContext.currentUserStatus ).toBe( 'attending' );
		expect( mockContext.rsvpNotice ).toBe( 'You are now attending this event.' );
	} );

	test( 'announces an RSVP failure and rolls back optimistic state', async () => {
		const { actions } = loadStore();

		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { nonce: 'nonce' } ),
			} )
			.mockResolvedValueOnce( {
				ok: false,
				status: 500,
				statusText: 'Server Error',
				json: async () => ( { success: false } ),
			} );

		await actions.handleRsvpButton();

		expect( mockContext.currentUserStatus ).toBe( 'no_status' );
		expect( mockContext.attendingCount ).toBe( 0 );
		expect( mockContext.rsvpNotice ).toBe( 'Your RSVP could not be updated. Please try again.' );
	} );
} );

describe( 'event RSVP custom registration questions', () => {
	beforeEach( () => {
		mockContext = createContext();
		mockContext.hasQuestions = true;
		mockContext.questionsError = '';
		mockContext.rsvpApi = '/wp-json/wporg-groups/v1/event/12/rsvp';
		mockContext.labels.missingAnswers = 'Please answer the required questions.';
		mockElement = null;
		mockStoreConfig = null;
		global.fetch = jest.fn();
		document.body.style.overflow = '';
		window.requestAnimationFrame = jest.fn( ( callback ) => {
			callback();
			return 1;
		} );
	} );

	function mockRsvpSuccess() {
		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { nonce: 'nonce' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				status: 200,
				json: async () => ( {
					success: true,
					status: 'attending',
					responses: { attending: { count: 1 } },
				} ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { success: false } ),
			} );
	}

	function renderModalWithQuestions() {
		document.body.innerHTML = `
			<div class="wp-block-wporg-event-rsvp">
				<div class="wp-block-button">
					<button class="wp-block-button__link wp-element-button" type="button">RSVP</button>
				</div>
				<div class="wporg-event-rsvp__modal">
					<fieldset class="wporg-event-rsvp__questions">
						<input class="wporg-event-rsvp__question-input" data-question-id="diet" required />
						<input class="wporg-event-rsvp__question-input" data-question-id="company" />
					</fieldset>
					<button class="wporg-event-rsvp__modal-rsvp-btn" type="button">Attend</button>
				</div>
			</div>
		`;

		return {
			company: document.querySelector( '[data-question-id="company"]' ),
			diet: document.querySelector( '[data-question-id="diet"]' ),
			rsvp: document.querySelector( '.wp-block-button button' ),
		};
	}

	test( 'opens the modal instead of RSVPing when the event asks questions', async () => {
		const { actions } = loadStore();
		const { rsvp } = renderModalWithQuestions();
		mockElement = rsvp;

		await actions.handleRsvpButton();

		expect( mockContext.modalOpen ).toBe( true );
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	test( 'blocks the RSVP when a required question is unanswered', async () => {
		const { actions } = loadStore();
		const { rsvp } = renderModalWithQuestions();
		mockElement = rsvp;

		await actions.toggleRsvp();

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( mockContext.currentUserStatus ).toBe( 'no_status' );
		expect( mockContext.questionsError ).toBe( 'Please answer the required questions.' );
	} );

	test( 'identifies which required question is unanswered', async () => {
		const { actions } = loadStore();
		const { company, diet, rsvp } = renderModalWithQuestions();
		mockElement = rsvp;

		await actions.toggleRsvp();

		// The generic error is announced via the live region, but the field in
		// error also has to be identifiable. WCAG 2.1 3.3.1.
		expect( diet.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( company.hasAttribute( 'aria-invalid' ) ).toBe( false );
	} );

	test( 'clears the invalid flag once the required question is answered', async () => {
		const { actions } = loadStore();
		const { diet, rsvp } = renderModalWithQuestions();
		mockElement = rsvp;

		await actions.toggleRsvp();
		expect( diet.getAttribute( 'aria-invalid' ) ).toBe( 'true' );

		diet.value = 'Vegetarian';
		await actions.toggleRsvp();

		expect( diet.hasAttribute( 'aria-invalid' ) ).toBe( false );
	} );

	test( 'sends the answers alongside the RSVP', async () => {
		const { actions } = loadStore();
		const { company, diet, rsvp } = renderModalWithQuestions();
		mockElement = rsvp;
		diet.value = 'Vegetarian';
		company.value = 'Automattic';

		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { nonce: 'nonce' } ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				status: 200,
				json: async () => ( {
					success: true,
					status: 'attending',
					responses: { attending: { count: 1 } },
				} ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { success: false } ),
			} );

		await actions.toggleRsvp();

		const [ url, options ] = global.fetch.mock.calls[ 1 ];
		expect( url ).toBe( '/wp-json/wporg-groups/v1/event/12/rsvp' );
		expect( JSON.parse( options.body ) ).toEqual( {
			status: 'attending',
			answers: { company: 'Automattic', diet: 'Vegetarian' },
		} );
		expect( mockContext.currentUserStatus ).toBe( 'attending' );
	} );

	test( 'sends blank optional answers so the server can tell cleared from absent', async () => {
		const { actions } = loadStore();
		const { diet, rsvp } = renderModalWithQuestions();
		mockElement = rsvp;
		diet.value = '  Vegetarian  ';

		mockRsvpSuccess();

		await actions.toggleRsvp();

		expect( JSON.parse( global.fetch.mock.calls[ 1 ][ 1 ].body ).answers ).toEqual( {
			company: '',
			diet: 'Vegetarian',
		} );
	} );

	test( 'saves edited answers without changing attendance', async () => {
		const { actions } = loadStore();
		const { diet, rsvp } = renderModalWithQuestions();
		mockElement = rsvp;
		mockContext.currentUserStatus = 'attending';
		mockContext.attendingCount = 1;
		mockContext.labels.answersSaved = 'Your answers have been saved.';
		diet.value = 'Vegan';

		mockRsvpSuccess();

		await actions.saveAnswers();

		expect( JSON.parse( global.fetch.mock.calls[ 1 ][ 1 ].body ).status ).toBe( 'attending' );
		expect( mockContext.currentUserStatus ).toBe( 'attending' );
		expect( mockContext.attendingCount ).toBe( 1 );
		expect( mockContext.rsvpNotice ).toBe( 'Your answers have been saved.' );
	} );

	test( 'surfaces the server validation message instead of the generic error', async () => {
		const { actions } = loadStore();
		const { diet, rsvp } = renderModalWithQuestions();
		mockElement = rsvp;
		// Passes the client check — "0" is a filled-in field in the browser.
		diet.value = '0';

		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { nonce: 'nonce' } ),
			} )
			.mockResolvedValueOnce( {
				ok: false,
				status: 400,
				statusText: 'Bad Request',
				json: async () => ( {
					code: 'wporg_groups_missing_answers',
					message: 'Please answer: Dietary requirements',
				} ),
			} );

		await actions.toggleRsvp();

		expect( mockContext.rsvpNotice ).toBe( 'Please answer: Dietary requirements' );
		expect( mockContext.questionsError ).toBe( 'Please answer: Dietary requirements' );
		expect( mockContext.currentUserStatus ).toBe( 'no_status' );
	} );

	test( 'keeps the generic wording for failures that are not ours', async () => {
		const { actions } = loadStore();
		const { diet, rsvp } = renderModalWithQuestions();
		mockElement = rsvp;
		diet.value = 'Vegetarian';

		global.fetch
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( { nonce: 'nonce' } ),
			} )
			.mockResolvedValueOnce( {
				ok: false,
				status: 500,
				statusText: 'Internal Server Error',
				json: async () => ( {} ),
			} );

		await actions.toggleRsvp();

		expect( mockContext.rsvpNotice ).toBe( 'Your RSVP could not be updated. Please try again.' );
		expect( mockContext.questionsError ).toBe( '' );
	} );
} );

/**
 * The browser-side half of the RSVP count line.
 *
 * These cases mirror `data_count_labels()` in `tests/test-rsvp-labels.php` —
 * same counts, same expected wording — because the branching exists twice by
 * necessity: PHP renders the line, this re-renders it after an RSVP changes
 * the count without a reload. A change to one set of branches should fail the
 * other suite.
 *
 * The fixture below is what `get_count_formats()` produces for English, and it
 * carries only the six documented keys, so asking for a key PHP doesn't ship
 * renders empty and fails the assertion rather than passing quietly.
 */
describe( 'event RSVP count label', () => {
	const countFormats = {
		countZero: 'Be the first to RSVP',
		countSingular: '%s going',
		countPlural: '%s going',
		countYouFirst: 'First one in',
		countYouAndOneOther: 'You and %s other',
		countYouAndOthers: 'You and %s others',
	};

	beforeEach( () => {
		mockContext = createContext();
		mockContext.labels = countFormats;
		mockElement = null;
		mockStoreConfig = null;
	} );

	test.each( [
		[ 'nobody yet', 0, 'no_status', 'Be the first to RSVP' ],
		[ 'one other person', 1, 'no_status', '1 going' ],
		[ 'several other people', 14, 'no_status', '14 going' ],
		[ 'you, alone', 1, 'attending', 'First one in' ],
		[ 'you and one other', 2, 'attending', 'You and 1 other' ],
		[ 'you and several others', 15, 'attending', 'You and 14 others' ],
		[ 'attending with a zero count', 0, 'attending', 'First one in' ],
	] )( 'describes %s', ( _name, attendingCount, currentUserStatus, expected ) => {
		const { state } = loadStore();
		mockContext.attendingCount = attendingCount;
		mockContext.currentUserStatus = currentUserStatus;

		expect( state.countLabel ).toBe( expected );
	} );

	test( 'follows the count as an RSVP changes it', () => {
		const { state } = loadStore();
		mockContext.attendingCount = 3;
		mockContext.currentUserStatus = 'no_status';

		expect( state.countLabel ).toBe( '3 going' );

		mockContext.attendingCount = 4;
		mockContext.currentUserStatus = 'attending';

		expect( state.countLabel ).toBe( 'You and 3 others' );
	} );

	test( 'only asks for keys the server ships', () => {
		const { state } = loadStore();
		const asked = new Set();
		mockContext.labels = new Proxy( countFormats, {
			get( target, key ) {
				asked.add( key );
				return target[ key ];
			},
		} );

		for ( const count of [ 0, 1, 2, 15 ] ) {
			for ( const status of [ 'no_status', 'attending' ] ) {
				mockContext.attendingCount = count;
				mockContext.currentUserStatus = status;
				expect( state.countLabel ).not.toBe( '' );
			}
		}

		expect( [ ...asked ].sort() ).toEqual( Object.keys( countFormats ).sort() );
	} );
} );
