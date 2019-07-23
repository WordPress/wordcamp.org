/**
 * External dependencies
 */
import { get }   from 'lodash';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { tokenSplit, arrayTokenReplace, listify } from '../../../i18n';

/**
 * Component for the section of each session post that displays information about the session's speakers.
 *
 * @param {Object} session
 *
 * @return {Element}
 */
function SessionSpeakers( { session } ) {
	let speakerData = get( session, '_embedded.speakers', [] );

	if ( speakerData.length === 0 ) {
		return null;
	}

	speakerData = speakerData.map( ( speaker ) => {
		if ( speaker.hasOwnProperty( 'code' ) ) {
			// The wporg username given for this speaker returned an error.
			return null;
		}

		const { link }     = speaker;
		let { title = {} } = speaker;

		title = title.rendered.trim() || __( 'Unnamed', 'wordcamporg' );

		if ( ! link ) {
			return title;
		}

		return (
			<a key={ link } href={ link } target="_blank" rel="noopener noreferrer">
				{ title }
			</a>
		);
	} );

	const speakers = arrayTokenReplace(
		/* translators: %s is a list of names. */
		tokenSplit( __( 'Presented by %s', 'wordcamporg' ) ),
		[ listify( speakerData ) ]
	);

	return (
		<p className="wordcamp-sessions__speakers">
			{ speakers }
		</p>
	);
}

SessionSpeakers.propTypes = {
	session: PropTypes.object.isRequired,
};

export default SessionSpeakers;
