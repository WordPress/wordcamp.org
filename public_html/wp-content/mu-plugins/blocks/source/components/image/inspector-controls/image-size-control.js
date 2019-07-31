/**
 * External dependencies
 */
import { debounce } from 'lodash';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { BaseControl, Button, ButtonGroup, RangeControl } from '@wordpress/components';
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Component for a UI control for image size.
 *
 * This control assumes the image only has one adjustable size dimension. For
 * avatars, this is because the images are always square. For featured images,
 * this is because the width is adjustable, while the height is automatically
 * calculated to maintain the correct aspect ratio.
 */
class ImageSizeControl extends Component {
	/**
	 * Run additional operations during component initialization.
	 *
	 * @param {Object} props
	 */
	constructor( props ) {
		super( props );

		this.state = {
			value: props.value,
			onChange: debounce( props.onChange, 10 ), // Higher values lead to a noticeable degradation in visual feedback.
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
		const { label, help, sizePresets = [], initialPosition, rangeProps } = this.props;
		const { value } = this.state;

		return (
			<BaseControl className="wordcamp-image__size" help={ help }>
				<span className="wordcamp-image__size-label">{ label }</span>
				<div className="wordcamp-image__size-preset-buttons">
					{ sizePresets.length > 0 && (
						<ButtonGroup aria-label={ label }>
							{ sizePresets.map( ( preset ) => {
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
							} ) }
						</ButtonGroup>
					) }

					<Button
						className="wordcamp-image__size-button-reset"
						isLarge
						isDefault
						onClick={ () => this.onChange( Number( initialPosition ) ) }
					>
						{ __( 'Reset', 'wordcamporg' ) }
					</Button>
				</div>

				<RangeControl
					className="wordcamp-image__size-range"
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

ImageSizeControl.propTypes = {
	label: PropTypes.string,
	help: PropTypes.string,
	value: PropTypes.number,
	sizePresets: PropTypes.arrayOf(
		PropTypes.shape( {
			name: PropTypes.string,
			shortName: PropTypes.string,
			size: PropTypes.number,
			slug: PropTypes.string,
		} )
	).isRequired,
	onChange: PropTypes.func.isRequired,
	initialPosition: PropTypes.number,
	rangeProps: PropTypes.shape( {
		max: PropTypes.number,
		min: PropTypes.number,
	} ),
};

export default ImageSizeControl;
