/**
 * Tests for what a company's list of events says about them.
 */

import { describe, expect, it } from 'vitest';
import { getEventFlag, getEventsHint, splitEvents } from '../events';
import type { Company, Sponsorship } from '../types';

/**
 * A company's events, one of them pending.
 */
const PENDING: Pick< Sponsorship, 'status' >[] = [ { status: 'svs-pending' } ];

/**
 * The flags of a company whose vetting has lapsed.
 */
const LAPSED: Company[ 'flags' ] = [ { type: 'lapsed', text: 'Vetting has lapsed' } ];

describe( 'getEventsHint', () => {
	it.each( [
		[
			'a company still being vetted',
			'sv-compliance-req',
			[],
			PENDING,
			/^Pending events are approved automatically/,
		],
		[ 'a vetted company', 'sv-compliant', [], PENDING, /^New events are approved automatically/ ],
		[
			'a vetted company whose vetting lapsed',
			'sv-compliant',
			LAPSED,
			PENDING,
			/^The last vetting is too old/,
		],
		[ 'a company with nothing pending', 'sv-in-progress', [], [], /^$/ ],
	] as [ string, Company[ 'status' ], Company[ 'flags' ], typeof PENDING, RegExp ][] )(
		'says how events get approved for %s',
		( name, status, flags, events, expected ) => {
			expect( getEventsHint( { status, flags }, events ) ).toMatch( expected );
		}
	);
} );

describe( 'getEventFlag', () => {
	it.each( [
		[ 'pending, a week away', 'svs-pending', '2026-11-24', '2026-11-24', 'Event in 7 days' ],
		[ 'pending, tomorrow', 'svs-pending', '2026-11-18', '2026-11-18', 'Event in 1 day' ],
		[ 'pending, today', 'svs-pending', '2026-11-17', '2026-11-17', 'Event today' ],
		[ 'pending, on its last day', 'svs-pending', '2026-11-15', '2026-11-17', 'Event under way' ],
		[ 'pending, eight days away', 'svs-pending', '2026-11-25', '2026-11-25', undefined ],
		[ 'pending, yesterday', 'svs-pending', '2026-11-16', '2026-11-16', undefined ],
		[ 'pending, undated', 'svs-pending', '', '', undefined ],
		[ 'approved, tomorrow', 'svs-approved', '2026-11-18', '2026-11-18', undefined ],
	] as [ string, Sponsorship[ 'status' ], string, string, string | undefined ][] )(
		'flags a sponsor that is %s only while it is urgent',
		( name, status, date, end, expected ) => {
			expect( getEventFlag( { status, event: { name: 'Camp', date, end } }, '2026-11-17' )?.text ).toBe(
				expected
			);
		}
	);
} );

describe( 'splitEvents', () => {
	it( 'folds away the events that are over, but not the one the company was opened from', () => {
		const events = [
			{ id: 1, event: { name: 'Today', date: '2026-11-17', end: '2026-11-17' } },
			{ id: 2, event: { name: 'Undated', date: '', end: '' } },
			{ id: 3, event: { name: 'Yesterday', date: '2026-11-16', end: '2026-11-16' } },
			{ id: 4, event: { name: 'Last year', date: '2025-11-16', end: '2025-11-16' } },
			{ id: 5, event: { name: 'On its last day', date: '2026-11-15', end: '2026-11-17' } },
		];
		const { shown, past } = splitEvents( events, '2026-11-17', 4 );

		expect( shown.map( ( { id } ) => id ) ).toEqual( [ 1, 2, 4, 5 ] );
		expect( past.map( ( { id } ) => id ) ).toEqual( [ 3 ] );
	} );
} );
