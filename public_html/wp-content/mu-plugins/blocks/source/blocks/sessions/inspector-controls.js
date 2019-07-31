/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { GridInspectorPanel, ImageInspectorPanel, featuredImageSizePresets } from '../../components';

/**
 * Component for block controls that appear in the Inspector Panel.
 */
export default class extends Component {
	/**
	 * Render the controls.
	 *
	 * @return {Element}
	 */
	render() {
		const { attributes, setAttributes, blockData } = this.props;
		const {
			show_images,
			featured_image_width,
			image_align,
			show_speaker,
			content,
			show_meta,
			show_category,
			sort,
		} = attributes;
		const { schema, options } = blockData;

		return (
			<InspectorControls>
				<GridInspectorPanel
					attributes={ attributes }
					blockData={ blockData }
					setAttributes={ setAttributes }
				/>

				<ImageInspectorPanel
					title={ __( 'Featured Image Settings', 'wordcamporg' ) }
					show={ show_images }
					onChangeShow={ ( value ) => setAttributes( { show_images: value } ) }
					size={ featured_image_width }
					onChangeSize={ ( value ) => setAttributes( { featured_image_width: value } ) }
					sizeSchema={ schema.featured_image_width }
					sizePresets={ featuredImageSizePresets }
					align={ image_align }
					onChangeAlign={ ( value ) => setAttributes( { image_align: value } ) }
					alignOptions={ options.align_image }
				/>

				<PanelBody title={ __( 'Content Settings', 'wordcamporg' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Description', 'wordcamporg' ) }
						value={ content || 'full' }
						options={ options.content }
						onChange={ ( value ) => setAttributes( { content: value } ) }
					/>
					<ToggleControl
						label={ __( 'Details', 'wordcamporg' ) }
						help={ __( 'Show date, time, and track.', 'wordcamporg' ) }
						checked={ show_meta === undefined ? false : show_meta }
						onChange={ ( value ) => setAttributes( { show_meta: value } ) }
					/>
					<ToggleControl
						label={ __( 'Categories', 'wordcamporg' ) }
						help={ __( 'Show session categories.', 'wordcamporg' ) }
						checked={ show_category === undefined ? false : show_category }
						onChange={ ( value ) => setAttributes( { show_category: value } ) }
					/>
					<ToggleControl
						label={ __( 'Speakers', 'wordcamporg' ) }
						help={ __( 'Show session speakers.', 'wordcamporg' ) }
						checked={ show_speaker === undefined ? false : show_speaker }
						onChange={ ( value ) => setAttributes( { show_speaker: value } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Sorting', 'wordcamporg' ) }>
					<SelectControl
						label={ __( 'Sort by', 'wordcamporg' ) }
						value={ sort }
						options={ options.sort || 'session_time' }
						onChange={ ( value ) => setAttributes( { sort: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
		);
	}
}
