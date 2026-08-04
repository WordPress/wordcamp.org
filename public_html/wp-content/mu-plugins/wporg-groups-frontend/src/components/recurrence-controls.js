/**
 * Shared recurrence controls for frontend event forms.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { createElement as h } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const WEEKDAYS = [
	{ value: 'MO', label: __( 'Mon', 'wporg-groups-frontend' ) },
	{ value: 'TU', label: __( 'Tue', 'wporg-groups-frontend' ) },
	{ value: 'WE', label: __( 'Wed', 'wporg-groups-frontend' ) },
	{ value: 'TH', label: __( 'Thu', 'wporg-groups-frontend' ) },
	{ value: 'FR', label: __( 'Fri', 'wporg-groups-frontend' ) },
	{ value: 'SA', label: __( 'Sat', 'wporg-groups-frontend' ) },
	{ value: 'SU', label: __( 'Sun', 'wporg-groups-frontend' ) },
];

const ORDERS = [
	{ value: 'first', label: __( 'First', 'wporg-groups-frontend' ) },
	{ value: 'second', label: __( 'Second', 'wporg-groups-frontend' ) },
	{ value: 'third', label: __( 'Third', 'wporg-groups-frontend' ) },
	{ value: 'fourth', label: __( 'Fourth', 'wporg-groups-frontend' ) },
	{ value: 'last', label: __( 'Last', 'wporg-groups-frontend' ) },
];

/**
 * Normalizes recurrence data returned by the form-data endpoint.
 *
 * @param {Object|null} value Recurrence data.
 * @return {Object|null} Normalized recurrence data, or null when unavailable.
 */
export function normalizeRecurrence( value ) {
	if ( ! value || ! value.available ) {
		return null;
	}

	return {
		available: true,
		locked: !! value.locked,
		frequency: value.frequency || '',
		interval: Math.max( 1, Number( value.interval ) || 1 ),
		weekdays: Array.isArray( value.weekdays ) ? value.weekdays : [],
		monthly_mode: value.monthly_mode || 'day',
		monthly_day: Math.min( 31, Math.max( 1, Number( value.monthly_day ) || 1 ) ),
		monthly_order: value.monthly_order || 'first',
		monthly_weekday: value.monthly_weekday || 'MO',
		end_type: value.end_type || 'never',
		until: value.until || '',
		count: Math.max( 1, Number( value.count ) || 12 ),
	};
}

/**
 * Returns recurrence defaults derived from the event date.
 *
 * @param {string} date Event date in YYYY-MM-DD format.
 * @return {Object} Date-derived recurrence fields.
 */
function defaultsForDate( date ) {
	const parsed = date ? new Date( `${ date }T12:00:00` ) : null;
	const valid = parsed && ! Number.isNaN( parsed.getTime() );
	const weekday = valid ? [ 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ][ parsed.getDay() ] : 'MO';
	const day = valid ? parsed.getDate() : 1;
	const orderIndex = Math.min( 3, Math.max( 0, Math.ceil( day / 7 ) - 1 ) );

	return {
		weekdays: [ weekday ],
		monthly_day: day,
		monthly_order: day > 28 ? 'last' : ORDERS[ orderIndex ].value,
		monthly_weekday: weekday,
	};
}

/**
 * Renders Google Calendar-style recurrence fields.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.value     Recurrence value.
 * @param {string}   props.eventDate Event start date.
 * @param {Function} props.onChange  Value change callback.
 * @return {Element|null} Recurrence controls.
 */
