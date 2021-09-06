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
 * @param {Array}   props
 * @param {boolean} props.loading
 * @param {string}  props.message Override the default message.
 * @return {Element}
 */
function NoContent( { loading, message } ) {
	if ( ! message ) {
		message = __( 'No content found.', 'wordcamporg' );
	}

	return (
		<div className="wordcamp-post-list has-no-content">
			{ loading ? <Spinner /> : message }
		</div>
	);
}

NoContent.propTypes = {
	loading: PropTypes.bool,
};

export default NoContent;
