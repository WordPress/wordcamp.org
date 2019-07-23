/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Component for an entity's permalink.
 *
 * @param {Object} props {
 *     @type {string} className
 *     @type {string} link
 *     @type {string} linkText
 * }
 *
 * @return {Element}
 */
export default function ItemPermalink( { className, link, linkText } ) {
	const classes = [
		'wordcamp-block__post-permalink',
		className,
	];

	return (
		<p className={ classnames( classes ) }>
			<a href={ link }>
				{ linkText || __( 'Read more', 'wordcamporg' ) }
			</a>
		</p>
	);
}
