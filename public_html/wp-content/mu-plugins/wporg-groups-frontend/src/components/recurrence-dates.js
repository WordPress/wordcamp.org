/**
 * Recurrence fields derived from the event date. Kept apart from the
 * controls so they can be unit tested without the component library.
 *
 * @package WordCamp\Groups\Frontend
 */

const ORDER_VALUES = [ 'first', 'second', 'third', 'fourth' ];

/**
 * Returns recurrence defaults derived from the event date.
 *
 * @param {string} date Event date in YYYY-MM-DD format.
 * @return {Object} Date-derived recurrence fields.
 */
export function defaultsForDate( date ) {
	const parsed = date ? new Date( `${ date }T12:00:00` ) : null;
	const valid = parsed && ! Number.isNaN( parsed.getTime() );
	const weekday = valid ? [ 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ][ parsed.getDay() ] : 'MO';
	const day = valid ? parsed.getDate() : 1;
	const orderIndex = Math.min( 3, Math.max( 0, Math.ceil( day / 7 ) - 1 ) );

	return {
		weekdays: [ weekday ],
		monthly_day: day,
		monthly_order: day > 28 ? 'last' : ORDER_VALUES[ orderIndex ],
		monthly_weekday: weekday,
	};
}

/**
 * Moves the date-derived recurrence fields along with the event date.
 *
 * The day of the month always follows the date, since the form has no other
 * way to set it. The weekly day and the monthly weekday pattern follow it
 * only while they still hold what the old date gave them, so a pattern the
 * organizer picked themselves is kept (#2227).
 *
 * @param {Object|null} value        Recurrence value.
 * @param {string}      previousDate Event date before the change.
 * @param {string}      date         Event date after the change.
 * @return {Object|null} Updated recurrence value.
 */
export function followEventDate( value, previousDate, date ) {
	if ( ! value || ! value.available || value.locked || previousDate === date ) {
		return value;
	}

	const before = defaultsForDate( previousDate );
	const after = defaultsForDate( date );
	const next = { ...value, monthly_day: after.monthly_day };

	if ( value.weekdays.length === 1 && value.weekdays[ 0 ] === before.weekdays[ 0 ] ) {
		next.weekdays = after.weekdays;
	}

	if ( value.monthly_order === before.monthly_order && value.monthly_weekday === before.monthly_weekday ) {
		next.monthly_order = after.monthly_order;
		next.monthly_weekday = after.monthly_weekday;
	}

	return next;
}
