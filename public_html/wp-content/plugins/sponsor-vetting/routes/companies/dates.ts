/**
 * Formats and compares the screen's dates.
 */

import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import type { Event } from './types';

type EventDays = Pick< Event, 'date' | 'end' >;

/**
 * Formats a day (`Y-m-d`) in the site's date format.
 *
 * Read as UTC and printed as UTC, so the day doesn't shift with the browser's or the site's time zone.
 *
 * @param day The day, or an empty string.
 * @return The formatted day, or an empty string.
 */
export function formatDay( day: string ): string {
	return day ? dateI18n( getSettings().formats.date, `${ day }T00:00:00Z`, 'UTC' ) : '';
}

/**
 * Formats a moment in the site's date and time format and time zone.
 *
 * @param time The moment, as an ISO 8601 string.
 * @return The formatted date and time.
 */
export function formatTime( time: string ): string {
	return dateI18n( getSettings().formats.datetime, time );
}

/**
 * Says how far a day (`Y-m-d`) is from today, like "in 3 weeks".
 *
 * @param day   The day, or an empty string.
 * @param today Today, as `Y-m-d`.
 * @return The distance, or an empty string.
 */
export function formatDistance( day: string, today: string ): string {
	if ( ! day ) {
		return '';
	}

	return day === today
		? __( 'Today', 'wordcamporg' )
		: humanTimeDiff( `${ day }T00:00:00Z`, `${ today }T00:00:00Z` );
}

/**
 * Gets today as a day (`Y-m-d`), in UTC like the days events start on.
 *
 * @return Today.
 */
export function getToday(): string {
	return new Date().toISOString().slice( 0, 10 );
}

/**
 * Compares two days (`Y-m-d`) for sorting, with missing days last in either direction.
 *
 * @param a         A day, or an empty string.
 * @param b         Another day, or an empty string.
 * @param direction The sort direction.
 * @return A negative number, zero, or a positive number.
 */
export function compareDays( a: string, b: string, direction: 'asc' | 'desc' ): number {
	if ( ! a || ! b ) {
		return Number( ! a ) - Number( ! b );
	}

	return direction === 'asc' ? a.localeCompare( b ) : b.localeCompare( a );
}

/**
 * Whether an event is over: its last day is before today. An event without a date isn't.
 *
 * @param event     The event.
 * @param event.end Its last day.
 * @param today     Today, as `Y-m-d`.
 * @return Whether it's over.
 */
export function isEventOver( { end }: Pick< Event, 'end' >, today: string ): boolean {
	return end !== '' && end < today;
}

/**
 * Whether an event is under way: it started before today and isn't over.
 *
 * @param event Its days.
 * @param today Today, as `Y-m-d`.
 * @return Whether it's under way.
 */
export function isEventUnderWay( event: EventDays, today: string ): boolean {
	return event.date !== '' && event.date < today && ! isEventOver( event, today );
}

/**
 * Compares the days of two events the way a vetter needs them: events that aren't over yet soonest first, then
 * undated ones, then past ones, latest first.
 *
 * @param a     An event's days.
 * @param b     Another event's days.
 * @param today Today, as `Y-m-d`.
 * @return A negative number, zero, or a positive number.
 */
export function compareEventDays( a: EventDays, b: EventDays, today: string ): number {
	const rank = ( event: EventDays ): number => {
		if ( ! event.date ) {
			return 1;
		}

		return isEventOver( event, today ) ? 2 : 0;
	};

	if ( rank( a ) !== rank( b ) ) {
		return rank( a ) - rank( b );
	}

	return rank( a ) === 2 ? b.date.localeCompare( a.date ) : a.date.localeCompare( b.date );
}
