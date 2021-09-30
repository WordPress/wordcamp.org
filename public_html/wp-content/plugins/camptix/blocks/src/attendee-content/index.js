/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Edit from './edit.js';

const { name, ...settings } = metadata;

registerBlockType( name, {
	...settings,
	title: __( 'Attendee Content', 'wordcamporg' ),
	description: __( 'This content is only shown once the viewer enters a valid attendee email.', 'wordcamporg' ),
	keywords: [ __( 'private content', 'wordcamporg' ), __( 'restricted content', 'wordcamporg' ) ],
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
