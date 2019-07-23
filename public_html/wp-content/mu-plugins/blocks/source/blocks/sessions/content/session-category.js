/**
 * External dependencies
 */
import { get }    from 'lodash';
import classnames from 'classnames';
import PropTypes  from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { intersperse } from '../../../i18n';

/**
 * Component for the section of each session post that displays a session's assigned categories.
 *
 * @param {Object} session
 *
 * @return {Element}
 */
function SessionCategory( { session } ) {
	let categoryContent;
	const terms = get( session, '_embedded[\'wp:term\']', [] ).flat();

	if ( session.session_category.length ) {
		/* translators: used between list items, there is a space after the comma */
		const separator = __( ', ', 'wordcamporg' );
		const categories = terms
			.filter( ( term ) => {
				return 'wcb_session_category' === term.taxonomy;
			} )
			.map( ( term ) => {
				return (
					<span
						key={ term.slug }
						className={ classnames( 'wordcamp-sessions__category', `slug-${ term.slug }` ) }
					>
						{ term.name.trim() }
					</span>
				);
			} );

		categoryContent = intersperse( categories, separator );
	}

	return (
		<div className="wordcamp-sessions__categories">
			{ categoryContent }
		</div>
	);
}

SessionCategory.propTypes = {
	session: PropTypes.object.isRequired,
};

export default SessionCategory;
