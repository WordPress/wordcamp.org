/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, ToggleControl } from '@wordpress/components';
import { date, format } from '@wordpress/date';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';

const { stripTags } = wp.sanitize;

/**
 * Internal dependencies
 */
import { DATE_SLUG_FORMAT, SITE_TIMEZONE } from './data';

/**
 * Render the inspector Controls for the Schedule block.
 *
 * @param {Object}   props
 * @param {Array}    props.attributes
 * @param {Array}    props.allSessions
 * @param {Object}   props.allTracks
 * @param {Function} props.setAttributes
 * @param {Array}    props.settings
 * @return {Element}
 */
export default function ScheduleInspectorControls( {
	attributes,
	allSessions,
	allTracks,
	setAttributes,
	settings,
} ) {
	const {
		showCategories,
		chooseSpecificDays,
		chosenDays,
		chooseSpecificTracks,
		chosenTrackIds,
		useClientTimezone,
	} = attributes;
	const displayedDays = getDisplayedDays( allSessions );

	const clientTimezoneHelpText = __(
		'Sessions will be shown using the local timezone of the viewer. This is best for virtual WordCamps or livestreams.',
		'wordcamporg'
	);

	const siteTimezoneHelpText = createInterpolateElement(
		__(
			'Sessions will be shown using the site timezone. This uses the same timezone for everyone. <a>Set the site timezone.</a>',
			'wordcamporg'
		),
		{
			// eslint-disable-next-line jsx-a11y/anchor-has-content -- See 21441-gutenberg
			a: <a href="/wp-admin/options-general.php" />,
		}
	);

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Display Settings', 'wordcamporg' ) } initialOpen={ true }>
				<ToggleControl
					label={ __( 'Show categories', 'wordcamporg' ) }
					checked={ showCategories }
					onChange={ ( value ) => setAttributes( { showCategories: value } ) }
				/>

				<ToggleControl
					label={ __( "Use visitor's timezone", 'wordcamporg' ) }
					help={ useClientTimezone ? clientTimezoneHelpText : siteTimezoneHelpText }
					checked={ useClientTimezone }
					onChange={ ( value ) => setAttributes( { useClientTimezone: value } ) }
				/>
			</PanelBody>

			<PanelBody title={ __( 'Filters', 'wordcamporg' ) } initialOpen={ true }>
				<ChooseSpecificDays
					chooseSpecificDays={ chooseSpecificDays }
					displayedDays={ displayedDays }
					chosenDays={ chosenDays }
					dateFormat={ settings.date_format }
					setAttributes={ setAttributes }
				/>

				<ChooseSpecificTracks
					chooseSpecificTracks={ chooseSpecificTracks }
					allTracks={ allTracks }
					chosenTrackIds={ chosenTrackIds }
					setAttributes={ setAttributes }
				/>
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Get all of the dates (in site/venue timezone) that the given sessions are assigned to.
 *
 * @param {Array} sessions
 * @return {string[]}
 */
function getDisplayedDays( sessions ) {
	let uniqueDays = sessions.reduce( ( accumulatingDays, session ) => {
		if ( session.derived.startTime ) {
			accumulatingDays[
				date( DATE_SLUG_FORMAT, session.derived.startTime, SITE_TIMEZONE )
			] = true;
		}

		return accumulatingDays;
	}, {} );

	uniqueDays = Object.keys( uniqueDays );

	return uniqueDays.sort();
}

/**
 * Render the UI for choosing specific days.
 *
 * @param {Array}    props
 * @param {boolean}  props.chooseSpecificDays
 * @param {Array}    props.displayedDays
 * @param {Array}    props.chosenDays
 * @param {string}   props.dateFormat
 * @param {Function} props.setAttributes
 * @return {Element}
 */
function ChooseSpecificDays( { chooseSpecificDays, displayedDays, chosenDays, dateFormat, setAttributes } ) {
	const pleaseAssignDates = createInterpolateElement(
		__( "There aren't any days to display. Please assign dates to <a>your sessions</a>.", 'wordcamporg' ),
		{
			// eslint-disable-next-line jsx-a11y/anchor-has-content -- See 21441-gutenberg
			a: <a href={ '/wp-admin/edit.php?post_type=wcb_session' } />,
		}
	);

	return (
		<div className="wordcamp-schedule__control-container">
			<fieldset>
				<legend>
					<ToggleControl
						label={ __( 'Choose specific days', 'wordcamporg' ) }
						checked={ chooseSpecificDays }
						onChange={ ( enabled ) => setAttributes( { chooseSpecificDays: enabled } ) }
					/>
				</legend>

				{ chooseSpecificDays && displayedDays.length === 0 && (
					<div className="notice notice-warning has-no-dates">
						<p>{ pleaseAssignDates }</p>
					</div>
				) }

				{ chooseSpecificDays &&
					displayedDays.length > 0 &&
					displayedDays.map( ( day ) => {
						return (
							<CheckboxControl
								key={ day }
								label={ format( dateFormat, day ) }
								checked={ chosenDays.includes( day ) }
								onChange={ ( isChecked ) => {
									const newDays = isChecked
										? [ ...chosenDays, day ]
										: chosenDays.filter( ( i ) => day !== i );

									setAttributes( { chosenDays: newDays } );
								} }
							/>
						);
					} ) }
			</fieldset>
		</div>
	);
}

/**
 * Render the UI for choosing specific tracks.
 *
 * All of the tracks that exist are shown, instead of just the ones assigned to the current sessions. That's more
 * consistent and obvious for users, so they don't have to guess why a track they created isn't showing up.
 *
 * @todo There's a couple issues related to hierarchical taxonomies:
 * https://github.com/WordPress/gutenberg/issues/13816#issuecomment-532885577
 * https://github.com/WordPress/gutenberg/issues/17476
 * @param {Array}    props
 * @param {boolean}  props.chooseSpecificTracks
 * @param {Object}   props.allTracks
 * @param {Array}    props.chosenTrackIds
 * @param {Function} props.setAttributes
 * @return {Element}
 */
function ChooseSpecificTracks( { chooseSpecificTracks, allTracks, chosenTrackIds, setAttributes } ) {
	const pleaseAssignTracks = createInterpolateElement(
		__( "There aren't any tracks to display, but you can <a>create some</a>.", 'wordcamporg' ),
		{
			// eslint-disable-next-line jsx-a11y/anchor-has-content -- See 21441-gutenberg
			a: <a href={ '/wp-admin/edit-tags.php?taxonomy=wcb_track&post_type=wcb_session' } />,
		}
	);

	// See `fetchScheduleData()` for details on track sorting.
	const tracksArrangedAlpha = __(
		'Notes: Tracks are arranged alphabetically, according to their slug.',
		'wordcamporg'
	);

	return (
		<div className="wordcamp-schedule__control-container">
			<fieldset>
				<legend>
					<ToggleControl
						label={ __( 'Choose specific tracks', 'wordcamporg' ) }
						checked={ chooseSpecificTracks }
						onChange={ ( enabled ) => setAttributes( { chooseSpecificTracks: enabled } ) }
						help={ allTracks.length > 0 ? tracksArrangedAlpha : '' }
					/>
				</legend>

				{ chooseSpecificTracks && allTracks.length === 0 && (
					<div className="notice notice-warning has-no-tracks">
						<p>{ pleaseAssignTracks }</p>
					</div>
				) }

				{ chooseSpecificTracks &&
					allTracks.length > 0 &&
					allTracks.map( ( track ) => {
						return (
							<CheckboxControl
								key={ track.id }
								label={ decodeEntities( stripTags( track.name ) ) }
								checked={ chosenTrackIds.includes( track.id ) }
								onChange={ ( isChecked ) => {
									const newTracks = isChecked
										? [ ...chosenTrackIds, track.id ]
										: chosenTrackIds.filter( ( id ) => track.id !== id );

									setAttributes( { chosenTrackIds: newTracks } );
								} }
							/>
						);
					} ) }
			</fieldset>
		</div>
	);
}
