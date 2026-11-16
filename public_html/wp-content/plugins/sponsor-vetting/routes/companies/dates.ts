import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

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
 * Compares the days two events start on the way a vetter needs them: events still to come soonest first, then
 * undated ones, then past ones, latest first.
 *
 * @param a     A day, or an empty string.
 * @param b     Another day, or an empty string.
 * @param today Today, as `Y-m-d`.
 * @return A negative number, zero, or a positive number.
 */
export function compareEventDays( a: string, b: string, today: string ): number {
	const rank = ( day: string ): number => {
		if ( ! day ) {
			return 1;
		}

		return day >= today ? 0 : 2;
	};

	if ( rank( a ) !== rank( b ) ) {
		return rank( a ) - rank( b );
	}

	return rank( a ) === 2 ? b.localeCompare( a ) : a.localeCompare( b );
}
