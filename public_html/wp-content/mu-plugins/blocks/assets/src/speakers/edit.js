/**
 * WordPress dependencies
 */
const { withSelect } = wp.data;
const { Component, Fragment } = wp.element;

/**
 * Internal dependencies
 */
import SpeakersBlockControls     from './block-controls';
import SpeakersInspectorControls from './inspector-controls';
import SpeakersToolbar           from './toolbar';
import { ICON }                  from './index';
import { WC_BLOCKS_STORE }       from '../blocks-store';

const blockData = window.WordCampBlocks.speakers || {};

class SpeakersEdit extends Component {
	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<SpeakersBlockControls
					icon={ ICON }
					{ ...this.props }
				/>
				{ mode &&
					<Fragment>
						<SpeakersInspectorControls { ...this.props } />
						<SpeakersToolbar { ...this.props } />
					</Fragment>
				}
			</Fragment>
		);
	}
}

const speakersSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	const entities = {
		wcb_speaker       : getEntities( 'postType', 'wcb_speaker', { _embed: true } ),
		wcb_speaker_group : getEntities( 'taxonomy', 'wcb_speaker_group' ),
		wcb_track         : getEntities( 'taxonomy', 'wcb_track' ),
	};

	return {
		blockData : blockData,
		entities  : entities,
	};
};

export const edit = withSelect( speakersSelect )( SpeakersEdit );
