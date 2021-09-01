/**
 * External dependencies
 */
import classnames from 'classnames';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { Disabled } from '@wordpress/components';
import { RawHTML } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ItemPermalink from './item-permalink';

/**
 * Component for an entity's content, with an optional permalink appended.
 *
 * DO NOT use this to output untrusted content. Note that this takes a blob of arbitrary HTML as input,
 * and uses RawHTML (which uses dangerouslySetHTML) to render it in the node tree.
 *
 * @param  root0
 * @param  root0.className
 * @param  root0.content
 * @param  root0.link
 * @param  root0.linkText
 * @return {Element}
 */
function DangerousItemHTMLContent( { className, content, link, linkText } ) {
	const classes = [ 'wordcamp-block__item-content', className ];

	return (
		<div className={ classnames( classes ) }>
			<Disabled>
				<RawHTML children={ content } />
				{ link && <ItemPermalink link={ link } linkText={ linkText } /> }
			</Disabled>
		</div>
	);
}

DangerousItemHTMLContent.propTypes = {
	className: PropTypes.string,
	content: PropTypes.string,
	link: PropTypes.string,
	linkText: PropTypes.string,
};

export default DangerousItemHTMLContent;
