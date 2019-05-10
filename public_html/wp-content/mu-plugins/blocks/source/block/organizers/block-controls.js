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
import { PlaceholderSpecificMode } from '../../component/block-controls';
import { getOptionLabel }          from '../../component/item-select';
import OrganizersBlockContent      from './block-content';
import OrganizersSelect            from './organizers-select';
import { LABEL }                   from './index';

/**
 * Component for displaying a UI within the block.
 */
class OrganizersBlockControls extends Component {
	/**
	 * Render the internal block UI.
	 *
	 * @return {Element}
	 */
	render() {
		const { icon, attributes, setAttributes, blockData } = this.props;
		const { mode } = attributes;
		const { options } = blockData;

		let output;

		switch ( mode ) {
			case 'all' :
				output = (
					<OrganizersBlockContent { ...this.props } />
				);
				break;

			case 'wcb_organizer' :
			case 'wcb_organizer_team' :
				output = (
					<PlaceholderSpecificMode
						label={ getOptionLabel( mode, options.mode ) }
						icon={ icon }
						content={
							<OrganizersBlockContent { ...this.props } />
						}
						placeholderChildren={
							<OrganizersSelect { ...this.props } />
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
							<OrganizersSelect
								label={ __( 'Choose specific organizers or teams', 'wordcamporg' ) }
								{ ...this.props }
							/>
						</div>
					</Placeholder>
				);
				break;
		}

		return output;
	}
}

export default OrganizersBlockControls;
