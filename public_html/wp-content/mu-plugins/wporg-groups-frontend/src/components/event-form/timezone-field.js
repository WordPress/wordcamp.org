/**
 * The timezone an event is scheduled in, shared by the event modal and the
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

export default function TimezoneField( { timezones, value, onChange, classPrefix } ) {
	const groups = Object.keys( timezones || {} );

	// The list arrives grouped ("Australia" > "Brisbane"), and a flat select of
	// 400-odd identifiers is unreadable, so render optgroups. SelectControl
	// renders `children` in place of its own `options` when both could apply.
	const children = groups.map( ( group ) =>
		h(
			'optgroup',
			{ key: group, label: group },
			Object.keys( timezones[ group ] ).map( ( zone ) =>
				h( 'option', { key: zone, value: zone }, timezones[ group ][ zone ] )
			)
		)
	);

	return h(
		'div',
		{ className: `${ classPrefix }__field` },
		h(
			SelectControl,
			{
				label: __( 'Time zone', 'wporg-groups-frontend' ),
				help: __(
					'The start and end times above are in this zone.',
					'wporg-groups-frontend'
				),
				value: value || '',
				onChange: onChange,
				__nextHasNoMarginBottom: true,
			},
			children
		)
	);
}
