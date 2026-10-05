/**
 * Pure helpers for the check-in modal, kept apart from the component so they
 * can be unit tested without rendering it.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * REST path for an event's check-in routes.
 *
 * @param {number} eventId      Event post ID.
 * @param {string} suffix       Route suffix after `/check-in`, e.g. `/12`, or `''`.
 * @param {string} recurrenceId Recurrence ID of the date, or `''`.
 * @param {string} base         Route base, `check-in` or `walk-in`.
 * @return {string} API path.
 */
export function checkInPath( eventId, suffix = '', recurrenceId = '', base = 'check-in' ) {
	const path = `/wporg-groups/v1/event/${ eventId }/${ base }${ suffix }`;

	return recurrenceId ? `${ path }?recurrence_id=${ encodeURIComponent( recurrenceId ) }` : path;
}

/**
 * The list with one attendee's check-in changed, for the optimistic update
 * and for rolling it back. The count is kept in step with the rows.
 *
 * @param {Object}  list      List as returned by the API.
 * @param {number}  commentId RSVP comment ID.
 * @param {boolean} checkedIn New state.
 * @return {Object} Updated list.
 */
export function withCheckIn( list, commentId, checkedIn ) {
	let delta = 0;

	const attendees = list.attendees.map( ( attendee ) => {
		if ( attendee.commentId !== commentId || attendee.checkedIn === checkedIn ) {
			return attendee;
		}

		delta += checkedIn ? 1 : -1;

		return { ...attendee, checkedIn };
	} );

	return {
		...list,
		attendees,
		checkedInCount: list.checkedInCount + delta,
	};
}

/**
 * Attendees whose name or username contains the filter text.
 *
 * @param {Array}  attendees Attendee rows.
 * @param {string} query     Filter text.
 * @return {Array} Matching rows, in their original order.
 */
export function filterAttendees( attendees, query ) {
	const needle = query.trim().toLowerCase();

	if ( ! needle ) {
		return attendees;
	}

	return attendees.filter(
		( attendee ) =>
			attendee.name.toLowerCase().includes( needle ) || attendee.login.toLowerCase().includes( needle )
	);
}

/**
 * Most walk-ins without an account one date can record. Mirrors
 * `Check_In\MAX_WALK_INS` on the server.
 */
export const MAX_WALK_INS = 9999;

/**
 * The walk-in count after a step, kept within what the server accepts.
 *
 * @param {number} current Current count.
 * @param {number} step    Change, e.g. `1` or `-1`.
 * @return {number} New count.
 */
export function stepWalkInCount( current, step ) {
	return Math.min( MAX_WALK_INS, Math.max( 0, ( Number( current ) || 0 ) + step ) );
}
