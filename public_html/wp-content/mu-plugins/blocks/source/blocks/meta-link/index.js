/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';

export const NAME = metadata.name;

export const SETTINGS = {
	...metadata,
	icon: 'admin-links',
	edit: edit,
};

if ( window.WordCampBlocks.hasOwnProperty( 'meta-link' ) ) {
	registerBlockVariation( NAME, {
		name: 'session-slides',
		title: __( 'Session Slides', 'wordcamporg' ),
		isDefault: true,
		description: __( "Display a link to the session's slides.", 'wordcamporg' ),
		attributes: {
			key: '_wcpt_session_slides',
			text: __( 'View Session Slides', 'wordcamporg' ),
		},
		isActive: ( blockAttributes ) => blockAttributes.key === '_wcpt_session_slides',
	} );
	registerBlockVariation( NAME, {
		name: 'session-video',
		title: __( 'Session Video', 'wordcamporg' ),
		description: __( "Display a link to the session's video recording.", 'wordcamporg' ),
		attributes: {
			key: '_wcpt_session_video',
			text: __( 'View Session Video', 'wordcamporg' ),
		},
		isActive: ( blockAttributes ) => blockAttributes.key === '_wcpt_session_video',
	} );
}
