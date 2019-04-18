/**
 * External dependencies
 */
import { isUndefined, pickBy, split } from 'lodash';

/**
 * WordPress dependencies
 */
const apiFetch                = wp.apiFetch;
const { withSelect }          = wp.data;
const { Component, Fragment } = wp.element;
const { addQueryArgs }        = wp.url;

/**
 * Internal dependencies
 */
import OrganizersBlockControls     from './block-controls';
import OrganizersInspectorControls from './inspector-controls';
import OrganizersToolbar           from './toolbar';
import { ICON }                    from './index';
import { WC_BLOCKS_STORE } from '../blocks-store';

const blockData = window.WordCampBlocks.organizers || {};
const MAX_POSTS = 100;

const ALL_POSTS_QUERY = {
	orderby  : 'title',
	order    : 'asc',
	per_page : MAX_POSTS,
};

const ALL_TERMS_QUERY = {
	orderby  : 'name',
	order    : 'asc',
	per_page : MAX_POSTS,
};

class OrganizersEdit extends Component {
	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<OrganizersBlockControls
					icon={ ICON }
					{ ...this.props }
				/>

				{ '' !== mode &&
					<Fragment>
						<OrganizersInspectorControls { ...this.props } />
						<OrganizersToolbar { ...this.props } />
					</Fragment>
				}
			</Fragment>
		);
	}
}

const organizerSelect = ( select ) => {
	const { getEntities } = select( WC_BLOCKS_STORE );

	return {
		blockData         : blockData,
		allOrganizerPosts : getEntities( 'postType', 'wcb_organizer' ),
		allOrganizerTerms : getEntities( 'taxonomy', 'wcb_organizer_team' ),
	};
};

export const edit = withSelect( organizerSelect )( OrganizersEdit );
