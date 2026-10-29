import { followEventDate } from '../src/components/recurrence-dates';

// 2099-01-29 is a Thursday, 2099-01-24 a Saturday, 2099-01-14 a Wednesday.
const recurrence = {
	available: true,
	locked: false,
	frequency: 'monthly',
	interval: 1,
	weekdays: [ 'TH' ],
	monthly_mode: 'day',
	monthly_day: 29,
	monthly_order: 'last',
	monthly_weekday: 'TH',
	end_type: 'never',
	until: '',
	count: 12,
};

describe( 'followEventDate', () => {
	it( 'moves the day of the month with the date', () => {
		expect( followEventDate( recurrence, '2099-01-29', '2099-01-24' ).monthly_day ).toBe( 24 );
	} );

	it( 'moves the weekly day and weekday pattern while they still hold the old defaults', () => {
		const next = followEventDate( recurrence, '2099-01-29', '2099-01-14' );

		expect( next.weekdays ).toEqual( [ 'WE' ] );
		expect( next.monthly_order ).toBe( 'second' );
		expect( next.monthly_weekday ).toBe( 'WE' );
	} );

	it( 'keeps a weekly day or weekday pattern the organizer picked', () => {
		const picked = { ...recurrence, weekdays: [ 'MO', 'TH' ], monthly_order: 'first', monthly_weekday: 'TU' };
		const next = followEventDate( picked, '2099-01-29', '2099-01-14' );

		expect( next.weekdays ).toEqual( [ 'MO', 'TH' ] );
		expect( next.monthly_order ).toBe( 'first' );
		expect( next.monthly_weekday ).toBe( 'TU' );
		expect( next.monthly_day ).toBe( 14 );
	} );

	it( 'leaves a locked schedule alone', () => {
		const locked = { ...recurrence, locked: true };

		expect( followEventDate( locked, '2099-01-29', '2099-01-24' ) ).toBe( locked );
	} );

	it( 'leaves unavailable recurrence alone', () => {
		expect( followEventDate( null, '2099-01-29', '2099-01-24' ) ).toBeNull();
	} );
} );
