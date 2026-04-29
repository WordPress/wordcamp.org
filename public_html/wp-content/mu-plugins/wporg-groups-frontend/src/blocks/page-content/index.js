import { registerBlockType } from '@wordpress/blocks';
import ServerSideRender from '@wordpress/server-side-render';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: ( { attributes, setAttributes } ) => {
		const blockProps = useBlockProps();
		return wp.element.createElement(
			'div',
			blockProps,
			wp.element.createElement(
				InspectorControls,
				{},
				wp.element.createElement(
					PanelBody,
					{ title: __( 'Settings', 'wporg-groups-frontend' ) },
					wp.element.createElement( TextControl, {
						label: __( 'Page slug', 'wporg-groups-frontend' ),
						value: attributes.slug,
						onChange: ( val ) => setAttributes( { slug: val } ),
					} )
				)
			),
			attributes.slug
				? wp.element.createElement( ServerSideRender, {
						block: metadata.name,
						attributes,
					} )
				: wp.element.createElement(
						'p',
						{ style: { color: '#656a71', fontStyle: 'italic' } },
						__( 'Enter a page slug in the block settings.', 'wporg-groups-frontend' )
					)
		);
	},
	save: () => null,
} );
