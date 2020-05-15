/**
 * External dependencies
 */
import classnames from 'classnames';
import { isEqual } from 'lodash';

/**
 * WordPress dependencies
 */
import { dateI18n } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import { createInterpolateElement, useContext } from '@wordpress/element';

/**
 * Import dependencies
 */
import { ScheduleGridContext } from './edit';

/**
 * Render an individual session.
 *
 * @param {Object}  props
 * @param {Object}  props.session
 * @param {Array}   props.displayedTracks
 * @param {boolean} props.showCategories
 * @param {boolean} props.overlapsAnother
 *
 * @return {Element}
 */
export function Session( { session, displayedTracks, showCategories, overlapsAnother } ) {
	const { renderEnvironment } = useContext( ScheduleGridContext );
	const { id, slug, title, link: permalink } = session;
	const { assignedCategories, assignedTracks, startTime, endTime } = session.derived;
	const displayedTrackIds = displayedTracks.map( ( track ) => track.id );

	const displayedAssignedTracks = assignedTracks.filter(
		( track ) => displayedTrackIds.includes( track.id )
	);

	const speakers = session.session_speakers || [];
	const titleLinkUrl = 'editor' === renderEnvironment ? `/wp-admin/post.php?post=${ id }&action=edit` : permalink;

	/*
	 * Link to the edit-post screen when editing because, in that context, it's more likely that the
	 * organizer will want to edit a session than view it.
	 *
	 * They're only opened in new tabs when editing, though. Doing that on the front end would be an anti-pattern
	 * that hurts UX by taking control away from the user.
	 */
	const titleLinkTarget = 'editor' === renderEnvironment ? '_blank' : '_self';

	const classes = classnames(
		'wordcamp-schedule__session',
		'has-slug-' + slug,
		'is-type-' + session.meta._wcpt_session_type,

		{ 'is-spanning-some-tracks': displayedAssignedTracks.length > 1 },
		{ 'is-spanning-all-tracks': displayedAssignedTracks.length === displayedTracks.length },
		{ 'is-overlapping-another-session': overlapsAnother },

		displayedAssignedTracks.map( ( track ) => 'has-track-' + track.slug ),
		assignedCategories.map( ( category ) => 'has-category-' + category.slug ),
		speakers.map( ( speaker ) => 'has-speaker-' + speaker.slug ),
	);

	// This expects that `assignedTracks` and `displayedAssignedTracks` have identical sorting.
	const startTrackId = displayedAssignedTracks[ 0 ].id;
	let endTrackId = displayedAssignedTracks[ displayedAssignedTracks.length - 1 ].id;

	const spansNonContiguousTracks = sessionSpansNonContiguousTracks(
		assignedTracks.map( ( track ) => track.id ),
		displayedTracks.map( ( track ) => track.id )
	);

	// Ignore the other tracks, since we can't display them accurately.
	if ( spansNonContiguousTracks ) {
		endTrackId = startTrackId;
	}

	const gridColumn = `
		wordcamp-schedule-track-${ startTrackId } /
		wordcamp-schedule-track-${ endTrackId }
	`;

	const gridRow = `
		time-${ dateI18n( 'Hi', startTime ) } /
		time-${ dateI18n( 'Hi', endTime ) }
	`;

	return (
		<div
			id={ 'wordcamp-schedule__session-' + id }
			className={ classes }
			style={ { gridColumn, gridRow } }
		>
			<h4 className="wordcamp-schedule__session-title">
				<a href={ titleLinkUrl } target={ titleLinkTarget } rel="noopener noreferrer">
					{ title.rendered }

					{ /* todo need to decode entities, e.g., "Doors Open &amp; Check-in". maybe other places too,
					     like speaker & track names, etc */ }
				</a>
			</h4>

			{ speakers && renderSpeakers( speakers, renderEnvironment ) }

			{ displayedAssignedTracks && renderAssignedTracks( displayedAssignedTracks ) }

			{ showCategories && assignedCategories.length > 0 && renderCategories( assignedCategories ) }

			{ 'editor' === renderEnvironment && renderWarnings( spansNonContiguousTracks, overlapsAnother ) }
		</div>
	);
}

/**
 * Determine if the session spans non-contiguous tracks.
 *
 * This is important, because spanning non-contiguous tracks isn't supported by CSS Grid yet (via `subgrid`).
 *
 * If the assigned sessions are contiguous, then looping through them will not flip the toggle more than once.
 * If it does, we can tell that there's a gap.
 *
 * This assumes that both arrays are sorted using the same criteria, and that their order matches the order in
 * which they appear in the schedule.
 *
 * @param {Array} assignedTrackIds Tracks that the given session is assigned to.
 * @param {Array} displayedTrackIds Tracks that are being displayed in the grid.
 *
 * @return {boolean}
 */
