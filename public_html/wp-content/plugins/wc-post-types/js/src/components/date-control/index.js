/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- Date settings OK.
import { __experimentalGetSettings, dateI18n } from '@wordpress/date';
import { BaseControl, TimePicker } from '@wordpress/components';
import { sprintf } from '@wordpress/i18n';

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
	const settings = __experimentalGetSettings();
	const is12HourTime = /a(?!\\)/i.test(
		settings.formats.time
			.toLowerCase() // Test only the lower case a
			.replace( /\\\\/g, '' ) // Replace "//" with empty strings
			.split( '' )
			.reverse()
			.join( '' ) // Reverse the string and test for "a" not followed by a slash
	);

	// Remove the timezone info from the date. `TimePicker` uses an instance of moment that does not know about
	// the site timezone, so passing it in causes an unexpected offset.
	const dateNoTZ = dateI18n( 'Y-m-d\\TH:i:s', date, 'WP' );

	return (
		<BaseControl>
			<BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel>
			<TimePicker
				currentTime={ dateNoTZ }
				onChange={ ( dateValue ) => {
					// dateValue is a tz-less string, so we need to add the site-timezone offset.
					// Otherwise, `dateI18n` tries to use the browser timezone, which might not be the same.
					const offset = getTimezoneOffset( settings.timezone );
					const value = dateI18n( 'U', dateValue + offset );
					onChange( value );
				} }
				is12Hour={ is12HourTime }
			/>
		</BaseControl>
	);
}
