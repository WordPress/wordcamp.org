import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: () => {
		return wp.element.createElement(
			'span',
			{ className: 'wp-block-wporg-event-rsvp-count' },
			__( 'N going', 'wporg-groups-frontend' )
		);
	},
	save: () => null,
} );