function sessionSpansNonContiguousTracks( assignedTrackIds, displayedTrackIds ) {
	let toggleCount = 0;
	let previousWasAssigned = false;
	let currentIsAssigned;

	if ( assignedTrackIds.length < 2 || isEqual( assignedTrackIds, displayedTrackIds ) ) {
		return false;
	}

	displayedTrackIds.forEach( ( trackId ) => {
		currentIsAssigned = assignedTrackIds.includes( trackId );

		if ( previousWasAssigned !== currentIsAssigned ) {
			toggleCount++;
			previousWasAssigned = currentIsAssigned;
		}
	} );

	return toggleCount > 2;
}

/**
 * Render the session's speakers
 *
 * @param {Array}  speakers
 * @param {string} renderEnvironment
 *
 * @return {Element}
 */
function renderSpeakers( speakers, renderEnvironment ) {
	return (
		<dl className="wordcamp-schedule__session-speakers">
			<dt className="screen-reader-text">
				{ __( 'Speakers:', 'wordcamporg' ) }
			</dt>

			{ speakers.map( ( speaker ) => {
				if ( ! speaker.id ) {
					return null;
				}

				const speakerLinkUrl = 'editor' === renderEnvironment ? `/wp-admin/post.php?post=${ speaker.id }&action=edit` : speaker.link;

				// See note about `wordcamp-schedule__session-title` regarding the `target`.
				const speakerLinkTarget = 'editor' === renderEnvironment ? '_blank' : '_self';

				return (
					<dd key={ speaker.id }>
						<a href={ speakerLinkUrl } target={ speakerLinkTarget } rel="noopener noreferrer">
							{ speaker.name }
						</a>
					</dd>
				);
			} ) }
		</dl>
	);
}

/**
 * Render the session's tracks
 *
 * @param {Array} tracks
 *
 * @return {Element}
 */
function renderAssignedTracks( tracks ) {
	return (
		<dl className="wordcamp-schedule__session-tracks">
			<dt className="screen-reader-text">
				{ __( 'Tracks:', 'wordcamporg' ) }
			</dt>

			{ tracks.map( ( track ) => {
				return (
					<dd key={ track.id }>
						{ track.name }
					</dd>
				);
			} ) }
		</dl>
	);
}

/**
 * Render the session's categories
 *
 * @param {Array} categories
 *
 * @return {Element}
 */
function renderCategories( categories ) {
	return (
		<dl className="wordcamp-schedule__session-category">
			<dt className="screen-reader-text">
				{ __( 'Categories:', 'wordcamporg' ) }
			</dt>

			{ categories.map( ( category ) => {
				return (
					<dd key={ category.id }>
						{ category.slug }
					</dd>
				);
			} ) }
		</dl>
	);
}

/**
 * Warn organizers about problems with the session.
 *
 * @param {boolean} spansNonContiguousTracks
 * @param {boolean} overlapsAnother
 *
 * @return {Element}
 */
function renderWarnings( spansNonContiguousTracks, overlapsAnother ) {
	/*
	 * See `fetchScheduleData()` for details on the sorting problem.
	 *
	 * This string should explicitly mention the grid layout, because otherwise it could be
	 * confusing to organizers viewing the mobile layout. They need to be aware of the problem
	 * even if it isn't obvious on their current device.
     */
	const pleaseRenameSlugs = createInterpolateElement(
		__( "Warning: Sessions can't span non-contiguous tracks in the grid layout. Please <a>rename the track slugs</a> so that the tracks you want to appear next to each other are sorted alphabetically.", 'wordcamporg' ),
		{
			a: <a href={ '/wp-admin/edit-tags.php?taxonomy=wcb_track&post_type=wcb_session' } >#21441-gutenberg</a>,
		}
	);

	return (
		<>
			{ spansNonContiguousTracks && (
				<p className="notice notice-warning notice-spans-non-contiguous-tracks">
					{ pleaseRenameSlugs }
				</p>
			) }

			{ /*
			   * Warn about this, because it can be hard to visually detect, and the cause isn't always obvious.
			   */ }
			{ overlapsAnother && (
				<p className="notice notice-warning notice-overlaps-another-track">
					{ __( "Warning: This session overlaps another session in the same track. Please adjust the times so that they don't overlap.", 'wordcamporg' ) }
				</p>
			) }
		</>
	);
}
