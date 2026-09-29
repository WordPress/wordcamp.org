/**
 * The topics an event covers, shared by the event modal and the
 * group-settings Events tab.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h } from '@wordpress/element';
import { FormTokenField } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import normalizeTopics, { MAX_TOPICS } from './normalize-topics';

export default function TopicsField( { suggestions, value, onChange, classPrefix } ) {
	return h(
		'div',
		{ className: `${ classPrefix }__field` },
		h( FormTokenField, {
			label: __( 'Topics', 'wordcamporg' ),
			value: value || [],
			suggestions: suggestions || [],
			maxLength: MAX_TOPICS,
			onChange: ( tokens ) => onChange( normalizeTopics( tokens ) ),
			__experimentalShowHowTo: false,
			__nextHasNoMarginBottom: true,
		} ),
		h(
			'p',
			{ className: 'components-form-token-field__help' },
			sprintf(
				/* translators: %d: the most topics an event can have. */
				__(
					'Up to %d. Press Enter or type a comma after each one. Attendees can follow a topic to the group’s other events on it.',
					'wordcamporg'
				),
				MAX_TOPICS
			)
		)
	);
}
