/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { edit } from './edit.js';

export const name = 'wordcamp/speakers';

export const settings = {
	title       : __( 'Speakers', 'wordcamporg' ),
	description : __( 'Add a list of speakers.', 'wordcamporg' ),
	icon        : 'megaphone',
	category    : 'wordcamp',
	edit,
	save        : function() {
		return null;
		// should we return a cached copy of the markup here, as a failsafe in case we ever want to disable this block or make some big changes to it?
		// disabling it entirely seems unlikely, but we might eventually want to build a v2 from scratch or something
		// not having to worry about back-compat might give us some flexibility in the future
		// https://wordpress.org/gutenberg/handbook/designers-developers/developers/block-api/block-edit-save/#save
	},
};
