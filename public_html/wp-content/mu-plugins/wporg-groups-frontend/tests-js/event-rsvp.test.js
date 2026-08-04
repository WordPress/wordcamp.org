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

describe( 'event RSVP accessibility', () => {
	beforeEach( () => {
		mockContext = createContext();
		mockElement = null;
		mockStoreConfig = null;
		global.fetch = jest.fn();
		window.requestAnimationFrame = jest.fn( ( callback ) => {
			callback();
			return 1;
		} );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
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
