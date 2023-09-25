/**
 * Display a timestamp in the user's timezone and locale format.
 *
 * @param {number} timestamp
 *
 * @return {string}
 */
export function getEventDateTime( timestamp ) {
	const eventDate = new Date( timestamp * 1000 );

	const localeDate = eventDate.toLocaleDateString( [], {
		weekday: 'long',
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	} );

	const localeTime = eventDate.toLocaleString( [], {
		timeZoneName: 'short',
		hour: 'numeric',
		minute: '2-digit',
	} );

	return `${ localeDate } ${ localeTime }`;
}
