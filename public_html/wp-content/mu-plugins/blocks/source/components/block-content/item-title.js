/**
 * External dependencies
 */
import classnames from 'classnames';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ }       from '@wordpress/i18n';
import { Disabled } from '@wordpress/components';
import { Fragment } from '@wordpress/element';

/**
 * Component for an entity title, optionally linked.
 *
 * @return {Element}
 */
function ItemTitle( { headingLevel, className, title, link } ) {
	const validLevels = [ 1, 2, 3, 4, 5, 6 ];
	let Tag = 'h3';

	if ( validLevels.includes( headingLevel ) ) {
		Tag = 'h' + headingLevel;
	}

	const classes = [ 'wordcamp-block__post-title', className ];

	const content = title || __( '(Untitled)', 'wordcamporg' );

	return (
		<Tag className={ classnames( classes ) }>
			{ link &&
				<Disabled>
					<a href={ link }>
						{ content }
					</a>
				</Disabled>
			}

			{ ! link &&
				<Fragment>
					{ content }
				</Fragment>
			}
		</Tag>
	);
}

ItemTitle.propTypes = {
	headingLevel : PropTypes.number,
	className    : PropTypes.string,
	title        : PropTypes.string,
	link         : PropTypes.string,
};

export default ItemTitle;
