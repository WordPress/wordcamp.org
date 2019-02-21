/**
 * External dependencies
 */
import { isUndefined, pickBy, split } from 'lodash';

/**
 * WordPress dependencies
 */
const apiFetch = wp.apiFetch;
const { withSelect } = wp.data;
const { Component, Fragment } = wp.element;
const { addQueryArgs } = wp.url;

/**
 * Internal dependencies
 */
import SessionsBlockControls from "./block-controls";
import SessionsInspectorControls from "./inspector-controls";

const blockData = window.WordCampBlocks.sessions || {};

const SESSIONS_ICON = 'list-view';
const MAX_POSTS = 100;

const ALL_POSTS_QUERY = {
	orderby  : 'title',
	order    : 'asc',
	per_page : MAX_POSTS,
	_embed   : true,
};

const ALL_TERMS_QUERY = {
	orderby  : 'name',
	order    : 'asc',
	per_page : MAX_POSTS,
};

class SessionsEdit extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			allSessionPosts      : null,
			allSessionTracks     : null,
			allSessionCategories : null,
		};
	}

	componentWillMount() {
		this.isStillMounted = true;

		const allSessionPosts = apiFetch( {
			path: addQueryArgs( `/wp/v2/sessions`, ALL_POSTS_QUERY ),
		} );
		const allSessionTracks = apiFetch( {
			path: addQueryArgs( `/wp/v2/session_track`, ALL_TERMS_QUERY ),
		} );
		const allSessionCategories = apiFetch( {
			path: addQueryArgs( `/wp/v2/session_category`, ALL_TERMS_QUERY ),
		} );

		if ( this.isStillMounted ) {
			this.setState( {
				allSessionPosts      : allSessionPosts, // Promise
				allSessionTracks     : allSessionTracks, // Promise
				allSessionCategories : allSessionCategories, // Promise
			} );
		}
	}

	componentWillUnmount() {
		this.isStillMounted = false;
	}

	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<SessionsBlockControls
					icon={ SESSIONS_ICON }
					{ ...this.props }
					{ ...this.state }
				/>
				{ mode &&
				<Fragment>
					<SessionsInspectorControls { ...this.props } />
				</Fragment>
				}
			</Fragment>
		);
	}
}

const sessionsSelect = ( select, props ) => {
	const { mode, post_ids, track_ids, category_ids, sort, filter_date } = props.attributes;
	const { getEntityRecords } = select( 'core' );

	const args = {
		per_page           : MAX_POSTS, // -1 is not allowed for per_page.
		_embed             : true,
		context            : 'view',
		_wcpt_session_type : 'session',
	};

	if ( 'session_time' !== sort ) {
		const [ orderby, order ] = split( sort, '_', 2 );
		args.orderby = orderby;
		args.order = order;
	}

	if ( 'specific_posts' === mode && Array.isArray( post_ids ) ) {
		args.include = post_ids;
	}

	if ( 'specific_tracks' === mode && Array.isArray( track_ids ) ) {
		args.session_track = track_ids;
	}

	if ( 'specific_categories' === mode && Array.isArray( category_ids ) ) {
		args.session_category = category_ids;
	}

	const sessionsQuery = pickBy( args, ( value ) => ! isUndefined( value ) );

	let sessionPosts = getEntityRecords( 'postType', 'wcb_session', sessionsQuery );

	// todo Is there a way to do this filtering and sorting via REST API parameters?
	if ( filter_date ) {
		sessionPosts.filter( ( session ) => {
			const { _wcpt_session_time } = session;
			const startDate = new Date( filter_date );
			const endDate = new Date( filter_date ).setDate( startDate.getDate() + 1 );

			return _wcpt_session_time >= startDate && _wcpt_session_time <= endDate;
		} );
	}

	if ( 'session_time' === sort ) {
		sessionPosts.sort( ( a, b ) => {
			return Number( a._wcpt_session_time ) - Number( b._wcpt_session_time );
		} );
	}

	return { blockData, sessionPosts };
};

export const edit = withSelect( sessionsSelect )( SessionsEdit );
