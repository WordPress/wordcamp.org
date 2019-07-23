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
 * @param {Object} props {
 *     @type {number}   grid_columns
 *     @type {Object}   schema
 *     @type {Function} setAttributes
 * }
 *
 * @return {Element}
 */
export default function GridColumnsControl( {
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
