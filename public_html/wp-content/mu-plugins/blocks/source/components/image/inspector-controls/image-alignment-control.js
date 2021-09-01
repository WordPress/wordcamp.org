/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { BaseControl, Toolbar } from '@wordpress/components';

/**
 * Component for a UI control for image alignment.
 *
 * @param  root0
 * @param  root0.label
 * @param  root0.help
 * @param  root0.value
 * @param  root0.onChange
 * @param  root0.alignOptions
 * @return {Element}
 */
function ImageAlignmentControl( { label, help, value, onChange, alignOptions } ) {
	return (
		<BaseControl className="wordcamp-image__alignment" help={ help }>
			<span className="wordcamp-image__alignment-label">{ label }</span>
			<Toolbar
				controls={ alignOptions.map( ( alignment ) => {
					const isActive = value === alignment.value;
					const iconSlug = `align-${ alignment.value }`;

					return {
						title: alignment.label,
						icon: iconSlug,
						isActive: isActive,
						onClick: () => {
							onChange( alignment.value );
						},
					};
				} ) }
			/>
		</BaseControl>
	);
}

ImageAlignmentControl.propTypes = {
	label: PropTypes.string,
	help: PropTypes.string,
	value: PropTypes.string.isRequired,
	onChange: PropTypes.func.isRequired,
	alignOptions: PropTypes.arrayOf(
		PropTypes.shape( {
			label: PropTypes.string,
			value: PropTypes.string,
		} )
	).isRequired,
};

export default ImageAlignmentControl;
