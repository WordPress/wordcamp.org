/**
 * Get the timezone to use depending on a `useClientTimezone` property.
 *
 * @param {Object}  props
 * @param {boolean} props.useClientTimezone
 * @return {string}
 */
export function getTimezone( { useClientTimezone = false } = {} ) {
	return useClientTimezone ? Intl.DateTimeFormat().resolvedOptions().timeZone : WordCampBlocks.schedule.timezone;
}

/**
 * Get the timezone set in `derived` on a list of sessions.
 *
 * @param {Array} sessions
 * @return {string}
 */
export function getTimezoneFromSessions( sessions ) {
	return sessions[ 0 ]?.derived?.timezone || WordCampBlocks.schedule.timezone;
}
