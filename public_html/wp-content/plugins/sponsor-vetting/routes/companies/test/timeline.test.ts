/**
 * Tests for a company's events and history.
 */

import { describe, expect, it } from 'vitest';
import { buildTimeline, sortByEvent } from '../timeline';
import type { CompanySponsorship } from '../types';

/**
 * Builds a sponsorship for an event.
 *
 * @param name The event's name.
 * @param date When it starts, as `Y-m-d`, or an empty string.
 * @param log  The times of the sponsorship's own log entries.
 * @param end  Its last day, for an event of more than one.
 * @return The sponsorship.
 */
function sponsorship(
	name: string,
	date: string,
	log: string[] = [],
	end: string = date
): Pick< CompanySponsorship, 'id' | 'title' | 'status' | 'event' | 'approved' | 'log' > {
	return {
		id: name.length,
		title: { raw: name, rendered: name },
		status: 'svs-pending',
		event: { name, date, end },
		approved: '',
		log: log.map( ( time ) => ( { time, html: name } ) ),
	};
}

describe( 'sortByEvent', () => {
	it( 'lists events that are not over soonest first, then undated ones, then past ones latest first', () => {
		const sorted = sortByEvent(
			[
				sponsorship( 'Long past', '2025-03-01' ),
				sponsorship( 'Later', '2027-01-15' ),
				sponsorship( 'Undated', '' ),
				sponsorship( 'Just past', '2026-11-01' ),
				sponsorship( 'Today', '2026-11-13' ),
				sponsorship( 'Under way', '2026-11-12', [], '2026-11-14' ),
			],
			'2026-11-13'
		);

		expect( sorted.map( ( { event } ) => event.name ) ).toEqual( [
			'Under way',
			'Today',
			'Later',
			'Undated',
			'Just past',
			'Long past',
		] );
	} );
} );

describe( 'buildTimeline', () => {
	it( 'merges the entries of a company and its sponsorships, newest first, naming each event', () => {
		const timeline = buildTimeline(
			[
				{ time: '2026-11-10T09:00:00+00:00', html: 'Company, middle' },
				{ time: '2026-11-01T09:00:00+00:00', html: 'Company, oldest' },
			],
			[ sponsorship( 'Camp', '2026-12-01', [ '2026-11-12T09:00:00+00:00', '2026-11-05T09:00:00+00:00' ] ) ]
		);

		expect( timeline.map( ( { event, html, time } ) => [ event, html, time.slice( 0, 10 ) ] ) ).toEqual( [
			[ 'Camp', 'Camp', '2026-11-12' ],
			[ '', 'Company, middle', '2026-11-10' ],
			[ 'Camp', 'Camp', '2026-11-05' ],
			[ '', 'Company, oldest', '2026-11-01' ],
		] );
	} );
} );
