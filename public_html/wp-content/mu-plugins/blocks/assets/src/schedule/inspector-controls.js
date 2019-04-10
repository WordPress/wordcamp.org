/**
 * WordPress dependencies
 */
const { PanelBody, PanelRow, CheckboxControl, ToggleControl } = wp.components;
const { InspectorControls }                                 = wp.editor;
const { Component, Fragment }                               = wp.element;
const { __ }                                                = wp.i18n;

/**
 * Internal dependencies
 */
import './inspector-controls.scss';

//const DEFAULT_SCHEMA = {
//	grid_columns: {
//		default : 2,
//		minimum : 2,
//		maximum : 4,
//	},
//
//	avatar_size: {
//		default : 150,
//		minimum : 25,
//		maximum : 600,
//	},
//};

const DEFAULT_OPTIONS = {
	//align   : {},
	//content : {},
	//sort    : {},

	// need some here for days/tracks/categories ?
};

class ScheduleInspectorControls extends Component {
	render() {
		const { attributes, setAttributes, blockData }                                 = this.props;
		const { show_avatars, avatar_size, avatar_align, content, excerpt_more, sort } = attributes;
		const { schema = DEFAULT_SCHEMA, options = DEFAULT_OPTIONS }                   = blockData;

		// mockup for category not consistent, see
		// https://github.com/WordPress/wordcamp.org/issues/62
		// need to update this and session depending on which is chosen

		// need to handle weirdness around selecting max # of specific is should be treated as "select all tracks, including future ones"
		// https://make.wordpress.org/community/2018/10/30/wordcamp-block-sessions/#comment-26194
		// https://make.wordpress.org/community/2018/10/26/wordcamp-block-schedule/#comment-26197
		// so maybe an internal variable for show all tracks, which is checked if they enable the toggle but then leave all the tracks selected
		// that'd be independent of the option to choose specific tracks
		// need to document this clearly b/c it's fraking confusing
		// need to test to make sure it works when additional tracks added in future

		const show_categories = false;
		const choose_days     = true;
		const choose_tracks   = true;

		return (
			<InspectorControls>
				<PanelBody title={ __( 'Display Settings', 'wordcamporg' ) } initialOpen={ true }>
					<PanelRow className="wordcamp-schedule__show-categories">
						<ToggleControl
							label={ __( 'Show categories', 'wordcamporg' ) }
							checked={ show_categories }
							onChange={ ( value ) => setAttributes( { show_category: value } ) }
						/>
					</PanelRow>

					<PanelRow className="wordcamp-schedule__choose-specific-days">    {/* todo not sure if this is correct */}
						<ToggleControl
							label={ __( 'Choose specific days', 'wordcamporg' ) }
							checked={ choose_days }
							onChange={ ( value ) => setAttributes( { choose_days: value } ) }
						/>

						{ choose_days &&
							<Fragment>
								<CheckboxControl
									label="Saturday, May 1st 2019"
									checked={ true }
									onChange={ ( isChecked ) => { setAttribute( isChecked ) } }
								/>

								<CheckboxControl
									label="Sunday, May 2nd 2019"
									checked={ true }
									onChange={ ( isChecked ) => { setAttribute( isChecked ) } }
								/>
							</Fragment>
						}
					</PanelRow>

					{/*
					Reading this gave me a thought: What if the toggle is OFF by default and says “Select Tracks to Show” or something. When it’s toggled ON, it shows the checkboxes.
					That would at least somewhat address my initial concern of “what happens if I turn that off?”. I think it may also set up the expectation that if you turn it on (i.e., “yes, I want to choose tracks”), tracks created after that won’t be shown (which feels like the right default to me).
					*/}

					<PanelRow className="wordcamp-schedule__choose-specific-tracks">
						<ToggleControl
							label={ __( 'Choose specific tracks', 'wordcamporg' ) }
							checked={ choose_tracks }
							onChange={ ( value ) => setAttributes( { show_tracks: value } ) }
						/>

							{ choose_tracks &&
							<Fragment>
								<CheckboxControl
									label="Auditorium"
									checked={ true }
									onChange={ ( isChecked ) => { setAttribute( isChecked ) } }
								/>

								<CheckboxControl
									label="Ballroom"
									checked={ true }
									onChange={ ( isChecked ) => { setAttribute( isChecked ) } }
								/>

								<CheckboxControl
									label="Balcony"
									checked={ true }
									onChange={ ( isChecked ) => { setAttribute( isChecked ) } }
								/>
							</Fragment>
						}
					</PanelRow>
				</PanelBody>
			</InspectorControls>
		);
	}
}

export default ScheduleInspectorControls;
