/**
 * External dependencies
 */
import { isUndefined, pickBy, split } from 'lodash';

/**
 * WordPress dependencies
 */
//const apiFetch                = wp.apiFetch;
//const { withSelect }          = wp.data;
const { Component, Fragment } = wp.element;
//const { addQueryArgs }        = wp.url;

/**
 * Internal dependencies
 */
import ScheduleBlockContent      from './block-content';
import ScheduleInspectorControls from './inspector-controls';
import { ICON }                  from './index';

// todo need to diff against other blocks to pull in latest changes


const blockData = window.WordCampBlocks.sessions || {}; // todo does this already exist b/c sessions block is loaded, so don't need to do any queries to fetch it?
														// is there any other input, like the day? i guess that's fetched automatically, or derived from sessions. and then configured in inspector controls
const MAX_POSTS = 100;  // doulbe this? todo

//const ALL_POSTS_QUERY = {
//	orderby  : 'title',
//	order    : 'asc',
//	per_page : MAX_POSTS,
//};
//
//const ALL_TERMS_QUERY = {
//	orderby  : 'name',
//	order    : 'asc',
//	per_page : MAX_POSTS,
//};

class ScheduleEdit extends Component {
	constructor( props ) {
		super( props );

		//console.log( 'data', blockData );
		//console.log( 'props', props );

		this.state = {
			//allSessionPosts : null,
			//allSessionTerms : null,
		};

		//this.fetchOrganizerDetails();
	}

	// rebased this against origin/vedanshu-store to get new data stuff, so make sure that PR is merged to master first, and that this one doesn't introduce any artifcats (git diff master and check each line)

	//fetchOrganizerDetails() {
	//	const allSessionPosts = apiFetch( {
	//		path: addQueryArgs( '/wp/v2/sessions', ALL_POSTS_QUERY ),
	//	} );
	//
	//	const allSessionTerms = apiFetch( {
	//		path: addQueryArgs( '/wp/v2/session_track', ALL_TERMS_QUERY ),
	//	} );
	//
	//	this.state = {
	//		allSessionPosts : allSessionPosts, // Promise
	//		allSessionTerms : allSessionTerms, // Promise
	//	}
	//}


	render() {
		return (
			<Fragment>
				<ScheduleBlockContent
					icon={ ICON }
					{ ...this.props }
					{ ...this.state }
				/>

				<ScheduleInspectorControls
					blockData={ blockData }
					{ ...this.props }
				/>
			</Fragment>
		);
	}
}

const scheduleSelect = ( select, props ) => {
	// don't need this bc already have sessions fetched?

	const { mode, item_ids, sort } = props.attributes;
	const { getEntityRecords }     = select( 'core' );
	const [ orderby, order ]       = split( sort, '_', 2 );

	const args = {
		orderby  : orderby,
		order    : order,
		per_page : MAX_POSTS, // -1 is not allowed for per_page.
		context  : 'view',
	};

	if ( Array.isArray( item_ids ) ) {
		switch ( mode ) {
			case 'wcb_organizer':
				args.include = item_ids;
				break;
			case 'wcb_organizer_team':
				args.organizer_team = item_ids;
				break;
		}
	}

	const organizersQuery = pickBy(
		args,
		( value ) => ! isUndefined( value )
	);

	return {
		blockData      : blockData,
		organizerPosts : getEntityRecords( 'postType', 'wcb_organizer', organizersQuery ),
	};
};

//export const edit = withSelect( scheduleSelect )( ScheduleEdit );
export const edit = ScheduleEdit;
