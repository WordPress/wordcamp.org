/**
 * External dependencies
 */
import Select, { components } from 'react-select';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { BaseControl, Button, ButtonGroup, RangeControl } = wp.components;

function VersatileSelect( {
	className,
	label,
	help,
	value,
	instanceId,
	onChange,
	...props
} ) {
	const id = `wordcamp-block-versatile-select-control-${ instanceId }`;

	return (
		<BaseControl
			id={ id }
			className={ classnames( 'wordcamp-components-image-alignment', className ) }
			label={ label }
			help={ help }
		>

		</BaseControl>
	);
}

export default VersatileSelect;
