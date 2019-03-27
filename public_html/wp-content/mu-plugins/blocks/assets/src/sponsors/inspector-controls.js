/**
 * WordPress dependencies.
 */
const { Component } = wp.element;
const { InspectorControls } = wp.editor;
const { PanelBody, PanelRow, ToggleControl, SelectControl } = wp.components;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import GridInspectorControl from '../shared/grid-layout/inspector-control';
import FeaturedImageInspectorControls from '../shared/featured-image/inspector-control';

/**
 * Class for defining Inspector control in sponsor block.
 */
class SponsorInspectorControls extends Component {

	/**
	 * Renders inspector controls.
	 */
	render() {

		const sortOptions = [
			{ label : __( 'Name (A to Z)', 'wordcamporg' ), value : 'name_asc' },
			{ label : __( 'Name (Z to A)', 'wordcamporg' ), value : 'name_desc' },
			{ label : __( 'Sponsor Level', 'wordcamporg' ), value : 'sponsor_level' },
		];

		const { attributes, setAttributes } = this.props;
		const {
			show_name, show_logo, show_desc, sort_by
		} = attributes;

		return (
			<InspectorControls>
				<GridInspectorControl
					{ ...this.props }
				/>
				<PanelBody
					title = { __( 'Content Settings', 'wordcamporg' ) }
					initialOpen = { true }
				>
					<PanelRow>
						<ToggleControl
							label = { __( 'Name', 'wordcamporg' ) }
							help = { __( 'Show or hide sponsor name', 'wordcamporg' ) }
							checked = { show_name === undefined ? true : show_name }
							onChange={ ( value ) => setAttributes( { show_name: value } ) }
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label = { __( 'Logo', 'wordcamporg' ) }
							help = { __( 'Show or hide sponsor logo', 'wordcamporg' ) }
							checked = { show_logo === undefined ? true : show_logo }
							onChange={ ( value ) => setAttributes( { show_logo: value } ) }
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label = { __( 'Description', 'wordcamporg' ) }
							help = { __( 'Show or hide sponsor description', 'wordcamporg' ) }
							checked = { show_desc === undefined ? true : show_desc }
							onChange={ ( value ) => setAttributes( { show_desc: value } ) }
						/>
					</PanelRow>
					<PanelRow>
						<SelectControl
							label = { __( 'Sort by', 'wordcamporg' ) }
							options = { sortOptions }
							value = { sort_by || 'name_asc' }
							onChange={ ( value ) => setAttributes( { sort_by: value } ) }
							help = { __( 'Configure sponsor levels from the Sponsor -> Order Sponsor Levels page.', 'wordcamporg' ) }
						/>
					</PanelRow>
				</PanelBody>
				<FeaturedImageInspectorControls
					title = { __( 'Logo size', 'wordcamporg' ) }
					help = { __( 'Specify logo width, or select a predefined size.', 'wordcamporg' ) }
					selectLabel = { __( 'Size', 'wordcamporg') }
					{ ...this.props }
				/>
			</InspectorControls>
		)
	}
}

export default SponsorInspectorControls;
