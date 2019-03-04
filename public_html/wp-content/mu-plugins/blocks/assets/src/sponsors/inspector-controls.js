/**
 * WordPress dependencies.
 */
const { Component, Fragment } = wp.element;
const { InspectorControls } = wp.editor;
const { PanelBody, PanelRow, SelectControl } = wp.components;
const { __ } = wp.i18n;


/**
 * Class for defining Inspector control in sponsor block.
 */
class SponsorInspectorControls extends Component {

	setFeaturedImageSize( value ) {
		const { setAttributes } = this.props;
		const { height, width } = SponsorInspectorControls.getSizeChart()[ value ];
		setAttributes( { featuredImageSize: { height, width } } );
	}

	/**
	 * Renders inspector controls.
	 */
	render() {

		const { attributes, setAttributes } = this.props;
		const { featuredImageSize } = attributes;
		const featuredImageSizeOptions = [
			{ label: __( 'Small', 'wordcamporg' ), value: 's' },
			{ label: __( 'Medium', 'wordcamporg' ), value: 'm' },
			{ label: __( 'Large', 'wordcamporg' ), value: 'l' },
		];

		return (
			<InspectorControls>
				<PanelBody
					title = { __( 'Content Settings' ) }
					initialOpen = { true }
				>
					<PanelRow>
						Featured Image Size
					</PanelRow>
					<PanelRow>

					</PanelRow>
				</PanelBody>
			</InspectorControls>
		)
	}
}

export default SponsorInspectorControls;
