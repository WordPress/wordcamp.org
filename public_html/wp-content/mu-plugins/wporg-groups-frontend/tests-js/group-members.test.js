/* eslint-disable id-length -- Fetch Response fixtures use the native `ok` property. */

let mockContext;
let mockStoreConfig;

jest.mock(
	'@wordpress/interactivity',
	() => ( {
		store: jest.fn( ( namespace, config ) => {
			mockStoreConfig = config;
			return config;
		} ),
		getContext: jest.fn( () => mockContext ),
	} ),
	{ virtual: true }
);

function loadStore() {
	jest.resetModules();
	require( '../src/blocks/group-members/view' );
	return mockStoreConfig;
}

function createContext() {
	return {
		currentRole: 'subscriber',
		errorLabel: 'Your role could not be changed. Please try again.',
		isError: false,
		message: '',
		restNonce: 'rest-nonce',
		roleApi: '/wp-json/wporg-groups/v1/members/me/role',
		saving: false,
	};
}

function clickOn( role ) {
	return { currentTarget: { dataset: { role } } };
}

describe( 'group members self-serve role switcher', () => {
	beforeEach( () => {
		mockContext = createContext();
		mockStoreConfig = null;
		global.fetch = jest.fn();

		delete window.location;
		window.location = { reload: jest.fn() };
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	test( 'posts the chosen role and reloads on success', async () => {
		global.fetch.mockResolvedValue( {
			ok: true,
			json: async () => ( { role: 'editor', roleLabel: 'Organizer' } ),
		} );
		const { actions } = loadStore();

		await actions.switchRole( clickOn( 'editor' ) );

		expect( global.fetch ).toHaveBeenCalledWith( mockContext.roleApi, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': 'rest-nonce',
			},
			body: JSON.stringify( { role: 'editor' } ),
		} );
		expect( mockContext.currentRole ).toBe( 'editor' );
		expect( window.location.reload ).toHaveBeenCalled();
	} );

	test( 'ignores a click on the role the member already has', async () => {
		const { actions } = loadStore();

		await actions.switchRole( clickOn( 'subscriber' ) );

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( window.location.reload ).not.toHaveBeenCalled();
	} );

	test( 'ignores a second click while a change is in flight', async () => {
		mockContext.saving = true;
		const { actions } = loadStore();

		await actions.switchRole( clickOn( 'editor' ) );

		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	/*
	 * The server-side guard members are most likely to hit: the last
	 * organizer trying to demote themselves. Its message has to survive to
	 * the status line, or the click just looks like it did nothing.
	 */
	test( 'surfaces the server error message and re-enables the buttons', async () => {
		global.fetch.mockResolvedValue( {
			ok: false,
			json: async () => ( {
				code: 'cannot_remove_last_organizer',
				message: 'A group must have at least one organizer. Promote someone else first.',
			} ),
		} );
		const { actions } = loadStore();

		mockContext.currentRole = 'editor';
		await actions.switchRole( clickOn( 'subscriber' ) );

		expect( mockContext.message ).toBe(
			'A group must have at least one organizer. Promote someone else first.'
		);
		expect( mockContext.isError ).toBe( true );
		expect( mockContext.saving ).toBe( false );
		expect( mockContext.currentRole ).toBe( 'editor' );
		expect( window.location.reload ).not.toHaveBeenCalled();
	} );

	test( 'falls back to the generic label when the request fails outright', async () => {
		global.fetch.mockRejectedValue( new Error() );
		const { actions } = loadStore();

		await actions.switchRole( clickOn( 'author' ) );

		expect( mockContext.message ).toBe( mockContext.errorLabel );
		expect( mockContext.isError ).toBe( true );
		expect( mockContext.saving ).toBe( false );
	} );
} );
