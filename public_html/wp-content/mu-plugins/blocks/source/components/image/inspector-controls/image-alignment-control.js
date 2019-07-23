/**
 * External dependencies
 */
import classnames from 'classnames';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { BaseControl, Toolbar } from '@wordpress/components';

/**
 * Component for a UI control for image alignment.
 *
 * @return {Element}
 */
function ImageAlignmentControl( {
	className,
	label,
	help,
	value,
	onChange,
	alignOptions,
} ) {
	return (
		<BaseControl className={ classnames( 'wordcamp-image__alignment', className ) } help={ help }>
			<span className="wordcamp-image__alignment-label">{ label }</span>
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

ImageAlignmentControl.propTypes = {
	className    : PropTypes.string,
	label        : PropTypes.string,
	help         : PropTypes.string,
	value        : PropTypes.string.isRequired,
	onChange     : PropTypes.func.isRequired,
	alignOptions : PropTypes.arrayOf(
		PropTypes.shape( {
			label : PropTypes.string,
			value : PropTypes.string,
		} )
	).isRequired,
};

export default ImageAlignmentControl;
