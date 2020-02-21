/**
 * WordPress dependencies
 */
import { AlignmentToolbar, InspectorControls } from '@wordpress/block-editor';
import { BaseControl, PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
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
	align_image: {},
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
		const { attributes, blockData, setAttributes } = this.props;
		const { avatar_align, avatar_size, content, headingAlign, show_avatars, show_session, sort } = attributes;
		const { options = DEFAULT_OPTIONS, schema = DEFAULT_SCHEMA } = blockData;

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
					<BaseControl>
						<span className="components-base-control__label">
							{ __( 'Speaker name alignment', 'wordcamporg' ) }
						</span>
						<AlignmentToolbar
							isCollapsed={ false }
							value={ headingAlign }
							onChange={ ( nextAlign ) => {
								setAttributes( { headingAlign: nextAlign } );
							} }
						/>
					</BaseControl>

					<SelectControl
						label={ __( 'Biography Length', 'wordcamporg' ) }
						value={ content }
						options={ options.content }
						onChange={ ( value ) => setAttributes( { content: value } ) }
					/>
					<ToggleControl
						label={ __( 'Session Information', 'wordcamporg' ) }
						help={ show_session
							? __( "Speaker's session name, time, and track are visible.", 'wordcamporg' )
							: __( "Speaker's session name, time, and track are hidden.", 'wordcamporg' ) }
						checked={ show_session }
						onChange={ ( value ) => setAttributes( { show_session: value } ) }
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
