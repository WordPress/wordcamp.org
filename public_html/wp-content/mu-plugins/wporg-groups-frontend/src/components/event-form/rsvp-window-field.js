/**
 * When an event's RSVPs open and close (#2397), shared by the event modal and
 * the group-settings Events tab.
 *
 * Both are a time before the event starts, so one setting works for every
 * date of a recurring event.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h, Fragment } from '@wordpress/element';
import { BaseControl, TextControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { toSeconds } from './rsvp-window-time';

const UNITS = [
	{ value: 'weeks', label: __( 'weeks before it starts', 'wordcamporg' ) },
	{ value: 'days', label: __( 'days before it starts', 'wordcamporg' ) },
	{ value: 'hours', label: __( 'hours before it starts', 'wordcamporg' ) },
];

function WindowRow( { id, label, unitLabel, help, value, onChange, min, classPrefix } ) {

	return h(
		BaseControl,
		{ id, help, className: `${ classPrefix }__field`, __nextHasNoMarginBottom: true },
		h(
			'div',
			{ className: `${ classPrefix }__rsvp-window-row` },
			h( TextControl, {
				label,
				id,
				'aria-describedby': `${ id }__help`,
				type: 'number',
				min,
				step: 1,
				value: value.amount,
				onChange: ( amount ) => onChange( { ...value, amount } ),
				__nextHasNoMarginBottom: true,
			} ),
			h( SelectControl, {
				label: unitLabel,
				hideLabelFromVision: true,
				value: value.unit,
				options: UNITS,
				onChange: ( unit ) => onChange( { ...value, unit } ),
				__nextHasNoMarginBottom: true,
			} )
		)
	);
}

export default function RsvpWindowField( { opens, closes, onChange, classPrefix } ) {
	const opensAt = toSeconds( opens );
	const closesAt = toSeconds( closes );
	const isInvalid = opensAt !== null && closesAt !== null && opensAt <= closesAt;

	return h(
		Fragment,
		null,
		h( WindowRow, {
			id: `${ classPrefix }-rsvp-opens`,
			label: __( 'RSVPs open', 'wordcamporg' ),
			unitLabel: __( 'Unit for when RSVPs open', 'wordcamporg' ),
			help: __( 'Leave empty to take RSVPs as soon as the event is published.', 'wordcamporg' ),
			value: opens,
			min: 1,
			onChange: ( value ) => onChange( 'rsvp_opens', value ),
			classPrefix,
		} ),
		h( WindowRow, {
			id: `${ classPrefix }-rsvp-closes`,
			label: __( 'RSVPs close', 'wordcamporg' ),
			unitLabel: __( 'Unit for when RSVPs close', 'wordcamporg' ),
			help: __( 'Leave empty to take RSVPs until the event ends. Anyone who has RSVPed can still cancel.', 'wordcamporg' ),
			value: closes,
			min: 0,
			onChange: ( value ) => onChange( 'rsvp_closes', value ),
			classPrefix,
		} ),
		isInvalid &&
			h(
				'p',
				{ className: `${ classPrefix }__rsvp-window-error`, role: 'alert' },
				__( 'RSVPs have to open before they close.', 'wordcamporg' )
			)
	);
}
