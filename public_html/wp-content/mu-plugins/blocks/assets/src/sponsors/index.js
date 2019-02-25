/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies.
 */
import { edit } from './edit.js';

export const name = 'wordcamp/sponsors';

export const settings = {
	title       : __( 'Sponsors', 'wordcamporg' ),
	description : __( "We wouldn't have WordCamp without their support.", 'wordcamporg' ),
	icon        : __( 'list-view', 'wordcamporg' ),
	category    : __( 'wordcamp', 'wordcamporg' ),
	edit,
	save        : function() {
		return null;
	},
};
