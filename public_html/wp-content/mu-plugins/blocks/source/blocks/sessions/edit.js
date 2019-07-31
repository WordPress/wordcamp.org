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
import { getSessionDetails } from './utils';
import { ICON, LABEL } from './index';
import InspectorControls from './inspector-controls';
import { LayoutToolbar } from '../../components/post-list';
import { PlaceholderSpecificMode } from '../../components/block-controls';
import SessionList from './session-list';
import SessionSelect from './session-select';
import { WC_BLOCKS_STORE } from '../../data';

const blockData = window.WordCampBlocks.sessions || {};

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
					<SessionList attributes={ attributes } entities={ entities } />
				);
				break;

			case 'wcb_session' :
			case 'wcb_track' :
			case 'wcb_session_category' :
				output = (
					<PlaceholderSpecificMode
						content={
							<SessionList attributes={ attributes } entities={ entities } />
						}
						placeholderChildren={
							isSelected && (
								<SessionSelect
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
							<SessionSelect
								label={ __( 'Choose specific sessions, tracks, or categories', 'wordcamporg' ) }
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
				{ mode && (
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
				) }
			</Fragment>
		);
	}
}

const sessionsSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	const sessionArgs = {
		_embed: true,
		wc_meta_key: '_wcpt_session_type',
		wc_meta_value: 'session', // Regular sessions only, no breaks/lunch/etc sessions.
	};

	const entities = {
		wcb_session: null,
		wcb_track: getEntities( 'taxonomy', 'wcb_track' ),
		wcb_session_category: getEntities( 'taxonomy', 'wcb_session_category' ),
	};

	const sessions = getEntities( 'postType', 'wcb_session', sessionArgs );
	if ( sessions ) {
		entities.wcb_session = sessions.map( ( item ) => ( { ...item, details: getSessionDetails( item ) } ) );
	}

	return {
		entities,
	};
};

export default withSelect( sessionsSelect )( Edit );
