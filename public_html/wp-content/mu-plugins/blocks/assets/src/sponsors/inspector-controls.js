/**
 * WordPress dependencies.
 */
const { Component, Fragment } = wp.element;
const { InspectorControls } = wp.editor;
const { PanelBody } = wp.components;
const { __ } = wp.i18n;


/**
 * Class for defining Inspector control in sponsor block.
 */
class SponsorInspectorControls extends Component {

	/**
	 * Renders inspector controls.
	 */
	render() {

		const { attributes, setAttributes } = this.props;

		return (
			<InspectorControls>
				<PanelBody
					title = { __( 'Content Settings' ) }
					initialOpen = { true }
				>
				</PanelBody>
			</InspectorControls>
		)

	}

}

export default SponsorInspectorControls;
