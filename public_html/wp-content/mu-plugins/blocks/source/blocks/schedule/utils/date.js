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
