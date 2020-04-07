/*
 * @todo discuss these potential changes
 *
 * `arrow-parens` `as-needed` - skipping them when only have 1 arg remove unnecessary clutter, which helps readability.
 *      e.g., `const w = x.filter( ( y ) => z.includes( y.id ) );` should be `const w = x.filter( y => z.includes( y.id ) );`
 * `no-unused-vars` `after-used` - `forEach( ( [ , sessions ] )` looks odd, and you don't know what that first arg actually is, in case you want to use it in the future.
 *      see getOverlappingSessionIds()
 * `no-multiple-empty-lines` - somestimes 2 lines useful to separate sections of code, like `import`s at top of file from `export` of components
 * `require-jsdoc` - not for `render` and lifecycle methods b/c it's obvious what they are
 *
 * assignment and control structures/returns/etc should be separate by a blank line for readability.
 *      same for div and other block-level html elements
 *
 * should use hasOwnProperty or Object.getOwnPropertyDescriptors(). the latter usually makes code more readable, but sometimes the former first better
 *
 * disable `no-console` b/c valid use case. if can make exception for `log` function without disabling, then do that. don't want console used for temporary debugging, but there are valid cases where you want to provide the user some insight into what went wrong
 *
 * padded-blocks - often useful for readability.
 * may need to use `padding-line-between-statements` to fix
 * e.g.,

if ( chooseSpecificTracks && chosenTrackIds.length ) {
	displayedTracksIds = chosenTrackIds;

} else {
	// Gather all of the tracks from the given sessions.
	const uniqueTrackIds = new Set();

	for ( const session of sessions ) {
		for ( const track of session.derived.assignedTracks ) {
			uniqueTrackIds.add( track.id );
		}
	}

	displayedTracksIds = Array.from( uniqueTrackIds );
}

 *
 * still having trouble reading things like the 6 assignments at the start of Session().
 *      maybe a solution that'd work for everyone would be to align the `=` and `:` operators _IF_ the white space created wouldn't be more than X characters?
 *      if it is larger, then can add an empty line in-between
 *      doing that in core IIRC
 *      e.g.,

 	const { id, slug, title } = session;
	const { assignedCategories, assignedTracks, startTime, endTime } = session.derived;
	const displayedTrackIds = displayedTracks.map( track => track.id );
	const displayedAssignedTracks = assignedTracks.filter( track => displayedTrackIds.includes( track.id ) );
	const speakers = session.session_speakers || [];
	const editUrl = `/wp-admin/post.php?post=${ id }&action=edit`;

    ...becomes

    const { id, slug, title } = session;
	const { assignedCategories, assignedTracks, startTime, endTime } = session.derived;

	const displayedTrackIds       = displayedTracks.map( track => track.id );
	const displayedAssignedTracks = assignedTracks.filter( track => displayedTrackIds.includes( track.id ) );
	const speakers                = session.session_speakers || [];
	const editUrl                 = `/wp-admin/post.php?post=${ id }&action=edit`;

	...or maybe

	const { id, slug, title } = session;
	const { assignedCategories, assignedTracks, startTime, endTime } = session.derived;

	const displayedTrackIds       = displayedTracks.map( track => track.id );
	const displayedAssignedTracks = assignedTracks.filter( track => displayedTrackIds.includes( track.id ) );

	const speakers = session.session_speakers || [];
	const editUrl  = `/wp-admin/post.php?post=${ id }&action=edit`;


	another example that's hard to read:

	export const SETTINGS = {
		title: __( 'Schedule', 'wordcamporg' ),
		description: __( "Display your WordCamp's awesome schedule.", 'wordcamporg' ),
		icon: ICON,
		category: 'wordcamp',
		supports: supports,
		attributes: attributes,
		edit: Edit,
		save: () => null,
	};
 *
 * add eslint-plugin-react-hooks plugin? or propose it be added to default wpscripts config?
 *
 * indent: ignoreComments -- i often use indenting in temporary comments to signify logic e.g.,

 // todo if x then do y
       // but otherwise then do z

 ... but eslint complains about it. not a huge deal since those typically get manually removed before merge, but still annoying to workflow
 */

