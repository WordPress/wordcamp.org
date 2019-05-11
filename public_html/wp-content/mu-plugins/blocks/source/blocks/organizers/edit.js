/**
 * WordPress dependencies
 */
const { withSelect }          = wp.data;
const { Component, Fragment } = wp.element;

/**
 * Internal dependencies
 */
import { LayoutToolbar }     from '../../components/post-list';
import { WC_BLOCKS_STORE }   from '../../data';
import { BlockContext }      from './block-context';
import { BlockControls }     from './block-controls';
import { InspectorControls } from './inspector-controls';
import { ICON }              from './index';

const definitions = window.WordCampBlocks.organizers || {};

/**
 * Top-level component for the editing UI for the block.
 */
class OrganizersEdit extends Component {

	constructor( props ) {
		super( props );

		this.getContextValue = this.getContextValue.bind( this );
	}


	getContextValue() {
		const { attributes, entities, setAttributes } = this.props;

		return { attributes, definitions, entities, setAttributes };
	}

	/**
	 * Render the block's editing UI.
	 *
	 * @return {Element}
	 */
	render() {
		const { Provider }                   = BlockContext;
		const { attributes, setAttributes }  = this.props;
		const { mode, layout }               = attributes;
		const { layout: layoutOptions = {} } = definitions.options;

		return (
			<Provider value={ this.getContextValue() }>
				<Fragment>
					<BlockControls icon={ ICON } />

					{ '' !== mode &&
						<Fragment>
							<InspectorControls { ...this.props } />
							<LayoutToolbar
								layout={ layout }
								options={ layoutOptions }
								setAttributes={ setAttributes }
							/>
						</Fragment>
					}
				</Fragment>
			</Provider>
		);
	}
}

const organizerSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	const entities = {
		wcb_organizer      : getEntities( 'postType', 'wcb_organizer', { _embed: true } ),
		wcb_organizer_team : getEntities( 'taxonomy', 'wcb_organizer_team' ),
	};

	return {
		blockData : definitions,
		entities,
	};
};

export const Edit = withSelect( organizerSelect )( OrganizersEdit );
