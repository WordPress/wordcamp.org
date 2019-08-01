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
 *     @type {node}   appender
 *     @type {string} className
 *     @type {node}   content
 * }
 *
 * @return {Element}
 */
function EditAppender( { className, content, appender } ) {
	const classes = [ 'wordcamp-edit-appender', 'has-specific-mode', className ];

	return (
		<Fragment>
			{ content }
			{ appender && (
				<div className={ classnames( classes ) }>
					{ appender }
				</div>
			) }
		</Fragment>
	);
}

export default EditAppender;
