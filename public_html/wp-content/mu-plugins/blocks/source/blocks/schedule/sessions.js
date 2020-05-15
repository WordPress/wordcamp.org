/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { dateI18n } from '@wordpress/date';
import { useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Session } from './session';
import { ScheduleGridContext } from './edit';

/**
 * Render time headings and session groups.
 *
 * @param {Object} props
 * @param {Array}  props.sessions
 * @param {Array}  props.displayedTracks
 *
 * @return {Element}
 */
export function Sessions( { sessions, displayedTracks } ) {
	const overlappingSessionIds = getOverlappingSessionIds( sessions );
	const sessionsByTimeSlot = groupSessionsByTimeSlot( sessions );
	const timeGroups = [];
	const timeSlots = Object.keys( sessionsByTimeSlot ).sort();

	const { attributes, settings } = useContext( ScheduleGridContext );
	const { time_format: timeFormat } = settings;

	for ( let i = 0; i < timeSlots.length; i++ ) {
		const currentSlot = timeSlots[ i ];
		const startTime = parseInt( currentSlot );
		const endTime = parseInt( timeSlots[ i + 1 ] );

		const gridRow = `
			time-${ dateI18n( 'Hi', startTime ) } /
			time-${ dateI18n( 'Hi', endTime ) }
		`;

		const classes = classnames(
			'wordcamp-schedule__time-slot-header',
			sessionsByTimeSlot[ currentSlot ].length ? 'has-sessions' : 'is-empty'
		);

		timeGroups.push(
			<h3 key={ startTime } className={ classes } style={ { gridRow } }>
				{ dateI18n( timeFormat, startTime ) } - { ' ' }
				{ dateI18n( timeFormat, endTime ) }
			</h3>
		);

		for ( const session of sessionsByTimeSlot[ currentSlot ] ) {
			timeGroups.push(
				<Session
					key={ session.id }
					session={ session }
					displayedTracks={ displayedTracks }
					showCategories={ attributes.showCategories }
					overlapsAnother={ overlappingSessionIds.includes( session.id ) }
				/>
			);
		}
	}

	// Remove the last row, to avoid printing an empty slot at the end of the Grid schedule.
	const finalTimeSlot = timeSlots[ timeSlots.length - 1 ];

	if ( 0 === sessionsByTimeSlot[ finalTimeSlot ].length ) {
		timeGroups.pop();
	}

	return timeGroups;
}

/**
 * Determine which sessions overlap each other within a track.
 *
 * For example, Session A and Session B are both in track Foo. Session A ends at 9:30am, and Session B starts at
 * 9:15am.
 *
 * @param {Array} ungroupedSessions
 *
 * @return {Array}
 */
function getOverlappingSessionIds( ungroupedSessions ) {
	const overlappingIds = [];
	const groupedSessions = groupSessionsByTrack( ungroupedSessions );

	Object.entries( groupedSessions ).forEach( ( [ , sessions ] ) => {
		/*
		 * Avoid recalculating during each iteration. Subtract 1 to avoid going over the boundary when peek ahead
		 * to the next item.
		 */
		const loopCount = sessions.length - 1;

		for ( let iteration = 0; iteration < loopCount; iteration++ ) {
			if ( sessions[ iteration ].derived.endTime > sessions[ iteration + 1 ].derived.startTime ) {
				overlappingIds.push( sessions[ iteration ].id );
				overlappingIds.push( sessions[ iteration + 1 ].id );
			}
		}
	} );

	return overlappingIds;
}

/**
 * Group Sessions by their track(s).
 *
 * Sessions will be sorted within their track based on their `startTime`. Sessions with multiple tracks will
 * appear in the list for each of their tracks.
 *
 * @param {Array} ungroupedSessions
 *
 * @return {Object}
 */
function groupSessionsByTrack( ungroupedSessions ) {
	const groupedSessions = {};

	for ( const session of ungroupedSessions ) {
		for ( const track of session.derived.assignedTracks ) {
			groupedSessions[ track.id ] = groupedSessions[ track.id ] || [];

			groupedSessions[ track.id ].push( session );
		}
	}

	// Sort sessions within a track chronologically.
	Object.entries( groupedSessions ).forEach( ( [ trackId ] ) => {
		groupedSessions[ trackId ].sort( ( first, second ) => {
			// Skipping the == check because it doesn't matter which side that falls on.
			return first.derived.startTime < second.derived.startTime ? -1 : 1;
		} );
	} );

	return groupedSessions;
}

/**
 * Group sessions by their time slot.
 *
 * @param {Array} ungroupedSessions
 *
 * @return {Array}
 */
function groupSessionsByTimeSlot( ungroupedSessions ) {
	return ungroupedSessions.reduce( ( groups, session ) => {
		const { startTime, endTime } = session.derived;

		groups[ startTime ] = groups[ startTime ] || [];
		groups[ startTime ].push( session );

		/*
		 * Add row marker for end time.
		 *
		 * The end of one session may be the start of another session, but not necessarily. Without an explicit
		 * end line, the cell would extend beyond it's actual end time.
		 *
		 * This looks odd on some schedules, but that can be fixed/mitigated with CSS for those camps. The raw
		 * data/markup needs this, so that all camps have the flexibility of introducing chronological gaps
		 * between sessions within the same track.
		 */
		groups[ endTime ] = groups[ endTime ] || [];

		return groups;
	}, {} );
}
