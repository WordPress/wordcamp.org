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
import { getOptionLabel } from '../../components/item-select';
import { ICON, LABEL } from './index';
import InspectorControls from './inspector-controls';
import { LayoutToolbar } from '../../components/post-list';
import { PlaceholderSpecificMode } from '../../components/block-controls';
import SpeakerList from './speaker-list';
import SpeakerSelect from './speaker-select';
import { WC_BLOCKS_STORE } from '../../data';

const blockData = window.WordCampBlocks.speakers || {};

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
					<SpeakerList attributes={ attributes } entities={ entities } />
				);
				break;

			case 'wcb_speaker' :
			case 'wcb_speaker_group' :
				output = (
					<PlaceholderSpecificMode
						content={
							<SpeakerList attributes={ attributes } entities={ entities } />
						}
						placeholderChildren={
							isSelected && (
								<SpeakerSelect
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
							<SpeakerSelect
								label={ __( 'Choose specific speakers or groups', 'wordcamporg' ) }
								attributes={ attributes }
								entities={ entities }
								icon={ ICON }
								setAttributes={ setAttributes }
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
				{ mode &&
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

const speakersSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	const entities = {
		wcb_speaker: getEntities( 'postType', 'wcb_speaker', { _embed: true } ),
		wcb_speaker_group: getEntities( 'taxonomy', 'wcb_speaker_group' ),
		wcb_track: getEntities( 'taxonomy', 'wcb_track' ),
	};

	return {
		entities,
	};
};

export default withSelect( speakersSelect )( Edit );
