/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { BaseControl, Toolbar } from '@wordpress/components';

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
export default function ImageAlignmentControl( {
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
