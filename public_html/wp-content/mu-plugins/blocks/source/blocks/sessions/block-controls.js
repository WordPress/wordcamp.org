/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Button, Placeholder } from '@wordpress/components';
import { Component }           from '@wordpress/element';
import { __ }                  from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PlaceholderSpecificMode } from '../../components/block-controls';
import { getOptionLabel }          from '../../components/item-select';
import { BlockContent }            from './block-content';
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
		const { icon, attributes, setAttributes, blockData } = this.props;
		const { mode } = attributes;
		const { options } = blockData;

		let output;

		switch ( mode ) {
			case 'all' :
				output = (
					<BlockContent { ...this.props } />
				);
				break;

			case 'wcb_session' :
			case 'wcb_track' :
			case 'wcb_session_category' :
				output = (
					<PlaceholderSpecificMode
						label={ getOptionLabel( mode, options.mode ) }
						icon={ icon }
						content={
							<BlockContent { ...this.props } />
						}
						placeholderChildren={
							<ContentSelect { ...this.props } />
						}
					/>
				);
				break;

			default :
				output = (
					<Placeholder
						className={ classnames( 'wordcamp__edit-placeholder', 'has-no-mode' ) }
						icon={ icon }
						label={ LABEL }
					>
						<div className={ 'wordcamp__edit-mode-option' } >
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

						<div className={ 'wordcamp__edit-mode-option' }>
							<ContentSelect
								icon={ icon }
								label={ __(
									'Choose specific sessions, tracks, or categories',
									'wordcamporg' ) }
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
