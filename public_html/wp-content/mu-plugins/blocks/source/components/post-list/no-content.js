/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * Component for indicating why there is no content.
 *
 * @return {Element}
 */
function NoContent( { loading } ) {
	return (
		<div className="wordcamp-post-list has-no-content">
			{ loading ? <Spinner /> : __( 'No content found.', 'wordcamporg' ) }
		</div>
	);
}

NoContent.propTypes = {
	loading: PropTypes.bool,
};

export default NoContent;
