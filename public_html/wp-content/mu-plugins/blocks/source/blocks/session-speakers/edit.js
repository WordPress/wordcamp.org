/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';

export default function( { attributes, setAttributes, context: { postId, postType }, isSelected } ) {
	const { byline, isLink } = attributes;
	const blockProps = useBlockProps();
	const speakers = useSelect( ( select ) => {
		const { getEntityRecord } = select( coreStore );
		const session = getEntityRecord( 'postType', postType, postId );
		return session.session_speakers || [];
	}, [] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'wordcamporg' ) }>
					<ToggleControl
						label={ __( 'Link to speaker', 'wordcamporg' ) }
						onChange={ () => setAttributes( { isLink: ! isLink } ) }
						checked={ isLink }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ ( ! RichText.isEmpty( byline ) || isSelected ) && (
					<RichText
						className="wp-block-wordcamp-session-speakers__byline"
						multiline={ false }
						aria-label={ __( 'Session speaker byline text', 'wordcamporg' ) }
						placeholder={ __( 'Presented by', 'wordcamporg' ) }
						value={ byline }
						onChange={ ( value ) => setAttributes( { byline: value } ) }
					/>
				) }
				{ speakers.map( ( { id, name, link } ) => (
					<span key={ id } className="wp-block-wordcamp-session-speakers__name">
						{ isLink ? <a href={ link }>{ name }</a> : name }
					</span>
				) ) }
			</div>
		</>
	);
}
