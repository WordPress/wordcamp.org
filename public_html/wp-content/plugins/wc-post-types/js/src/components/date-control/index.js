/**
 * WordPress dependencies
 */
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- Date settings OK.
import { __experimentalGetSettings, dateI18n } from '@wordpress/date';
import { BaseControl, Button, DateTimePicker, Dropdown } from '@wordpress/components';

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
	const formattedDate = date && dateI18n( `${ settings.formats.date } ${ settings.formats.time }`, date );

	return (
		<BaseControl>
			<BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel>
			<Dropdown
				position="bottom left"
				renderToggle={ ( { onToggle, isOpen } ) => (
					<Button onClick={ onToggle } aria-expanded={ isOpen } isLink>
						{ formattedDate }
					</Button>
				) }
				renderContent={ () => (
					<DateTimePicker currentDate={ date } onChange={ onChange } is12Hour={ is12HourTime } />
				) }
			/>
		</BaseControl>
	);
}
