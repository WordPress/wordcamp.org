/**
 * WordPress dependencies
 */
import { gmdate } from '@wordpress/date';
import { Fragment } from '@wordpress/element';
// eslint-disable-next-line
import { _x } from '@wordpress/i18n';

// todo remove all of the eslint-disable lines that were added for #356

/**
 * Internal dependencies
 */
import { NoContent } from '../../components/';

/**
 * Render the schedule for a specific day.
 *
 * @param {Object} props
 * @param {string} props.date In a format acceptable by `wp.date.gmdate()`.
 * @param {Array}  props.sessions
 * @param {Array}  props.tracks
 * @param {string} props.dateFormat
 * @param {string} props.timeFormat
 *
 * @return {Element}
 */
/* eslint-disable-next-line */
function ScheduleDay( { date, sessions, tracks, dateFormat, timeFormat } ) {
	return (
		<Fragment>
			<h2 className="wordcamp-schedule__date">
				{ gmdate( dateFormat, date ) }
			</h2>
			{ /* todo this needs to be editable, should also be a separate Heading block. so when inserting a schedule
			 block, We can make the text editable, though, with a reasonable default. If they remove the text,
			then we can automatically remove the corresponding h2 tag, to avoid leaving an artifact behind that
			 affects margins/etc. */ }

			<section id={ `wordcamp-schedule__day-${ gmdate( 'Y-m-d', date ) }` } className="wordcamp-schedule__day">
				todo
			</section>
		</Fragment>
	);
}

/**
 * Render the schedule.
 *
 * @param {Object} props
 * @param {Object} props.entities
 *
 * @return {Element}
 */
export function ScheduleGrid( { entities } ) {
	const { sessions, settings } = entities;
	const isLoading = ! Array.isArray( sessions );
	const hasSessions = ! isLoading && sessions.length > 0;

	if ( isLoading || ! hasSessions || ! settings ) {
		return (
			<NoContent loading={ isLoading } />
		);
	}

	const { date_format, time_format } = settings;
	const scheduleDays = [];
	const date = '2020-01-01';

	/* eslint-disable */
	// for each day
		scheduleDays.push(
			<ScheduleDay
				key={ date }
				date={ date }
				sessions={ [] }
				tracks={ [] }
				dateFormat={ date_format }
				timeFormat={ time_format }
			/>
		);
	/* eslint-enable */

	return (
		<div className="wordcamp-schedule">
			{ scheduleDays }
		</div>
	);
}
