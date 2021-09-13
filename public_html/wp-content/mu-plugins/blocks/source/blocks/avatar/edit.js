/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';

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
	const [ featuredImage ] = useEntityProp( 'postType', postType, 'featured_media', postId );
	const url = useSelect(
		( select ) => {
			if ( ! featuredImage ) {
				return urls ? urls[ size ] : '';
			}
			const image = select( coreStore ).getMedia( featuredImage, { context: 'view' } );
			return image?.source_url || '';
		},
		[ featuredImage ]
	);

	if ( ! url ) {
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
				<img src={ url } alt={ __( 'Avatar', 'wordcamporg' ) } />
			</figure>
		</>
	);
}
