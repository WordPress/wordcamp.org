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
 * Component for an entity title, optionally linked.
 *
 * @param  root0
 * @param  root0.align
 * @param  root0.className
 * @param  root0.headingLevel
 * @param  root0.link
 * @param  root0.title
 * @return {Element}
 */
function ItemTitle( { align, className, headingLevel, link, title } ) {
	const validLevels = [ 1, 2, 3, 4, 5, 6 ];
	let Tag = 'h3';

	if ( validLevels.includes( headingLevel ) ) {
		Tag = 'h' + headingLevel;
	}

	const classes = [ 'wordcamp-block__item-title', className ];
	const content = title || __( '(Untitled)', 'wordcamporg' );

	const style = {};
	if ( align ) {
		style.textAlign = align;
	}

	return (
		<Tag className={ classnames( classes ) } style={ style }>
			{ link ? (
				<a href={ link } target="_blank" rel="noopener noreferrer">
					{ content }
				</a>
			) : (
				content
			) }
		</Tag>
	);
}

ItemTitle.propTypes = {
	headingLevel: PropTypes.number,
	className: PropTypes.string,
	title: PropTypes.string,
	link: PropTypes.string,
};

export default ItemTitle;
