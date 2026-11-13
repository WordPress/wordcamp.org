import { afterEach, describe, expect, it } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';
import { compareDays, formatDay } from '../dates';

describe( 'formatDay', () => {
	const settings = getSettings();

	afterEach( () => setSettings( settings ) );

	it.each( [ [ 'Pacific/Pago_Pago' ], [ 'Pacific/Kiritimati' ] ] )(
		'prints the day it was given on a site in %s',
		( timezone ) => {
			setSettings( { ...settings, timezone: { ...settings.timezone, string: timezone } } );

			expect( formatDay( '2026-11-23' ) ).toBe( 'November 23, 2026' );
		}
	);

	it( 'prints nothing for no day', () => {
		expect( formatDay( '' ) ).toBe( '' );
	} );
} );

describe( 'compareDays', () => {
	it.each( [
		[ 'asc', [ '2026-11-23', '2027-01-15', '' ] ],
		[ 'desc', [ '2027-01-15', '2026-11-23', '' ] ],
	] as const )( 'sorts %s with missing days last', ( direction, expected ) => {
		const days = [ '', '2027-01-15', '2026-11-23' ];

		expect( days.sort( ( a, b ) => compareDays( a, b, direction ) ) ).toEqual( expected );
	} );
} );
