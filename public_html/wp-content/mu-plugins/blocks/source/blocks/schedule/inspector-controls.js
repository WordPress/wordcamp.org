/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, ToggleControl } from '@wordpress/components';
import { Component, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/*
 * @todo
 * make this dynamic
 * add default schema and options, like speakers?
 *
 * commit w/ props mark, mel, corey. look for any others as well
 */

/**
 * Render the inspector Controls for the Schedule block.
 */
export default class extends Component {
	/**
	 * Render the component.
	 *
	 * @return {Element}
	 */
	render() {
		const { setAttributes } = this.props;
		const show_categories = false;
		const choose_days = true;
		const choose_tracks = true;

		return (
			<InspectorControls>
				<PanelBody title={ __( 'Display Settings', 'wordcamporg' ) } initialOpen={ true }>
					<ToggleControl
						label={ __( 'Show categories', 'wordcamporg' ) }
						checked={ show_categories }
						onChange={ ( value ) => setAttributes( { show_category: value } ) }
					/>

					<div className="wordcamp-schedule__control-container">
						<fieldset>
							<legend>
								<ToggleControl
									label={ __( 'Choose specific days', 'wordcamporg' ) }
									checked={ choose_days }
									onChange={ ( value ) => setAttributes( { choose_days: value } ) }
								/>
							</legend>

							{ choose_days &&
								<Fragment>
									<CheckboxControl
										label="Saturday, May 1st 2019"
										checked={ true }
										onChange={ ( isChecked ) => {
											setAttributes( isChecked );
										} }
									/>

									<CheckboxControl
										label="Sunday, May 2nd 2019"
										checked={ true }
										onChange={ ( isChecked ) => {
											setAttributes( isChecked );
										} }
									/>
								</Fragment>
							}
						</fieldset>
					</div>

					<div className="wordcamp-schedule__control-container">
						<fieldset>
							<legend>
								<ToggleControl
									label={ __( 'Choose specific tracks', 'wordcamporg' ) }
									checked={ choose_tracks }
									onChange={ ( value ) => setAttributes( { show_tracks: value } ) }
								/>
							</legend>

							{ choose_tracks &&
								<Fragment>
									<CheckboxControl
										label="Auditorium"
										checked={ true }
										onChange={ ( isChecked ) => {
											setAttributes( isChecked );
										} }
									/>

									<CheckboxControl
										label="Ballroom"
										checked={ true }
										onChange={ ( isChecked ) => {
											setAttributes( isChecked );
										} }
									/>

									<CheckboxControl
										label="Balcony"
										checked={ true }
										onChange={ ( isChecked ) => {
											setAttributes( isChecked );
										} }
									/>
								</Fragment>
							}
						</fieldset>
					</div>
				</PanelBody>
			</InspectorControls>
		);
	}
}