export default function RecurrenceControls( { value, eventDate, onChange } ) {
	if ( ! value || ! value.available ) {
		return null;
	}

	const locked = !! value.locked;
	const update = ( fields ) => onChange( { ...value, ...fields } );
	const setFrequency = ( frequency ) => {
		const defaults = defaultsForDate( eventDate );
		update( {
			frequency,
			...( frequency === 'weekly' && value.weekdays.length === 0 ? { weekdays: defaults.weekdays } : {} ),
			...( frequency === 'monthly' ? {
				monthly_day: defaults.monthly_day,
				monthly_order: defaults.monthly_order,
				monthly_weekday: defaults.monthly_weekday,
			} : {} ),
		} );
	};

	return h( 'fieldset', { className: 'wporg-event-recurrence' },
		h( 'legend', {}, __( 'Repeating event', 'wporg-groups-frontend' ) ),
		locked && h( Notice, { status: 'info', isDismissible: false },
			__( 'The recurrence schedule is locked after publication.', 'wporg-groups-frontend' )
		),
		h( SelectControl, {
			label: __( 'Repeats', 'wporg-groups-frontend' ),
			value: value.frequency,
			disabled: locked,
			options: [
				{ label: __( 'Does not repeat', 'wporg-groups-frontend' ), value: '' },
				{ label: __( 'Weekly', 'wporg-groups-frontend' ), value: 'weekly' },
				{ label: __( 'Monthly', 'wporg-groups-frontend' ), value: 'monthly' },
				{ label: __( 'Yearly', 'wporg-groups-frontend' ), value: 'yearly' },
			],
			onChange: setFrequency,
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency && h( 'div', { className: 'wporg-event-recurrence__row' },
			h( TextControl, {
				label: __( 'Repeat every', 'wporg-groups-frontend' ),
				type: 'number', min: 1, value: value.interval, disabled: locked,
				onChange: ( interval ) => update( { interval: Math.max( 1, Number( interval ) || 1 ) } ),
				__nextHasNoMarginBottom: true,
			} ),
			h( 'span', { className: 'wporg-event-recurrence__unit' },
				value.frequency === 'weekly' ? __( 'week(s)', 'wporg-groups-frontend' ) :
				value.frequency === 'monthly' ? __( 'month(s)', 'wporg-groups-frontend' ) :
				__( 'year(s)', 'wporg-groups-frontend' )
			)
		),
		value.frequency === 'weekly' && h( 'div', { className: 'wporg-event-recurrence__weekdays' },
			h( 'span', {}, __( 'Repeat on', 'wporg-groups-frontend' ) ),
			...WEEKDAYS.map( ( day ) => h( CheckboxControl, {
				key: day.value,
				label: day.label,
				checked: value.weekdays.includes( day.value ),
				disabled: locked,
				onChange: ( checked ) => update( {
					weekdays: checked ? [ ...value.weekdays, day.value ] : value.weekdays.filter( ( item ) => item !== day.value ),
				} ),
				__nextHasNoMarginBottom: true,
			} ) )
		),
		value.frequency === 'monthly' && h( SelectControl, {
			label: __( 'Monthly pattern', 'wporg-groups-frontend' ),
			value: value.monthly_mode,
			disabled: locked,
			options: [
				{ label: sprintf( __( 'Day %d of the month', 'wporg-groups-frontend' ), value.monthly_day ), value: 'day' },
				{ label: __( 'Weekday pattern', 'wporg-groups-frontend' ), value: 'weekday' },
			],
			onChange: ( monthly_mode ) => update( { monthly_mode } ),
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency === 'monthly' && value.monthly_mode === 'weekday' && h( 'div', { className: 'wporg-event-recurrence__row' },
			h( SelectControl, {
				label: __( 'Order', 'wporg-groups-frontend' ), value: value.monthly_order, disabled: locked,
				options: ORDERS, onChange: ( monthly_order ) => update( { monthly_order } ),
				__nextHasNoMarginBottom: true,
			} ),
			h( SelectControl, {
				label: __( 'Weekday', 'wporg-groups-frontend' ), value: value.monthly_weekday, disabled: locked,
				options: WEEKDAYS, onChange: ( monthly_weekday ) => update( { monthly_weekday } ),
				__nextHasNoMarginBottom: true,
			} )
		),
		value.frequency && h( SelectControl, {
			label: __( 'Ends', 'wporg-groups-frontend' ),
			value: value.end_type,
			disabled: locked,
			options: [
				{ label: __( 'Never', 'wporg-groups-frontend' ), value: 'never' },
				{ label: __( 'On date', 'wporg-groups-frontend' ), value: 'until' },
				{ label: __( 'After occurrences', 'wporg-groups-frontend' ), value: 'count' },
			],
			onChange: ( end_type ) => update( { end_type } ),
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency && value.end_type === 'until' && h( TextControl, {
			label: __( 'End date', 'wporg-groups-frontend' ),
			type: 'date', min: eventDate, value: value.until, disabled: locked, required: ! locked,
			onChange: ( until ) => update( { until } ),
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency && value.end_type === 'count' && h( TextControl, {
			label: __( 'Occurrences', 'wporg-groups-frontend' ),
			type: 'number', min: 1, value: value.count, disabled: locked,
			onChange: ( count ) => update( { count: Math.max( 1, Number( count ) || 1 ) } ),
			__nextHasNoMarginBottom: true,
		} )
	);
}
