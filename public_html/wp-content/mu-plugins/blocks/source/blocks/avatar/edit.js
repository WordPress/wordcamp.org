/**
 * WordPress dependencies
 */
import { useEntityProp } from '@wordpress/core-data';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { __, sprintf } from '@wordpress/i18n';

const placeholderChip = (
	<div className="post-featured-image_placeholder">
		<p> { __( 'Avatar', 'wordcamporg' ) }</p>
	</div>
);

function getPostLabel( postType ) {
	switch ( postType ) {
		case 'wcb_speaker':
			return __( 'Speaker', 'wordcamporg' );
		case 'wcb_organizer':
			return __( 'Organizer', 'wordcamporg' );
		default:
			return __( 'post', 'wordcamporg' );
	}
}

const blockData = window.WordCampBlocks.avatar || {};

export default function PostAvatarEdit( { attributes, setAttributes, context: { postId, postType } } ) {
	const { isLink, size = blockData.schema.size.default } = attributes;
	const [ urls ] = useEntityProp( 'postType', postType, 'avatar_urls', postId );
	const blockProps = useBlockProps( { style: { width: size, height: size } } );

	if ( ! urls || ! urls[ size ] ) {
		return <div { ...blockProps }>{ placeholderChip }</div>;
	}

	const sizeOptions = blockData.options.size.map( ( value ) => ( { label: value + 'px', value: value } ) );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'wordcamporg' ) }>
					<SelectControl
						label={ __( 'Avatar size', 'wordcamporg' ) }
						value={ size }
						options={ sizeOptions }
						onChange={ ( newSize ) => setAttributes( { size: Number( newSize ) } ) }
					/>
					<ToggleControl
						label={ sprintf(
							// translators: %s: Name of the post type e.g: "post".
							__( 'Link to %s', 'wordcamporg' ),
							getPostLabel( postType )
						) }
						onChange={ () => setAttributes( { isLink: ! isLink } ) }
						checked={ isLink }
					/>
				</PanelBody>
			</InspectorControls>
			<figure { ...blockProps }>
				<img src={ urls[ size ] } alt={ __( 'Avatar', 'wordcamporg' ) } />
			</figure>
		</>
	);
}
