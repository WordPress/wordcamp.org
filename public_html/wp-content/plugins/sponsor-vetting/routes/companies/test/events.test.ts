import { describe, expect, it } from 'vitest';
import { getEventFlag, getEventsHint } from '../events';
import type { Company, Sponsorship } from '../types';

const PENDING: Pick< Sponsorship, 'status' >[] = [ { status: 'svs-pending' } ];
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
		[ 'pending, a week away', 'svs-pending', '2026-11-24', 'Event in 7 days' ],
		[ 'pending, tomorrow', 'svs-pending', '2026-11-18', 'Event in 1 day' ],
		[ 'pending, today', 'svs-pending', '2026-11-17', 'Event today' ],
		[ 'pending, eight days away', 'svs-pending', '2026-11-25', undefined ],
		[ 'pending, yesterday', 'svs-pending', '2026-11-16', undefined ],
		[ 'pending, undated', 'svs-pending', '', undefined ],
		[ 'approved, tomorrow', 'svs-approved', '2026-11-18', undefined ],
	] as [ string, Sponsorship[ 'status' ], string, string | undefined ][] )(
		'flags a sponsor that is %s only while it is urgent',
		( name, status, date, expected ) => {
			expect( getEventFlag( { status, event: { name: 'Camp', date } }, '2026-11-17' )?.text ).toBe(
				expected
			);
		}
	);
} );
