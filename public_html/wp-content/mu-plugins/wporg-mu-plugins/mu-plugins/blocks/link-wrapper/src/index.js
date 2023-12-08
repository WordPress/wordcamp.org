/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { useRef } from '@wordpress/element';
import { InnerBlocks, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import metadata from './block.json';

function Edit( { attributes, setAttributes } ) {
	const wrapperRef = useRef();

	// Check for nested links or buttons. This doesn't catch every case, since
	// some blocks have settings to output links on the frontend (images), but
	// it provides some guardrails to validate the content.
	const links = wrapperRef.current?.querySelectorAll( 'a,[data-type="core/button"]' ) || [];

	return (
		<>
			<InspectorControls key="setting">
				<PanelBody title={ __( 'Link destination', 'wporg' ) }>
					<TextControl
						label={ __( 'Link destination', 'wporg' ) }
						hideLabelFromVision
						value={ attributes.url }
						onChange={ ( val ) => setAttributes( { url: val } ) }
						type="url"
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps( { ref: wrapperRef } ) }>
				<InnerBlocks />
				{ !! links.length && (
					<Notice
						className="wporg-link-wrapper__notice"
						spokenMessage={ null }
						status="warning"
						isDismissible={ false }
					>
						{ __(
							'This block should not contain links or buttons. Remove the link, or use a different container.',
							'wporg'
						) }
					</Notice>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: ( { attributes } ) => {
		const blockProps = useBlockProps.save();
		return (
			<a { ...blockProps } href={ attributes.url }>
				<InnerBlocks.Content />
			</a>
		);
	},
} );
