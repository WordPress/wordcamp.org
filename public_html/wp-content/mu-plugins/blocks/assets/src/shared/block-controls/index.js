/**
 * WordPress dependencies
 */
const { Placeholder, Spinner } = wp.components;
const { Component, Fragment } = wp.element;

export class BlockControls extends Component {
	constructor( props ) {
		super( props );

		this.getModeLabel = this.getModeLabel.bind( this );
	}

	getModeLabel( value ) {
		const { mode } = this.props.blockData.options;

		return mode.find( ( modeOption ) => {
			return value === modeOption.value;
		} ).label;
	}
}

export function PlaceholderNoContent( { icon, label, loading } ) {
	return (
		<Placeholder
			icon={ icon }
			label={ label }
		>
			{ loading ?
				<Spinner /> :
				__( 'No content found.', 'wordcamporg' )
			}
		</Placeholder>
	);
}

export function PlaceholderSpecificMode( { label, icon, content, placeholderChildren } ) {
	return (
		<Fragment>
			{ content }
			<Placeholder
				label={ label }
				icon={ icon }
			>
				{ placeholderChildren }
			</Placeholder>
		</Fragment>
	);
}
