/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
const { Button, Placeholder } = wp.components;
const { Component }           = wp.element;
const { __ }                  = wp.i18n;

/**
 * Internal dependencies
 */
import { PlaceholderSpecificMode } from '../../components/block-controls';
import { getOptionLabel }          from '../../components/item-select';
import { BlockContent }            from './block-content';
import { BlockContext }            from './block-context';
import { ContentSelect }           from './content-select';
import { LABEL }                   from './index';

/**
 * Component for displaying a UI within the block.
 */
export class BlockControls extends Component {
	/**
	 * Render the internal block UI.
	 *
	 * @return {Element}
	 */
	render() {
		const { icon } = this.props;
		const { attributes, definitions, setAttributes } = this.context;
		const { mode } = attributes;
		const { options } = definitions;

		let output;

		switch ( mode ) {
			case 'all' :
				output = (
					<BlockContent />
				);
				break;

			case 'wcb_organizer' :
			case 'wcb_organizer_team' :
				output = (
					<PlaceholderSpecificMode
						label={ getOptionLabel( mode, options.mode ) }
						icon={ icon }
						content={
							<BlockContent />
						}
						placeholderChildren={
							<ContentSelect />
						}
					/>
				);
				break;

			default :
				output = (
					<Placeholder
						className={ classnames( 'wordcamp-block-edit-placeholder', 'wordcamp-block-edit-placeholder-no-mode' ) }
						icon={ icon }
						label={ LABEL }
					>
						<div className="wordcamp-block-edit-mode-option">
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

						<div className="wordcamp-block-edit-mode-option">
							<ContentSelect
								label={ __( 'Choose specific organizers or teams', 'wordcamporg' ) }
							/>
						</div>
					</Placeholder>
				);
				break;
		}

		return output;
	}
}

BlockControls.contextType = BlockContext;
