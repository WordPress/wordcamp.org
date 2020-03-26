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
import SponsorList from './sponsor-list';
import SponsorSelect from './sponsor-select';
import { WC_BLOCKS_STORE } from '../../data';

const blockData = window.WordCampBlocks.sponsors || {};

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
					<SponsorList attributes={ attributes } entities={ entities } />
				);
				break;

			case 'wcb_sponsor' :
			case 'wcb_sponsor_level' :
				output = (
					<EditAppender
						content={ <SponsorList attributes={ attributes } entities={ entities } /> }
						appender={
							isSelected && (
								<Placeholder className="wordcamp__edit-placeholder" icon={ ICON } label={ LABEL }>
									<SponsorSelect
										label={ getOptionLabel( mode, options.mode ) }
										attributes={ attributes }
										entities={ entities }
										icon={ ICON }
										setAttributes={ setAttributes }
									/>
								</Placeholder>
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
								isSecondary
								onClick={ () => {
									setAttributes( { mode: 'all' } );
								} }
							>
								{ getOptionLabel( 'all', options.mode ) }
							</Button>
						</div>

						<div className="wordcamp__edit-mode-option">
							<SponsorSelect
								label={ __( 'Choose specific sponsors or levels', 'wordcamporg' ) }
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

const sponsorSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	const entities = {
		wcb_sponsor: getEntities( 'postType', 'wcb_sponsor', { _embed: true } ),
		wcb_sponsor_level: getEntities( 'taxonomy', 'wcb_sponsor_level' ),
	};

	return {
		entities,
	};
};

export default withSelect( sponsorSelect )( Edit );
