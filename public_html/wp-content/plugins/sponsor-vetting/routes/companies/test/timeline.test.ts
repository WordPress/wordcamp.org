import { describe, expect, it } from 'vitest';
import { buildTimeline, sortByEvent } from '../timeline';
import type { Sponsorship } from '../types';

/**
 * Builds a sponsorship for an event.
 *
 * @param name The event's name.
 * @param date When it starts, as `Y-m-d`, or an empty string.
 * @param log  The times of the sponsorship's own log entries.
 * @return The sponsorship.
 */
function sponsorship( name: string, date: string, log: string[] = [] ): Sponsorship {
	return {
		id: name.length,
		title: { raw: name, rendered: name },
		status: 'svs-pending',
		event: { name, date },
		approved: '',
		log: log.map( ( time ) => ( { time, html: name } ) ),
	};
}

describe( 'sortByEvent', () => {
	it( 'lists events to come soonest first, then undated ones, then past ones latest first', () => {
		const sorted = sortByEvent(
			[
				sponsorship( 'Long past', '2025-03-01' ),
				sponsorship( 'Later', '2027-01-15' ),
				sponsorship( 'Undated', '' ),
				sponsorship( 'Just past', '2026-11-01' ),
				sponsorship( 'Today', '2026-11-13' ),
			],
			'2026-11-13'
		);

		expect( sorted.map( ( { event } ) => event.name ) ).toEqual( [
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
