// Import the default config for core compatibility, but enable us to add some overrides as needed.
const defaultConfig = require( '@wordpress/scripts/config/.prettierrc.js' );

module.exports = {
	...defaultConfig,
	printWidth: 115,
};

/*
 *
 * alternatives
 *      existing prettier forks
 *          https://github.com/brodybits/prettierx - already has --paren-spacing from wp-prettier merged
 *      write your own prettier fork
 *      https://github.com/prettier/prettier-eslint - maybe combine w/ your own existing/custom fork, so only have to change things that eslint can't fix
 *          probably needs custom eslint rules as well, but can maybe use some existing eslint plugins to get some of that
    *          https://github.com/yannickcr/eslint-plugin-react/blob/master/docs/rules/jsx-one-expression-per-line.md
 *          related: https://github.com/hugomrdias/prettier-stylelint, https://github.com/Dreamseer/stylelint-formatter-pretty
 */

/*
 * todo can fix?
 *
 * glancing at config options, it doesn't seem like there's much of anything, but keep an eye out
 *
 * why isn't the last { aligned with the first one? maybe a prettier bug?
 * { assignedTracks &&
	   assignedTracks.map( ( track ) => {
	           return <dd key={ track.id }>{ track.name }</dd>;
	   } ) }

 * it won't let me add temporary comments that are formatted in a way i can read, while trying to help understand why something isn't working
 * all the comments get immediately pushed back into
 * prettier-ignore-start doesn't seem to work
 * maybe just run it manually once right before review, rather than as a file watcher

	console.log( date( timeFormat, 1566388800 * 1000 ) );               // 5am / browser    in site TZ
	console.log( gmdate( timeFormat, 1566388800 * 1000 ) );             // 12pm UTC         supposed to add site TZ to timestamp, so result should be 10?
	console.log( format( timeFormat, 1566388800 * 1000 ) );             // 5am / browser
	console.log( dateI18n( timeFormat, 1566388800 * 1000, true ) );     // 5am / browser
	console.log( dateI18n( timeFormat, 1566388800 * 1000, false ) );    // 5am / browser
	console.log( getDate( 1566388800 * 1000 ) );                        // 5am / browser

 *
 *
 *
 */

/*
  probably can't fix b/c too prettier is too opinionated and inflexible:

  should leave args and children on separate line each if there's more than 1 of them
    can maybe partially fix w/ https://github.com/yannickcr/eslint-plugin-react/blob/master/docs/rules/jsx-one-expression-per-line.md and https://github.com/prettier/prettier-eslint

      `<ScheduleGrid icon={ ICON } attributes={ attributes } entities={ entities } />`
      `<div className="wordcamp-schedule">{ scheduleDays }</div>`
           really really hate this type especially. if could fix this, it'd go a long way to accepting.
           is there some kind of workaround maybe?
      `return <>{ timeGroups }</>;`
      but prettier just fits as many as it can without going over `printWidth`
      "Prettier [ as opposed to an intelligent human being ] strives to fit the most code into every line. With the print width set to 120, prettier may produce overly compact, or otherwise undesirable code."
      could lower print-width, but that'd have unintended side-effects
      don't want to artificially restrict though, it's not the 80s anymore
 
      another example of ^
  return <div className="wordcamp-schedule">{ scheduleDays }</div>; should be 3+ lines
       prettier assumes that # of characters is what matters, but it's not
       # of "things going on" is what matters
           1) returning; 2) div w/ classes; 3) contents of div
           that's too many things to parse out mentally for a single line
       it also hides the hierarchical relationship between the wrapper and its contents

       maybe write prettierx arg to avoid collapsing children onto same line
       or --fixable eslint rule to uncollapse them
 
 */

/*
 * note that wrapping a JSX condition+element in parens can sometimes (but not always) save it from being undesirably compacted onto a single line
 */

/*
 * good things
 *
 * if you can learn to love the bomb, then you can stop thinking about all this
 * automatically re-formatting comments to wrap at printWidth is nice, doing that manually is always a pain
 *      but it doesn't always do that? why sometimes and not always?
 */
