import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: () => {
		return wp.element.createElement(
			'span',
			{ className: 'wp-block-wporg-event-venue-name' },
			__( 'Venue name', 'wporg-groups-frontend' )
		);
	},
	save: () => null,
} );
