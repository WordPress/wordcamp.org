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

const { name, ...settings } = metadata;

registerBlockType( name, {
	...settings,
	title: __( 'Attendee Content', 'wordcamporg' ),
	description: __( 'This content is only shown once the viewer enters a valid attendee email.', 'wordcamporg' ),
	keywords: [ __( 'private content', 'wordcamporg' ), __( 'restricted content', 'wordcamporg' ) ],
	edit: () => (
		<>
			<div style={ { background: 'rgba(0,0,0,0.1)' } }>
				<p>
					<em>{ __( 'The content below is only shown to registered attendees.', 'wordcamporg' ) }</em>
				</p>
			</div>
			<InnerBlocks />
			<div style={ { background: 'rgba(0,0,0,0.1)' } }>
				<p>
					<em>{ __( 'End of attendee content.', 'wordcamporg' ) }</em>
				</p>
			</div>
		</>
	),
	save: () => <InnerBlocks.Content />,
} );
