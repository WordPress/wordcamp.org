/**
 * Builds the request body for the event endpoints from the form state.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * @param {Object}      args
 * @param {Object}      args.form            Field values.
 * @param {string}      args.description     Serialized description blocks.
 * @param {number}      args.featuredImageId
 * @param {Object|null} args.recurrence      Normalized recurrence, or null when unavailable.
 * @return {Object} Payload.
 */
export default function buildEventPayload( { form, description, featuredImageId, recurrence } ) {
	return {
		title: form.title,
		description,
		date: form.date,
		time_start: form.time_start,
		time_end: form.time_end,
		venue_id: parseInt( form.venue_select, 10 ) || 0,
		is_online: form.is_online,
		online_event_link: form.is_online ? form.online_event_link : '',
		featured_image_id: featuredImageId,
		// Include recurrence while the schedule is editable, including for drafts.
		// Published schedules are locked, so omit it from later updates.
		...( recurrence && ! recurrence.locked ? { recurrence } : {} ),
		// Blank-labelled rows are just an empty slot the organizer
		// added and never filled in; the server drops them too.
		rsvp_questions: ( form.rsvp_questions || [] ).filter(
			( q ) => q.label.trim() !== ''
		),
	};
}
