/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, ToggleControl } from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { DATE_SLUG_FORMAT } from './data';

/**
 * Render the inspector Controls for the Schedule block.
 *
 * @param {Object}   props
 * @param {Array}    props.attributes
 * @param {Array}    props.allSessions
 * @param {Object}   props.allTracks
 * @param {Function} props.setAttributes
 * @param {Array}    props.settings
 *
 * @return {Element}
 */
export default function ScheduleInspectorControls(
	{ attributes, allSessions, allTracks, setAttributes, settings }
) {
	const { showCategories, chooseSpecificDays, chosenDays, chooseSpecificTracks, chosenTrackIds } = attributes;
	const displayedDays = getDisplayedDays( allSessions );

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Display Settings', 'wordcamporg' ) } initialOpen={ true }>
				<ToggleControl
					label={ __( 'Show categories', 'wordcamporg' ) }
					checked={ showCategories }
					onChange={ ( value ) => setAttributes( { showCategories: value } ) }
				/>

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
 * Get all of the dates that the given sessions are assigned to.
 *
 * @param {Array} sessions
 *
 * @return {string[]}
 */
function getDisplayedDays( sessions ) {
	let uniqueDays = sessions.reduce( ( accumulatingDays, session ) => {
		accumulatingDays[ dateI18n( DATE_SLUG_FORMAT, session.derived.startTime ) ] = true;

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
 *
 * @return {Element}
 */
function ChooseSpecificDays( { chooseSpecificDays, displayedDays, chosenDays, dateFormat, setAttributes } ) {
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

				{ chooseSpecificDays &&
					displayedDays.map( ( day ) => {
						return (
							<CheckboxControl
								key={ day }
								label={ dateI18n( dateFormat, day ) }
								checked={ chosenDays.includes( day ) }
								onChange={ ( isChecked ) => {
									/*
									 * Use `.from()` because `setAttributes()` needs a new array to determine if
									 * it's changed or not.
									 */
									const newDays = Array.from( chosenDays );

									if ( isChecked ) {
										newDays.push( day );
									} else {
										newDays.splice( newDays.indexOf( day ), 1 ); // Remove from the array.
									}

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
 * @param {Array}    props
 * @param {boolean}  props.chooseSpecificTracks
 * @param {Object}   props.allTracks
 * @param {Array}    props.chosenTrackIds
 * @param {Function} props.setAttributes
 *
 * @return {Element}
 */
function ChooseSpecificTracks( { chooseSpecificTracks, allTracks, chosenTrackIds, setAttributes } ) {
	return (
		<div className="wordcamp-schedule__control-container">
			<fieldset>
				<legend>
					<ToggleControl
						label={ __( 'Choose specific tracks', 'wordcamporg' ) }
						checked={ chooseSpecificTracks }
						onChange={ ( enabled ) => setAttributes( { chooseSpecificTracks: enabled } ) }

						// See `fetchScheduleData()` for details on track sorting.
						help="Notes: Tracks are arranged alphabetically, according to their slug."
					/>
				</legend>

				{ chooseSpecificTracks &&
					allTracks.map( ( track ) => {
						return (
							<CheckboxControl
								key={ track.id }
								label={ track.name }
								checked={ chosenTrackIds.includes( track.id ) }
								onChange={ ( isChecked ) => {
									const newTracks = Array.from( chosenTrackIds ); // setAttributes() needs a new array to determine if it's changed or not.

									if ( isChecked ) {
										newTracks.push( track.id );
									} else {
										newTracks.splice( newTracks.indexOf( track.id ), 1 ); // Remove from the array.
									}

									setAttributes( { chosenTrackIds: newTracks } );
								} }
							/>
						);
					} ) }
			</fieldset>
		</div>
	);
}
