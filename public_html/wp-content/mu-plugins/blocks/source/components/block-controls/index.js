/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Placeholder } from '@wordpress/components';
import { Fragment }    from '@wordpress/element';

/**
 * Component for block controls when the block has a specific mode selected.
 *
 * @param {Object} props {
 *     @type {string} className
 *     @type {string} label
 *     @type {string} icon
 *     @type {string} content
 *     @type {Array}  placeholderChildren
 * }
 *
 * @return {Element}
 */
export function PlaceholderSpecificMode( { className, label, icon, content, placeholderChildren } ) {
	const classes = [
		'wordcamp-block__edit-placeholder',
		'has-specific-mode',
		className,
	];

	return (
		<Fragment>
			{ content }
			<Placeholder
				className={ classnames( classes ) }
				label={ label }
				icon={ icon }
			>
				{ placeholderChildren }
			</Placeholder>
		</Fragment>
	);
}
