/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './edit.scss';
import Edit from './edit';

export const NAME = 'wordcamp/sessions';
export const LABEL = __( 'Sessions', 'wordcamporg' );
export const ICON = 'list-view';

const supports = {
	align: [ 'wide', 'full' ],
};

export const SETTINGS = {
	title: __( 'Sessions', 'wordcamporg' ),
	description: __( 'Add a list of sessions.', 'wordcamporg' ),
	icon: ICON,
	category: 'wordcamp',
	supports: supports,
	edit: Edit,
	save: () => null,
};
