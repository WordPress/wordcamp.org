/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- Date settings OK.
import { __experimentalGetSettings } from '@wordpress/date';
import { BaseControl, TimePicker } from '@wordpress/components';

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

	return (
		<BaseControl>
			<BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel>
			<TimePicker currentTime={ date } onChange={ onChange } is12Hour={ is12HourTime } />
		</BaseControl>
	);
}
