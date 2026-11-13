import type { LogEntry, Sponsorship } from './types';

export type TimelineEntry = LogEntry & {
	/* The event the entry is about, or an empty string for the company itself. */
	event: string;
};

/**
 * Puts a company's sponsorships in the order a vetter needs them: events still to come soonest first, then
 * undated ones, then past ones, latest first.
 *
 * @param sponsorships The sponsorships.
 * @param today        Today, as `Y-m-d`.
 * @return The sponsorships, sorted.
 */
export function sortByEvent( sponsorships: Sponsorship[], today: string ): Sponsorship[] {
	const rank = ( { event }: Sponsorship ): number => {
		if ( ! event.date ) {
			return 1;
		}

		return event.date >= today ? 0 : 2;
	};

	return [ ...sponsorships ].sort( ( a, b ) => {
		if ( rank( a ) !== rank( b ) ) {
			return rank( a ) - rank( b );
		}

		return rank( a ) === 2
			? b.event.date.localeCompare( a.event.date )
			: a.event.date.localeCompare( b.event.date );
	} );
}

/**
 * Merges the notes and history of a company and of its sponsorships into one timeline, newest first.
 *
 * @param log          The company's own entries.
 * @param sponsorships Its sponsorships, each with its entries.
 * @return The entries, each naming the event it's about.
 */
export function buildTimeline( log: LogEntry[], sponsorships: Sponsorship[] ): TimelineEntry[] {
	return [
		...log.map( ( entry ) => ( { ...entry, event: '' } ) ),
		...sponsorships.flatMap( ( { event, log: entries } ) =>
			entries.map( ( entry ) => ( { ...entry, event: event.name } ) )
		),
	].sort( ( a, b ) => Date.parse( b.time ) - Date.parse( a.time ) );
}
