/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { edit } from './edit.jsx';

const supports = {
	className: false,
};

export const name = 'wordcamp/speakers';

export const settings = {
	title: __( 'Speakers', 'wordcamporg' ),
	description: __( 'Add a list of speakers.', 'wordcamporg' ),
	icon: 'megaphone',
	category: 'wordcamp',
	supports: supports,
	edit,
	save: function() {
		return null;
	},
};
