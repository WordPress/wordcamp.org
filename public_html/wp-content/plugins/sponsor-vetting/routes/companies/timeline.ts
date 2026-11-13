import { compareEventDays } from './dates';
import type { Event, LogEntry } from './types';

export type TimelineEntry = LogEntry & {
	/* The event the entry is about, or an empty string for the company itself. */
	event: string;
};

/**
 * Puts a company's sponsorships in the order a vetter needs them: by event, as compareEventDays() orders them.
 *
 * @param sponsorships The sponsorships.
 * @param today        Today, as `Y-m-d`.
 * @return The sponsorships, sorted.
 */
export function sortByEvent< Item extends { event: Event } >( sponsorships: Item[], today: string ): Item[] {
	return [ ...sponsorships ].sort( ( a, b ) => compareEventDays( a.event.date, b.event.date, today ) );
}

/**
 * Merges the notes and history of a company and of its sponsorships into one timeline, newest first.
 *
 * @param log          The company's own entries.
 * @param sponsorships Its sponsorships, each with its entries.
 * @return The entries, each naming the event it's about.
 */
export function buildTimeline(
	log: LogEntry[],
	sponsorships: { event: Event; log: LogEntry[] }[]
): TimelineEntry[] {
	return [
		...log.map( ( entry ) => ( { ...entry, event: '' } ) ),
		...sponsorships.flatMap( ( { event, log: entries } ) =>
			entries.map( ( entry ) => ( { ...entry, event: event.name } ) )
		),
	].sort( ( a, b ) => Date.parse( b.time ) - Date.parse( a.time ) );
}
