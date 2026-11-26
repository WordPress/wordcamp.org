/**
 * The people speaking at an event, picked from the group's members.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import PeopleField from './people-field';
import { MAX_SPEAKERS } from './hosts';

export default function SpeakersField( { value, onChange, classPrefix } ) {
	return h( PeopleField, {
		value,
		onChange,
		classPrefix,
		label: __( 'Speakers', 'wordcamporg' ),
		help: __( 'Listed under “Speakers” on the event page. Pick from the group’s members.', 'wordcamporg' ),
		maxLength: MAX_SPEAKERS,
		messages: {
			added: __( 'Speaker added.', 'wordcamporg' ),
			removed: __( 'Speaker removed.', 'wordcamporg' ),
			remove: __( 'Remove speaker', 'wordcamporg' ),
			/* translators: %d: the most speakers an event can have. */
			__experimentalInvalid: sprintf( __( 'Up to %d speakers.', 'wordcamporg' ), MAX_SPEAKERS ),
		},
	} );
}
