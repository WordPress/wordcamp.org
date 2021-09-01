/**
 * External dependencies
 */
import classnames from 'classnames';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Component for an entity's permalink.
 *
 * @param  root0
 * @param  root0.className
 * @param  root0.link
 * @param  root0.linkText
 * @return {Element}
 */
function ItemPermalink( { className, link, linkText } ) {
	const classes = [ 'wordcamp-block__item-permalink', className ];

	return (
		<p className={ classnames( classes ) }>
			<a href={ link } target="_blank" rel="noopener noreferrer">
				{ linkText || __( 'Read more', 'wordcamporg' ) }
			</a>
		</p>
	);
}

ItemPermalink.propTypes = {
	className: PropTypes.string,
	link: PropTypes.string,
	linkText: PropTypes.string,
};

export default ItemPermalink;
