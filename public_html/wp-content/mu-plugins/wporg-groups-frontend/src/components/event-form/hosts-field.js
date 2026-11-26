/**
 * The people hosting an event, picked from the group's members.
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
import { MAX_HOSTS } from './hosts';

export default function HostsField( { value, onChange, classPrefix } ) {
	return h( PeopleField, {
		value,
		onChange,
		classPrefix,
		label: __( 'Hosts', 'wordcamporg' ),
		help: __( 'Shown as “Hosted by” on the event page. Pick from the group’s members.', 'wordcamporg' ),
		maxLength: MAX_HOSTS,
		messages: {
			added: __( 'Host added.', 'wordcamporg' ),
			removed: __( 'Host removed.', 'wordcamporg' ),
			remove: __( 'Remove host', 'wordcamporg' ),
			/* translators: %d: the most hosts an event can have. */
			__experimentalInvalid: sprintf( __( 'Up to %d hosts.', 'wordcamporg' ), MAX_HOSTS ),
		},
	} );
}
