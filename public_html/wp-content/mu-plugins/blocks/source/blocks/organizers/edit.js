/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Placeholder } from '@wordpress/components';
import { Component, Fragment } from '@wordpress/element';
import { withSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { EditAppender, LayoutToolbar, getOptionLabel } from '../../components';
import { ICON, LABEL } from './index';
import InspectorControls from './inspector-controls';
import OrganizerList from './organizer-list';
import OrganizerSelect from './organizer-select';
import { WC_BLOCKS_STORE } from '../../data';

const blockData = window.WordCampBlocks.organizers || {};

/**
 * Top-level component for the editing UI for the block.
 */
class Edit extends Component {
	/**
	 * Render the internal block UI.
	 *
	 * @return {Element}
	 */
	renderContent() {
		const { attributes, entities, isSelected, setAttributes } = this.props;
		const { mode } = attributes;
		const { options } = blockData;

		let output;

		switch ( mode ) {
			case 'all' :
				output = (
					<OrganizerList attributes={ attributes } entities={ entities } />
				);
				break;

			case 'wcb_organizer' :
			case 'wcb_organizer_team' :
				output = (
					<EditAppender
						content={
							<OrganizerList attributes={ attributes } entities={ entities } />
						}
						appender={
							isSelected && (
								<OrganizerSelect
									label={ getOptionLabel( mode, options.mode ) }
									attributes={ attributes }
									entities={ entities }
									icon={ ICON }
									setAttributes={ setAttributes }
								/>
							)
						}
					/>
				);
				break;

			default :
				output = (
					<Placeholder
						className="wordcamp__edit-placeholder has-no-mode"
						icon={ ICON }
						label={ LABEL }
					>
						<div className="wordcamp__edit-mode-option">
							<Button
								isDefault
								isLarge
								onClick={ () => {
									setAttributes( { mode: 'all' } );
								} }
							>
								{ getOptionLabel( 'all', options.mode ) }
							</Button>
						</div>

						<div className="wordcamp__edit-mode-option">
							<OrganizerSelect
								label={ __( 'Choose specific organizers or teams', 'wordcamporg' ) }
								attributes={ attributes }
								entities={ entities }
								icon={ ICON }
								setAttributes={ this.props.setAttributes }
							/>
						</div>
					</Placeholder>
				);
				break;
		}

		return output;
	}

	/**
	 * Render the block's editing UI.
	 *
	 * @return {Element}
	 */
	render() {
		const { attributes, setAttributes } = this.props;
		const { mode, layout } = attributes;
		const { layout: layoutOptions = {} } = blockData.options;

		return (
			<Fragment>
				{ this.renderContent() }

				{ '' !== mode &&
					<Fragment>
						<InspectorControls
							attributes={ attributes }
							blockData={ blockData }
							setAttributes={ setAttributes }
						/>
						<LayoutToolbar
							layout={ layout }
							options={ layoutOptions }
							setAttributes={ setAttributes }
						/>
					</Fragment>
				}
			</Fragment>
		);
	}
}

const organizerSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	const entities = {
		wcb_organizer: getEntities( 'postType', 'wcb_organizer', { _embed: true } ),
		wcb_organizer_team: getEntities( 'taxonomy', 'wcb_organizer_team' ),
	};

	return {
		entities,
	};
};

export default withSelect( organizerSelect )( Edit );
