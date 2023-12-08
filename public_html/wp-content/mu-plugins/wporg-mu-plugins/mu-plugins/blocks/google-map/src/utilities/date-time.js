/**
 * Display a timestamp in the user's timezone and locale format.
 *
 * Note: The start time and day of the week are important pieces of information to include, since that helps
 * attendees know at a glance if it's something they can attend. Otherwise they have to click to open it. The
 * timezone is also important to make it clear that we're showing the user's timezone, not the venue's.
 *
 * @see https://make.wordpress.org/community/2017/03/23/showing-upcoming-local-events-in-wp-admin/#comment-23297
 * @see https://make.wordpress.org/community/2017/03/23/showing-upcoming-local-events-in-wp-admin/#comment-23307
 *
 * @param {number} timestamp
 *
 * @return {string} The formatted date and time.
 */
export function getEventDateTime( timestamp ) {
	const eventDate = new Date( timestamp * 1000 );

	const localeDate = eventDate.toLocaleDateString( [], {
		weekday: 'long',
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );

	const localeTime = eventDate.toLocaleString( [], {
		timeZoneName: 'short',
		hour: 'numeric',
		minute: '2-digit',
	} );

	return (
		<>
			<span className="wporg-google-map__date">{ localeDate }</span>
			<span className="wporg-google-map__date-time-separator"></span>
			<span className="wporg-google-map__time">{ localeTime }</span>
		</>
	);
}
