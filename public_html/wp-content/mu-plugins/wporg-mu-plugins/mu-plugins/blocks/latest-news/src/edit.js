/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	Disabled,
	__experimentalNumberControl as NumberControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	PanelBody,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Renders controls and a preview of this dynamic block.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes
 * @param {Function} props.setAttributes
 * @param {string}   props.name
 *
 * @return {WPElement} Element to render.
 */
export default function Edit( { attributes, setAttributes, name } ) {
	const { blogId, perPage, showCategories } = attributes;

	const onPerPageChange = ( value ) => setAttributes( { perPage: value * 1 } );
	const onBlogIdChange = ( value ) => setAttributes( { blogId: Number( value ) } );
	const onShowCategoriesChange = ( value ) => setAttributes( { showCategories: value } );

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'wporg' ) }>
					<NumberControl
						label={ __( 'Blog ID', 'wporg' ) }
						onChange={ onBlogIdChange }
						value={ blogId }
						help={ __(
							'For example, 8 for wordpress.org/news, 719 for developer.wordpress.org/news',
							'wporg'
						) }
					/>
					<NumberControl
						label={ __( 'Items To Show', 'wporg' ) }
						onChange={ onPerPageChange }
						value={ perPage }
					/>
					<CheckboxControl
						label={ __( 'Show Categories', 'wporg' ) }
						onChange={ onShowCategoriesChange }
						checked={ showCategories }
					/>
				</PanelBody>
			</InspectorControls>
			<Disabled>
				<ServerSideRender block={ name } attributes={ attributes } />
			</Disabled>
		</div>
	);
}
