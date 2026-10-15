/**
 * Helpers for the event form's host field.
 *
 * Kept apart from the field so they can be tested without the component
 * library.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * Kept in step with `Event_Hosts\MAX_HOSTS`.
 */
export const MAX_HOSTS = 10;


/**
 * A member from the `/members` endpoint, in the shape the field holds.
 *
 * @param {Object} member REST member.
 * @return {{id: number, name: string, slug: string}} Host.
 */
export function memberToHost( member ) {
	const match = /profiles\.wordpress\.org\/([^/]+)/.exec( member.profile || '' );

	return {
		id: member.id,
		name: member.name,
		slug: match ? match[ 1 ] : '',
	};
}

/**
 * Label every known person, adding the slug where a name is shared.
 *
 * @param {Array} people Hosts and members, possibly overlapping.
 * @return {Map<string, Object>} Label to person.
 */
export function labelPeople( people ) {
	const byId = new Map();
	people.forEach( ( person ) => byId.set( person.id, person ) );

	const nameCounts = new Map();
	byId.forEach( ( person ) => nameCounts.set( person.name, ( nameCounts.get( person.name ) || 0 ) + 1 ) );

	const labels = new Map();
	byId.forEach( ( person ) => {
		const label = nameCounts.get( person.name ) > 1 && person.slug
			? `${ person.name } (@${ person.slug })`
			: person.name;
		labels.set( label, person );
	} );

	return labels;
}
