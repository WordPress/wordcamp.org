/**
 * Topic list normalization for the event form, kept free of component
 * imports so it can be unit tested on its own.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * Matches `MAX_TOPICS` in inc/event-topics.php, which enforces it on save.
 */
export const MAX_TOPICS = 5;

/**
 * Trim the tokens and drop blanks and case-insensitive repeats, the way the
 * server does, so the field never shows a topic the save would discard.
 *
 * @param {Array<string|Object>} tokens Tokens from FormTokenField.
 * @return {string[]} Topic names.
 */
export default function normalizeTopics( tokens ) {
	const seen = new Set();

	return ( tokens || [] )
		.map( ( token ) => String( typeof token === 'object' ? token.value : token ).trim() )
		.filter( ( name ) => {
			const key = name.toLowerCase();
			if ( '' === name || seen.has( key ) ) {
				return false;
			}
			seen.add( key );
			return true;
		} )
		.slice( 0, MAX_TOPICS );
}
