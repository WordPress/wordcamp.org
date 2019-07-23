/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { RangeControl } from '@wordpress/components';
import { __ }           from '@wordpress/i18n';

/**
 * Component for a range control that adjusts the number of columns in a post list grid.
 *
 * For use within a block, consider GridInspectorPanel instead unless you want to include this control
 * in a panel with other unrelated controls.
 *
 * @return {Element}
 */
function GridColumnsControl( {
	grid_columns,
	schema,
	setAttributes,
} ) {
	const { default: defaultValue = 2, maximum = 4, minimum = 4 } = schema;

	return (
		<RangeControl
			label={ __( 'Grid Columns', 'wordcamporg' ) }
			value={ Number( grid_columns ) }
			min={ minimum }
			max={ maximum }
			initialPosition={ defaultValue }
			onChange={ ( value ) => setAttributes( { grid_columns: value } ) }
		/>
	);
}

GridColumnsControl.propTypes = {
	grid_columns : PropTypes.number.isRequired,
	schema       : PropTypes.shape( {
		default : PropTypes.number,
		maximum : PropTypes.number,
		minimum : PropTypes.number,
	} ).isRequired,
	setAttributes: PropTypes.func.isRequired,
};

export default GridColumnsControl;
