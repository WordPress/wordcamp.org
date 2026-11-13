import { dateI18n, getSettings } from '@wordpress/date';

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
