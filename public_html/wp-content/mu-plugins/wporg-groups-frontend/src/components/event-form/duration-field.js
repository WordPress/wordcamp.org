/**
 * Duration preset select with a custom end-time fallback, shared by the
 * event modal and the group-settings Events tab.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h, useState } from '@wordpress/element';
import { TextControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const DURATION_OPTIONS = [
	{ label: __( '30 minutes', 'wporg-groups-frontend' ), value: '30' },
	{ label: __( '1 hour', 'wporg-groups-frontend' ), value: '60' },
	{ label: __( '1.5 hours', 'wporg-groups-frontend' ), value: '90' },
	{ label: __( '2 hours', 'wporg-groups-frontend' ), value: '120' },
	{ label: __( '2.5 hours', 'wporg-groups-frontend' ), value: '150' },
	{ label: __( '3 hours', 'wporg-groups-frontend' ), value: '180' },
	{ label: __( 'Custom', 'wporg-groups-frontend' ), value: 'custom' },
];

function addMinutesToTime( time, minutes ) {
	if ( ! time ) {
		return '';
	}
	const [ h, m ] = time.split( ':' ).map( Number );
	const total = h * 60 + m + minutes;
	const newH = Math.floor( total / 60 ) % 24;
	const newM = total % 60;
	return String( newH ).padStart( 2, '0' ) + ':' + String( newM ).padStart( 2, '0' );
}

function getMinutesBetween( start, end ) {
	if ( ! start || ! end ) {
		return 0;
	}
	const [ sh, sm ] = start.split( ':' ).map( Number );
	const [ eh, em ] = end.split( ':' ).map( Number );
	let diff = ( eh * 60 + em ) - ( sh * 60 + sm );
	if ( diff < 0 ) {
		diff += 24 * 60;
	}
	return diff;
}

export default function DurationField( { timeStart, timeEnd, onChange, classPrefix } ) {
	const minutes = getMinutesBetween( timeStart, timeEnd );
	const knownDuration = DURATION_OPTIONS.find( ( o ) => o.value !== 'custom' && Number( o.value ) === minutes );
	const [ isCustom, setIsCustom ] = useState( ! knownDuration && !! timeEnd );

	const selectedValue = isCustom ? 'custom' : ( knownDuration ? String( minutes ) : '' );

	return h( 'div', { className: `${ classPrefix }__field` },
		h( SelectControl, {
			label: __( 'Duration', 'wporg-groups-frontend' ),
			value: selectedValue,
			options: [ { label: __( '— Select —', 'wporg-groups-frontend' ), value: '' } ].concat( DURATION_OPTIONS ),
			onChange: ( v ) => {
				if ( v === 'custom' ) {
					setIsCustom( true );
				} else if ( v ) {
					setIsCustom( false );
					onChange( addMinutesToTime( timeStart, Number( v ) ) );
				}
			},
			__nextHasNoMarginBottom: true,
		} ),
		isCustom && h( TextControl, {
			label: __( 'End time', 'wporg-groups-frontend' ),
			type: 'time',
			value: timeEnd,
			onChange: onChange,
			required: true,
			__nextHasNoMarginBottom: true,
		} )
	);
}
