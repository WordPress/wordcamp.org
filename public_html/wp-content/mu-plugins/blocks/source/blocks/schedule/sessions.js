/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { format } from '@wordpress/date';
import { useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Session } from './session';
import { ScheduleGridContext } from './schedule-grid';
import { sortBySlug } from './data';

/**
 * Render time headings and session groups.
 *
 * @param {Object} props
 * @param {Array}  props.sessions
 * @param {Array}  props.displayedTracks
 * @param {Array}  props.overlappingSessions
 * @return {Element}
 */
export function Sessions( { sessions, displayedTracks, overlappingSessions } ) {
	const { attributes, settings } = useContext( ScheduleGridContext );
	const { time_format: timeFormat } = settings;

	const sessionsByTimeSlot = groupSessionsByTimeSlot( sessions );
	const overlappingSessionIds = overlappingSessions.map( ( session ) => session.id );
	const timeGroups = [];
	const timeSlots = Object.keys( sessionsByTimeSlot ).sort();

	for ( let i = 0; i < timeSlots.length; i++ ) {
		const currentSlot = timeSlots[ i ];
		const startTime = parseInt( currentSlot );
		// If this is the last session, this value is NaN, which date-fns cannot handle.
		// Since this row is later removed (see below), fall back to any integer.
		const endTime = parseInt( timeSlots[ i + 1 ] ) || 0;

		const gridRow = `
			time-${ format( 'Hi', startTime ) } /
			time-${ format( 'Hi', endTime ) }
		`;

		const classes = classnames(
			'wordcamp-schedule__time-slot-header',
			sessionsByTimeSlot[ currentSlot ].length ? 'has-sessions' : 'is-empty'
		);

		timeGroups.push(
			<h3 key={ startTime } className={ classes } style={ { gridRow } }>
				{ format( timeFormat, startTime ) }
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
 * Group sessions by their time slot.
 *
 * @param {Array} ungroupedSessions
 * @return {Object}
 */
function groupSessionsByTimeSlot( ungroupedSessions ) {
	const groupedSessions = ungroupedSessions.reduce( ( groups, session ) => {
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

	Object.entries( groupedSessions ).forEach( ( [ , sessions ] ) => {
		/*
		 * Sorting by track makes sure that the sessions are shown in the same order in both the single-column
		 * layout, and the grid layout. The single-column layout shows them in source-order, while the grid
		 * places them onto lines irrespective of source-order.
		 *
		 * If the orders don't match, then partially-sighted users would have a disorienting UX, since their
		 * screen reader would read the sessions in a different order than they were seeing in the grid.
		 */
		sortByTrack( sessions );
	} );

	return groupedSessions;
}

/**
 * Sort an array of sessions, based on their assigned tracks.
 *
 * @param {Array} sessions
 */
function sortByTrack( sessions ) {
	sessions.sort( ( first, second ) => {
		return sortBySlug( first.derived.assignedTracks[ 0 ], second.derived.assignedTracks[ 0 ] );
	} );
}
