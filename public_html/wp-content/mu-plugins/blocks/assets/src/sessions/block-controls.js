/**
 * WordPress dependencies
 */
const { Button, Placeholder } = wp.components;
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { BlockControls, PlaceholderNoContent, PlaceholderSpecificMode } from "../shared/block-controls";

const LABEL = __( 'Sessions', 'wordcamporg' );

class SessionsBlockControls extends BlockControls {
	render() {
		const { icon, attributes, setAttributes, sessionPosts } = this.props;
		const { mode } = attributes;

		const hasPosts = Array.isArray( sessionPosts ) && sessionPosts.length;

		if ( mode && ! hasPosts ) {
			return (
				<PlaceholderNoContent
					icon={ icon }
					label={ LABEL }
					loading={ () => {
						return ! Array.isArray( sessionPosts );
					} }
				/>
			);
		}

		let output;

		switch ( mode ) {
			case 'all' :
				output = (
					<SessionsBlockContent { ...this.props } />
				);
				break;

			case 'post' :
			case 'track' :
			case 'category' :
				output = (
					<PlaceholderSpecificMode
						label={ this.getModeLabel( mode ) }
						icon={ icon }
						content={
							<SessionsBlockContent { ...this.props } />
						}
						placeholderChildren={
							<SessionsSelect { ...this.props } />
						}
					/>
				);
				break;

			default :
				output = (
					<Placeholder
						icon={ icon }
						label={ LABEL }
					>
						<div className="wordcamp-block-mode-option">
							<Button
								isDefault
								isLarge
								onClick={ () => {
									setAttributes( { mode: 'all' } );
								} }
							>
								{ this.getModeLabel( 'all' ) }
							</Button>
						</div>

						<div className="wordcamp-block-mode-option">
							<SpeakersSelect
								label={ __( 'Choose specific speakers or groups', 'wordcamporg' ) }
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

export default SessionsBlockControls;
