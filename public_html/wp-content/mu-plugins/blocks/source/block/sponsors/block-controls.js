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
import SponsorsBlockContent        from './block-content';
import SponsorsSelect              from './sponsors-select';
import { LABEL }                   from './index';

/**
 * Component for displaying a UI within the block.
 */
class SponsorsBlockControls extends Component {
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
					<SponsorsBlockContent { ...this.props } />
				);
				break;

			case 'wcb_sponsor' :
			case 'wcb_sponsor_level' :
				output = (
					<PlaceholderSpecificMode
						label={ getOptionLabel( mode, options.mode ) }
						icon={ icon }
						content={
							<SponsorsBlockContent { ...this.props } />
						}
						placeholderChildren={
							<SponsorsSelect { ...this.props } />
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
							<SponsorsSelect
								icon={ icon }
								label={ __( 'Choose specific sponsors or levels', 'wordcamporg' ) }
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

export default SponsorsBlockControls;
