/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { PanelBody, PanelRow, ToggleControl } from '@wordpress/components';
import { Component, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './edit.scss';
import ImageAlignmentControl from './image-alignment-control';
import ImageSizeControl from './image-size-control';

/**
 * Component to add an Inspector panel with image-related controls.
 *
 * @param {Object} props {
 *     @type {string}   title
 *     @type {boolean}  initialOpen
 *     @type {string}   className
 *     @type {boolean}  show
 *     @type {Function} onChangeShow
 *     @type {number}   size
 *     @type {Function} onChangeSize
 *     @type {Object}   sizeSchema
 *     @type {Array}    sizePresets
 *     @type {string}   align
 *     @type {Function} onChangeAlign
 *     @type {Array}    alignOptions
 * }
 */
class ImageInspectorPanel extends Component {
	/**
	 * Render the control.
	 *
	 * @return {Element}
	 */
	render() {
		const {
			title = __( 'Image Settings', 'wordcamporg' ),
			initialOpen = true,
			className,
			show,
			onChangeShow,
			size,
			onChangeSize,
			sizeSchema,
			sizePresets = [],
			align,
			onChangeAlign,
			alignOptions = [],
		} = this.props;

		return (
			<PanelBody
				title={ title }
				initialOpen={ initialOpen }
				className={ classnames( 'wordcamp-image__inspector-panel', className ) }
			>
				<ToggleControl
					label={ __( 'Show images', 'wordcamporg' ) }
					help={ show
						? __( 'Images are visible.', 'wordcamporg' )
						: __( 'Images are hidden.', 'wordcamporg' ) }
					checked={ show }
					onChange={ onChangeShow }
				/>
				{ show && (
					<Fragment>
						<ImageSizeControl
							label={ __( 'Size', 'wordcamporg' ) }
							value={ Number( size ) }
							initialPosition={ Number( sizeSchema.default ) }
							sizePresets={ sizePresets }
							onChange={ onChangeSize }
							rangeProps={ {
								min: Number( sizeSchema.minimum ),
								max: Number( sizeSchema.maximum ),
							} }
						/>
						{ /* The PanelRow wrapper prevents the toolbar from expanding to full width. */ }
						<PanelRow>
							<ImageAlignmentControl
								label={ __( 'Alignment', 'wordcamporg' ) }
								value={ align }
								onChange={ onChangeAlign }
								alignOptions={ alignOptions }
							/>
						</PanelRow>
					</Fragment>
				) }
			</PanelBody>
		);
	}
}

export default ImageInspectorPanel;
