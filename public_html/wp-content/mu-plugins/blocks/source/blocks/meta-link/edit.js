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
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';

export default function Edit( { attributes, setAttributes, context: { postId, postType } } ) {
	const { key, text, textAlign } = attributes;
	const url = useSelect(
		( select ) => {
			const { getEntityRecord } = select( coreStore );
			const post = getEntityRecord( 'postType', postType, postId );
			return post.meta[ key ];
		},
		[ key ]
	);

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
			{ ! url && (
				<InspectorControls>
					<Notice status="error" isDismissible={ false }>
						{ __( 'The link for this content is missing. Add the URL in the Session tab.', 'wordcamporg' ) }
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
