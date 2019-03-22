/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { edit }            from './edit.js';
import { ORGANIZERS_ICON } from './edit.js';

export const name = 'wordcamp/organizers';

export const settings = {
	title       : __( 'Organizers',                'wordcamporg' ),
	description : __( 'Add a list of organizers.', 'wordcamporg' ),
	icon        : ORGANIZERS_ICON,
	category    : 'wordcamp',
	edit,
	save        : function() {
		return null;
	},
};
