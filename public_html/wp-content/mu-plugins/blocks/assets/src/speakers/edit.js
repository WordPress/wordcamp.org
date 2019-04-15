/**
 * External dependencies
 */
import { orderBy, intersection, split } from 'lodash';

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

const speakersSelect = ( select, props ) => {

	const { getEntities } = select( WC_BLOCKS_STORE );

	return {
		blockData       : blockData,
		tracks          : getEntities( 'taxonomy', 'wcb_track' ),
		allSpeakerPosts : getEntities( 'postType', 'wcb_speaker' ),
		allSpeakerTerms : getEntities( 'taxonomy', 'wcb_speaker_group' ),
	};
};

export const edit = withSelect( speakersSelect )( SpeakersEdit );
