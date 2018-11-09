/**
 * WordPress dependencies
 */
const { withSelect } = wp.data;
const { Component, Fragment } = wp.element;

/**
 * Internal dependencies
 */
import SpeakersInspectorControls from './inspector-controls';
import SpeakersBlockControls from './block-controls';

const MAX_POSTS = 100;

class SpeakersEdit extends Component {
	render() {
		return (
			<Fragment>
				<SpeakersInspectorControls { ...this.props } />
				<SpeakersBlockControls { ...this.props } />
			</Fragment>
		);
	}
}

const speakersSelect = ( select, props ) => {
	const { mode, post_ids, term_ids, sort } = props.attributes;
	const { getEntityRecords } = select( 'core' );
	const [ orderby, order ] = _.split( sort, '_', 2 );

	const args = {
		orderby: orderby,
		order: order,
		per_page: MAX_POSTS, // -1 is not allowed for per_page.
		_embed: true,
	};

	if ( 'specific_posts' === mode && Array.isArray( post_ids ) ) {
		args.include = post_ids;
	}

	if ( 'specific_terms' === mode && Array.isArray( term_ids ) ) {
		args.filter.taxonomy = 'wcb_speaker_group';
		args.filter.term = term_ids;
	}

	const speakersQuery = _.pickBy( args, ( value ) => ! _.isUndefined( value ) );

	return {
		speakerPosts: getEntityRecords( 'postType', 'wcb_speaker', speakersQuery ),
	};
};

export const edit = withSelect( speakersSelect )( SpeakersEdit );
