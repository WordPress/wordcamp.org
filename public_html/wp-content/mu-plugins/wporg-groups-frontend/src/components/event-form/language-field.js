/**
 * The language an event is run in, shared by the event modal and the
 * group-settings Events tab.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function LanguageField( { languages, value, onChange, classPrefix } ) {
	const options = [
		// Not "Select a language": unlike the group's country this is genuinely
		// optional, and a required-sounding placeholder would push organizers
		// into answering a question they may not want to.
		{ label: __( '— Not specified —', 'wporg-groups-frontend' ), value: '' },
	].concat(
		( languages || [] ).map( ( language ) => ( {
			label: language.name,
			value: language.code,
		} ) )
	);

	return h(
		'div',
		{ className: `${ classPrefix }__field` },
		h( SelectControl, {
			label: __( 'Language', 'wporg-groups-frontend' ),
			help: __(
				'The language this event is held in. Attendees can filter the events list by it.',
				'wporg-groups-frontend'
			),
			value: value || '',
			options: options,
			onChange: onChange,
			__nextHasNoMarginBottom: true,
		} )
	);
}
