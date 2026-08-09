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
	require( '../src/blocks/group-membership/view' );
	return mockStoreConfig;
}

function createContext() {
	return {
		isMember: true,
		notificationOptIn: false,
		preferenceApi: '/wp-json/wporg-groups/v1/members/notification-preference',
		preferenceErrorLabel: 'Your email preference could not be saved.',
		preferenceMessage: '',
		preferenceNoticeError: false,
		preferenceNoticeSuccess: false,
		preferenceSavedLabel: 'Your email preference has been saved.',
		preferenceSaving: false,
		restNonce: 'rest-nonce',
	};
}

describe( 'group membership notification preference', () => {
	beforeEach( () => {
		mockContext = createContext();
		mockStoreConfig = null;
		global.fetch = jest.fn();
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	test( 'saves an opted-in member notification preference', async () => {
		global.fetch.mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true, optIn: true } ),
		} );
		const { actions } = loadStore();

		await actions.updateNotificationPreference( {
			target: { checked: true },
		} );

		expect( global.fetch ).toHaveBeenCalledWith( mockContext.preferenceApi, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': 'rest-nonce',
			},
			body: JSON.stringify( { opt_in: true } ),
		} );
		expect( mockContext.notificationOptIn ).toBe( true );
		expect( mockContext.preferenceMessage ).toBe( mockContext.preferenceSavedLabel );
		expect( mockContext.preferenceNoticeSuccess ).toBe( true );
		expect( mockContext.preferenceNoticeError ).toBe( false );
		expect( mockContext.preferenceSaving ).toBe( false );
	} );

	test( 'rolls back the notification preference when saving fails', async () => {
		mockContext.notificationOptIn = true;
		global.fetch.mockResolvedValue( {
			ok: false,
			json: async () => ( { success: false } ),
		} );
		const { actions } = loadStore();

		await actions.updateNotificationPreference( {
			target: { checked: false },
		} );

		expect( global.fetch ).toHaveBeenCalledWith(
			mockContext.preferenceApi,
			expect.objectContaining( {
				body: JSON.stringify( { opt_in: false } ),
			} )
		);
		expect( mockContext.notificationOptIn ).toBe( true );
		expect( mockContext.preferenceMessage ).toBe( mockContext.preferenceErrorLabel );
		expect( mockContext.preferenceNoticeSuccess ).toBe( false );
		expect( mockContext.preferenceNoticeError ).toBe( true );
		expect( mockContext.preferenceSaving ).toBe( false );
	} );

	test( 'ignores preference changes while a save is pending', async () => {
		let resolveResponse;
		global.fetch.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					resolveResponse = resolve;
				} )
		);
		const { actions } = loadStore();

		const firstSave = actions.updateNotificationPreference( {
			target: { checked: true },
		} );

		expect( mockContext.notificationOptIn ).toBe( true );
		expect( mockContext.preferenceSaving ).toBe( true );

		const secondSave = actions.updateNotificationPreference( {
			target: { checked: false },
		} );
		await secondSave;
		await Promise.resolve();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( mockContext.notificationOptIn ).toBe( true );

		resolveResponse( {
			ok: true,
			json: async () => ( { success: true, optIn: true } ),
		} );
		await firstSave;

		expect( mockContext.notificationOptIn ).toBe( true );
		expect( mockContext.preferenceNoticeSuccess ).toBe( true );
		expect( mockContext.preferenceNoticeError ).toBe( false );
		expect( mockContext.preferenceSaving ).toBe( false );
	} );
} );
