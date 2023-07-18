/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	AlignmentControl,
	BlockControls,
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { Notice } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';

export default function Edit( { attributes, setAttributes, context: { postId, postType } } ) {
	const { key, text, textAlign } = attributes;
	const [ meta = {} ] = useEntityProp( 'postType', postType, 'meta', postId );
	const url = meta[ key ] || '';

	const blockProps = useBlockProps( {
		className: classnames( {
			[ `has-text-align-${ textAlign }` ]: textAlign,
		} ),
	} );

	return (
		<>
			<BlockControls group="block">
				<AlignmentControl
					value={ textAlign }
					onChange={ ( nextAlign ) => {
						setAttributes( { textAlign: nextAlign } );
					} }
				/>
			</BlockControls>
			{ postId && postType && ! url && (
				<InspectorControls>
					<Notice status="error" isDismissible={ false }>
						{ __(
							'The link for this content is missing. Add the URL in the Session tab.',
							'wordcamporg'
						) }
					</Notice>
				</InspectorControls>
			) }
			<div { ...blockProps }>
				<RichText
					tagName="a"
					href={ url }
					multiline={ false }
					aria-label={ __( 'Link text', 'wordcamporg' ) }
					value={ text }
					onChange={ ( value ) => setAttributes( { text: value } ) }
				/>
			</div>
		</>
	);
}
