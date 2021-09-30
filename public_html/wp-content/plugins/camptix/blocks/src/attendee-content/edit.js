/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { InnerBlocks, InspectorControls } from '@wordpress/block-editor';

export default function( { attributes, setAttributes } ) {
	const { markAttended } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'wordcamporg' ) } initialOpen>
					<ToggleControl
						label={ __( 'Mark viewers as attended?', 'wordcamporg' ) }
						help={ __(
							'When an attendee logs in to view this content, it will mark them as attending the event.',
							'wordcamporg'
						) }
						checked={ markAttended }
						onChange={ () => setAttributes( { markAttended: ! markAttended } ) }
					/>
				</PanelBody>
			</InspectorControls>
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
	);
}
