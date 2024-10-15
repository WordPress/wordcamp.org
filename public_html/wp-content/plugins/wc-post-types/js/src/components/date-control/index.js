/**
 * External dependencies
 */
import { format } from 'date-fns';
import { TZDate } from '@date-fns/tz';

/**
 * WordPress dependencies
 */
import { getSettings } from '@wordpress/date';
import { BaseControl, TimePicker } from '@wordpress/components';
import { sprintf } from '@wordpress/i18n';

const TIMEZONELESS_FORMAT = "yyyy-MM-dd'T'HH:mm:ss";

/**
 * Using the site settings, generate the offset in ISO 8601 format (`+00:00`).
 *
 * For example, an offset of `-6` will return `'-06:00'`, and `'13.75'` will return `'+13:45'`.
 *
 * @param {Object}        root0        The site timezone settings.
 * @param {string|number} root0.offset The offset in hours, as a string if it's fractional.
 * @return {string}
 */
function getTimezoneOffset( { offset = 0 } ) {
	return sprintf(
		'%1$s%2$02d:%3$02d',
		Number( offset ) < 0 ? '-' : '+',
		Math.floor( Math.abs( offset ) ),
		( Math.abs( offset ) % 1 ) * 60
	);
}

export default function( { date, label, onChange } ) {
	const settings = getSettings();
	const is12HourTime = /a(?!\\)/i.test(
		settings.formats.time
			.toLowerCase() // Test only the lower case a
			.replace( /\\\\/g, '' ) // Replace "//" with empty strings
			.split( '' )
			.reverse()
			.join( '' ) // Reverse the string and test for "a" not followed by a slash
	);

	// The `TimePicker` component is timezone-agnostic, so `currentTime` should be a
	// date-time string without a timezone (but in the server timezone for display).

	// First get the server timezone from date settings. This could be a string
	// like "America/New_York" or an offset like "-1".
	let serverTimezone = settings.timezone.string;
	if ( ! serverTimezone ) {
		// If it's a offset, convert it to ISO 8601 format.
		serverTimezone = getTimezoneOffset( settings.timezone );
	}

	// Because this date is a timestamp, it's understood to be a UTC value, and
	// can be simply created with the correct timezone.
	const dateServerTZ = new TZDate( date, serverTimezone );

	return (
		<BaseControl>
			<BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel>
			<TimePicker
				currentTime={ format( dateServerTZ, TIMEZONELESS_FORMAT ) }
				onChange={ ( newDate ) => {
					// Parse the date with the server timezone. This will be an incorrect date, because
					// the date is first converted to the client timezone, then the server timezone data
					// is added. For example, if the TimePicker reads 9:00 UTC-8 (server timezone), but
					// the client timezone is UTC-5, when created, the timestamp for the date will be
					// 9:00 UTC-5. Instead, we should create a new date with the server timezone appended,
					// so that it will create the correct UTC timestamp.
					// This `_date` is only used to get the timezone in the correct format.
					const _date = new TZDate( newDate, serverTimezone );

					// XXX returns the timezone (ISO-8601 w/ Z), e.g. -08:00.
					const timezone = format( _date, 'XXX' );

					// Now create a new date with the correct timezone.
					const newDateTZ = new Date( newDate + timezone );

					// Save value in seconds format for post meta.
					const value = format( newDateTZ, 't' );
					onChange( value );
				} }
				is12Hour={ is12HourTime }
			/>
		</BaseControl>
	);
}
