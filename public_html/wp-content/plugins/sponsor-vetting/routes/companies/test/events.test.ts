import { describe, expect, it } from 'vitest';
import { getEventsHint } from '../events';
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