module.exports = {
	extends: 'plugin:@wordpress/eslint-plugin/recommended-with-formatting',

	/**
	 * Setting this to true is necessary to avoid merging WordCamp.org configs with any that exist in parent
	 * folders in the developer's environment.
	 * See https://github.com/eslint/eslint/commit/c99e9b621fe46b8535cf59fe489977dd36cb2a39.
	 */
	root: true,

	globals: {
		wp: true,
	},

	ignorePatterns: [ '*.min.js' ],

	rules: {
		/*
		 * Set up our text domain.
		 */
		'@wordpress/i18n-text-domain': [ 'error', { allowedTextDomain: [ 'wordcamporg' ] } ],

		/*
		 * The rationale behind this rule is that sometimes a variable is defined by a costly operation, but then
		 * the variable is never used, so that operation was wasted. That's a valid point, but in practice that
		 * doesn't happen very often, so the benefit is not significant.
		 *
		 * The benefits of grouping variable assignments at the start of a function outweigh the costs, since it
		 * almost always makes the function easier to quickly grok.
		 *
		 * In the uncommon case where a significant performance penalty would be introduced, the developer is
		 * still free to choose to define the variable after the early returns.
		 */
		'@wordpress/no-unused-vars-before-return': [ 'off' ],

		/*
		 * Instead of turning this off altogether, we should safelist the parameters that are coming in from
		 * the REST API. However, the `allow` config for this rule is only available in eslint 5+. Currently
		 * the @wordpress/scripts package uses eslint 4.x, but the next version will bump it up to 5.
		 *
		 * Here is the config to use once this is possible:
		 *
		 * 'camelcase' : [
		 *     'error',
		 *     {
		 *         allow: [ // These are variables defined in PHP and exposed via the REST API.
		 *             // Speakers block
		 *  		   'post_ids', 'term_ids', 'grid_columns',
		 *  		   'show_avatars', 'avatar_size', 'avatar_align',
		 *  		   'speaker_link', 'show_session',
		 *         ],
		 *     },
		 * ],
		 */
		camelcase: 'off',

		/*
		 * Short variable names are almost always obscure and non-descriptive, but they should be meaningful,
		 * obvious, and self-documenting.
		 */
		'id-length': [
			'error',
			{
				min: 3,
				exceptions: [ '__', '_n', '_x', 'id', 'a', 'b', 'i' ],
			},
		],

		/*
		 * Force a line-length of 115 characters.
		 *
		 * We ignore URLs, trailing comments, strings, and template literals to prevent awkward fragmenting of
		 * meaningful content.
		 */
		'max-len': [
			'error',
			{
				code: 115,
				ignoreUrls: true,
				ignoreTrailingComments: true,
				ignoreStrings: true,
				ignoreTemplateLiterals: true,
			},
		],

		/*
		 * Objects are harder to quickly scan when the formatting is inconsistent.
		 */
		'object-shorthand': [ 'error', 'consistent-as-needed' ],

		/**
		 * Only prefer const over let when destructuring if all variables in the declaration are never reassigned.
		 *
		 * With the default setting of this rule, to prefer const when any of the destructured variables are never
		 * reassigned, we end up with situations where we have to destructure the same entity twice, which seems
		 * inefficient. E.g. if in the below example 'a' gets reassigned but 'b' doesn't:
		 *
		 * let { a, b } = var;
		 *
		 * Seems better than having to do:
		 *
		 * let { a } = var;
		 * const { b } = var;
		 */
		'prefer-const': [
			'error',
			{
				destructuring: 'all',
			},
		],

		/**
		 * Disallow creating more than one component per file.
		 *
		 * Having one component per file makes components easier to test and easier to document. `ignoreStateless`
		 * allows us to still keep simple stateless components, but these should be used sparingly.
		 */
		'react/no-multi-comp': [
			'error',
			{
				ignoreStateless: true,
			},
		],

		/*
		 * Sort imports alphabetically, at least inside multiple-member imports. Ignores declaration sorting since
		 * this interfers with the External/WordPress/Internal groupings. For example, it will flag the following
		 * as incorrect:
		 *
		 *   import { c, a, b } from 'foo';
		 *
		 * Running `eslint --fix` will update this to
		 *
		 *   import { a, b, c } from 'foo';
		 *
		 */
		'sort-imports': [
			'error',
			{
				ignoreDeclarationSort: true,
			},
		],

		/*
		 * Descriptions are often obvious from the variable and function names, so always requiring them would be
		 * inconvenient. The developer should add one whenever it's not obvious, though.
		 *
		 * @todo `@param` tags should align the variable name and description, just like in PHP.
		 */
		'jsdoc/require-returns-description': 'off',
	},
};
