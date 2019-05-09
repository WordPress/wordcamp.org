/**
 * External dependencies
 */
import classnames from 'classnames';
import { debounce } from 'lodash';

/**
 * WordPress dependencies
 */
const { Component, Fragment } = wp.element;
const { BaseControl, Button, ButtonGroup, RangeControl, Toolbar } = wp.components;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import './inspector-controls.scss';

/**
 * Component for a UI control for image size.
 *
 * This control assumes the image only has one size dimension. For avatars, this is because the images are always
 * square. For featured images, this is because the width is adjustable, while the height is automatically calculated
 * to maintain the correct aspect ratio.
 *
 * @param {Object} props {
 *     @type {string}   className
 *     @type {string}   label
 *     @type {string}   help
 *     @type {number}   value
 *     @type {Array}    presetSizes
 *     @type {Function} onChange
 *     @type {number}   initialPosition
 *     @type {Object}   rangeProps
 * }
 *
 * @return {Element}
 */
export class ImageSizeControl extends Component {
	/**
	 * Run additional operations during component initialization.
	 *
	 * @param {Object} props
	 */
	constructor( props ) {
		super( props );

		this.state = {
			value    : props.value,
			onChange : debounce( props.onChange, 10 ), // Higher values lead to a noticeable degradation in visual feedback.
		};

		this.onChange = this.onChange.bind( this );
	}

	/**
	 * Wrapper for debouncing the onChange callback set in props.
	 *
	 * @param {number} value
	 */
	onChange( value ) {
		this.setState( { value } );
		this.state.onChange( value );
	}

	/**
	 * Render the size control.
	 *
	 * @return {Element}
	 */
	render() {
		const {
			className,
			label,
			help,
			presetSizes = [],
			initialPosition,
			rangeProps,
		} = this.props;
		const { value } = this.state;

		return (
			<BaseControl
				className={ classnames( 'wordcamp-image-size', className ) }
				label={ label }
				help={ help }
			>
				<div className="wordcamp-image-size-preset-buttons">
					{ presetSizes.length > 0 &&
						<ButtonGroup aria-label={ label }>
							{ presetSizes.map( ( preset ) => {
								const { name, shortName, size, slug } = preset;
								const isCurrent = value === size;

								return (
									<Button
										key={ slug }
										isLarge
										isPrimary={ isCurrent }
										aria-label={ name }
										aria-pressed={ isCurrent }
										onClick={ () => this.onChange( Number( size ) ) }
									>
										{ shortName || name }
									</Button>
								);
							})}
						</ButtonGroup>
					}

					<Button
						className="wordcamp-image-size-button-reset"
						isLarge
						isDefault
						onClick={ () => this.onChange( Number( initialPosition ) ) }
					>
						{ __( 'Reset', 'wordcamporg' ) }
					</Button>
				</div>

				<RangeControl
					className="wordcamp-image-size-range"
					value={ value }
					initialPosition={ initialPosition }
					onChange={ this.onChange }
					beforeIcon="format-image"
					afterIcon="format-image"
					aria-label={ label }
					{ ...rangeProps }
				/>
			</BaseControl>
		);
	}
}

/**
 * Component for a UI control for image alignment.
 *
 * @param {Object} props {
 *     @type {string}   className
 *     @type {string}   label
 *     @type {string}   help
 *     @type {string}   value
 *     @type {Function} onChange
 *     @type {Array}    alignOptions
 * }
 *
 * @return {Element}
 */
export function ImageAlignmentControl( {
    className,
    label,
    help,
    value,
    onChange,
    alignOptions,
} ) {
	return (
		<BaseControl
			className={ classnames( 'wordcamp-image-alignment', className ) }
			label={ label }
			help={ help }
		>
			<Toolbar
				controls={ alignOptions.map( ( alignment ) => {
					const isActive = value === alignment.value;
					const iconSlug = `align-${ alignment.value }`;

					return {
						title    : alignment.label,
						icon     : iconSlug,
						isActive : isActive,
						onClick  : () => {
							onChange( alignment.value );
						},
					};
				} ) }
			/>
		</BaseControl>
	);
}

/**
 * Component to add an Inspector panel
 *
 * Should be used with rest of the components in this folder. Will use and set attributes `show_images`,
 * `image_size`, and `image_align`.
 */
export class ImageInspectorPanel extends Component {
	/**
	 * Render the control.
	 *
	 * @return {Element}
	 */
	render() {
		const {
			title,
			initialOpen = true,
			show_images,
			image_size,
			image_align,
			imageSizeSchema = {},
			alignOptions = [],
			setAttributes,
		} = this.props;

		return (
			<PanelBody
				title={ title || __( 'Image Settings', 'wordcamporg' ) }
				initialOpen={ initialOpen }
			>
				<ToggleControl
					label={ __( 'Show images', 'wordcamporg' ) }
					checked={ show_images }
					onChange={ ( value ) => setAttributes( { show_images: value } ) }
				/>
				{ show_images &&
					<Fragment>
						<ImageSizeControl
							label={ __( 'Size', 'wordcamporg' ) }
							value={ Number( image_size ) }
							initialPosition={ Number( imageSizeSchema.default ) }
							onChange={ ( value ) => setAttributes( { image_size: value } ) }
							rangeProps={ {
								min : Number( imageSizeSchema.minimum ),
								max : Number( imageSizeSchema.maximum ),
							} }
						/>
						<ImageAlignmentControl
							label={ __( 'Alignment', 'wordcamporg' ) }
							value={ image_align }
							onChange={ ( value ) => setAttributes( { image_align: value } ) }
							alignOptions={ alignOptions }
						/>
					</Fragment>
				}
			</PanelBody>
		);
	}
}


