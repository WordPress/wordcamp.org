/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Placeholder } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';

export const title = __( 'Live Schedule', 'wordcamporg' );
export const icon = 'excerpt-view';

registerBlockType( 'wordcamp/live-schedule', {
	title: title,
	description: __( 'Display a live schedule interface.', 'wordcamporg' ),
	icon: icon,
	category: 'wordcamp',
	supports: {
		align: [ 'wide', 'full' ],
	},
	edit: () => <Placeholder icon={ icon } label={ title } />,
	save: () => <div id="day-of-event" />,
} );
