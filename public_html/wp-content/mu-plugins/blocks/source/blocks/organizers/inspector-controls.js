/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { GridInspectorPanel, ImageInspectorPanel, avatarSizePresets } from '../../components';

const DEFAULT_SCHEMA = {
	grid_columns: {
		default: 2,
		minimum: 2,
		maximum: 4,
	},

	avatar_size: {
		default: 150,
		minimum: 25,
		maximum: 600,
	},
};

const DEFAULT_OPTIONS = {
	align: {},
	content: {},
	sort: {},
};

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
		const { show_avatars, avatar_size, avatar_align, content, sort } = attributes;
		const { schema = DEFAULT_SCHEMA, options = DEFAULT_OPTIONS } = blockData;

		return (
			<InspectorControls>
				<GridInspectorPanel
					attributes={ attributes }
					blockData={ blockData }
					setAttributes={ setAttributes }
				/>

				<ImageInspectorPanel
					title={ __( 'Avatar Settings', 'wordcamporg' ) }
					show={ show_avatars }
					onChangeShow={ ( value ) => setAttributes( { show_avatars: value } ) }
					size={ avatar_size }
					onChangeSize={ ( value ) => setAttributes( { avatar_size: value } ) }
					sizeSchema={ schema.avatar_size }
					sizePresets={ avatarSizePresets }
					align={ avatar_align }
					onChangeAlign={ ( value ) => setAttributes( { avatar_align: value } ) }
					alignOptions={ options.align_image }
				/>

				<PanelBody title={ __( 'Content Settings', 'wordcamporg' ) } initialOpen={ false }>
					<SelectControl
						label={ __( 'Biography Length', 'wordcamporg' ) }
						value={ content }
						options={ options.content }
						onChange={ ( value ) => setAttributes( { content: value } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Sorting & Filtering', 'wordcamporg' ) } initialOpen={ false }>
					<SelectControl
						label={ __( 'Sort by', 'wordcamporg' ) }
						value={ sort }
						options={ options.sort }
						onChange={ ( value ) => setAttributes( { sort: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
		);
	}
}
