/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { edit } from './edit';

export const name  = 'wordcamp/schedule';
export const LABEL = __( 'Schedule', 'wordcamporg' );
export const ICON  = 'schedule';

const supports = {
	'align': [ 'wide', 'full' ],
};

export const settings = {
	title       : __( 'Schedule', 'wordcamporg' ),
	description : __( "Display your WordCamp's awesome schedule.", 'wordcamporg' ),
	icon        : ICON,
	category    : 'wordcamp',
	supports    : supports,
	edit        : edit,
	save        : () => null,

	// todo keywordsto other blocks as well, in master and commit now

	/*
	 * Make the block full-width _only_ in the editor.
	 *
	 * It's very common for camps to have 3-4 tracks, but the editor's content area is very narrow, which makes it
	 * difficult to read the schedule. Defining the block as full-width block gives it more space so it can be
	 * easier to read, but we don't want to do that on the front-end, because that would unnecessarily add extra
	 * styles that get in the way of organizer's customizing their CSS. It would also behave inconsistently between
	 * themes that do and don't support the extra alignments that Gutenberg adds.
	 *
	 * Using the standard mechanism for full-width blocks would also add a toolbar icon which could be used to turn
	 * off the wider view in the editor, which could cause confusion and clutter, without added benefit.
	 *
	 * Another potential solution would have been to manually add CSS rules to make the block full-width in the editor,
	 * but that would have meant duplicating the existing rules from Core, and tightly coupling the editing experience,
	 * which wouldn't be future-proof.
	 *
	 * Adding the alignment through `getEditWrapperProps()` allows us to achieve the desired result without any side-
	 * effects.
	 */
	//getEditWrapperProps( props ) {
	//	return {
	//		'data-align': 'full',
	//		// maybe apply this conditionally based on screen width? show responsive layout on smaller screen?
	//	};
    //},
	// probably remove ^ in favor of `supports align wide full` above, but test with 1 and 2 column schedules once get live data integrated
	// if do that, then maybe turn `full` on by default if the camp has 3+ tracks, or maybe just always, and let them turn it off if they want
};
