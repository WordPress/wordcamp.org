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
	{ value: 'MO', label: __( 'Mon', 'wordcamporg' ) },
	{ value: 'TU', label: __( 'Tue', 'wordcamporg' ) },
	{ value: 'WE', label: __( 'Wed', 'wordcamporg' ) },
	{ value: 'TH', label: __( 'Thu', 'wordcamporg' ) },
	{ value: 'FR', label: __( 'Fri', 'wordcamporg' ) },
	{ value: 'SA', label: __( 'Sat', 'wordcamporg' ) },
	{ value: 'SU', label: __( 'Sun', 'wordcamporg' ) },
];

const ORDERS = [
	{ value: 'first', label: __( 'First', 'wordcamporg' ) },
	{ value: 'second', label: __( 'Second', 'wordcamporg' ) },
	{ value: 'third', label: __( 'Third', 'wordcamporg' ) },
	{ value: 'fourth', label: __( 'Fourth', 'wordcamporg' ) },
	{ value: 'last', label: __( 'Last', 'wordcamporg' ) },
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

	return h( 'div', { className: 'wporg-event-recurrence' },
		locked && h( Notice, { status: 'info', isDismissible: false },
			__( 'The recurrence schedule is locked after publication.', 'wordcamporg' )
		),
		h( SelectControl, {
			label: __( 'Repeats', 'wordcamporg' ),
			value: value.frequency,
			disabled: locked,
			options: [
				{ label: __( 'Does not repeat', 'wordcamporg' ), value: '' },
				{ label: __( 'Weekly', 'wordcamporg' ), value: 'weekly' },
				{ label: __( 'Monthly', 'wordcamporg' ), value: 'monthly' },
				{ label: __( 'Yearly', 'wordcamporg' ), value: 'yearly' },
			],
			onChange: setFrequency,
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency && h( 'div', { className: 'wporg-event-recurrence__row' },
			h( TextControl, {
				label: __( 'Repeat every', 'wordcamporg' ),
				type: 'number', min: 1, value: value.interval, disabled: locked,
				onChange: ( interval ) => update( { interval: Math.max( 1, Number( interval ) || 1 ) } ),
				__nextHasNoMarginBottom: true,
			} ),
			h( 'span', { className: 'wporg-event-recurrence__unit components-checkbox-control__label' },
				value.frequency === 'weekly' ? __( 'week(s)', 'wordcamporg' ) :
				value.frequency === 'monthly' ? __( 'month(s)', 'wordcamporg' ) :
				__( 'year(s)', 'wordcamporg' )
			)
		),
		value.frequency === 'weekly' && h( 'div', {
			className: 'wporg-event-recurrence__weekdays',
			role: 'group',
			'aria-label': __( 'Repeat on', 'wordcamporg' ),
		},
			h( 'span', { className: 'wporg-event-recurrence__label' }, __( 'Repeat on', 'wordcamporg' ) ),
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
			label: __( 'Monthly pattern', 'wordcamporg' ),
			value: value.monthly_mode,
			disabled: locked,
			options: [
				{ label: sprintf( __( 'Day %d of the month', 'wordcamporg' ), value.monthly_day ), value: 'day' },
				{ label: __( 'Weekday pattern', 'wordcamporg' ), value: 'weekday' },
			],
			onChange: ( monthly_mode ) => update( { monthly_mode } ),
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency === 'monthly' && value.monthly_mode === 'weekday' && h( 'div', { className: 'wporg-event-recurrence__row' },
			h( SelectControl, {
				label: __( 'Order', 'wordcamporg' ), value: value.monthly_order, disabled: locked,
				options: ORDERS, onChange: ( monthly_order ) => update( { monthly_order } ),
				__nextHasNoMarginBottom: true,
			} ),
			h( SelectControl, {
				label: __( 'Weekday', 'wordcamporg' ), value: value.monthly_weekday, disabled: locked,
				options: WEEKDAYS, onChange: ( monthly_weekday ) => update( { monthly_weekday } ),
				__nextHasNoMarginBottom: true,
			} )
		),
		value.frequency && h( SelectControl, {
			label: __( 'Ends', 'wordcamporg' ),
			value: value.end_type,
			disabled: locked,
			options: [
				{ label: __( 'Never', 'wordcamporg' ), value: 'never' },
				{ label: __( 'On date', 'wordcamporg' ), value: 'until' },
				{ label: __( 'After occurrences', 'wordcamporg' ), value: 'count' },
			],
			onChange: ( end_type ) => update( { end_type } ),
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency && value.end_type === 'until' && h( TextControl, {
			label: __( 'End date', 'wordcamporg' ),
			type: 'date', min: eventDate, value: value.until, disabled: locked, required: ! locked,
			onChange: ( until ) => update( { until } ),
			__nextHasNoMarginBottom: true,
		} ),
		value.frequency && value.end_type === 'count' && h( TextControl, {
			label: __( 'Occurrences', 'wordcamporg' ),
			type: 'number', min: 1, value: value.count, disabled: locked,
			onChange: ( count ) => update( { count: Math.max( 1, Number( count ) || 1 ) } ),
			__nextHasNoMarginBottom: true,
		} )
	);
}
