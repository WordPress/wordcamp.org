/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * Component for block controls when the block has a specific mode selected.
 *
 * @param {Object} props {
 *     @type {string} className
 *     @type {string} label
 *     @type {string} icon
 *     @type {node}   content
 *     @type {node}   placeholderChildren
 * }
 *
 * @return {Element}
 */
export function PlaceholderSpecificMode( { className, content, placeholderChildren } ) {
	const classes = [ 'wordcamp-block__edit-appender', 'has-specific-mode', className ];

	return (
		<Fragment>
			{ content }
			{ placeholderChildren && (
				<div className={ classnames( classes ) }>
					{ placeholderChildren }
				</div>
			) }
		</Fragment>
	);
}
