/**
 * Displays sponsor block.
 */
import SponsorInspectorControls from './inspector-controls';
import SponsorBlocksControls from './block-controls';

/**
 WordPress dependencies.
 **/
const { withSelect } = wp.data;
const { Component, Fragment } = wp.element;
const apiFetch = wp.apiFetch;
const { addQueryArgs } = wp.url;

const MAX_PAGE = 100;

class SponsorsEdit extends Component {

	/**
	 * Constructor for SponsorsEdit block.
	 *
	 * @param props
	 */
	constructor( props ) {
		super( props );
	}

	/**
	 * Renders SponsorEdit component.
	 */
	render() {

		const { mode } = this.props.attributes;

		return (
			<Fragment>
				<SponsorBlocksControls
					{ ...this.props }
				/>
				<Fragment>
					<SponsorInspectorControls {...this.props} />
				</Fragment>
			</Fragment>
		)
	}
}

/**
 * API call for wcb_sponsor post type data.
 *
 * @param select
 * @param props
 */
const sponsorSelect = ( select, props ) => {
	const { getEntityRecords } = select( 'core' );

	const { mode, post_ids, sort, term_ids } = props.attributes;

	const sponsorQuery = {
		orderby : 'title',
		order   : 'asc',
		per_page: MAX_PAGE,
		_embed  : true,
	};

	if ( Array.isArray( post_ids ) ) {
		sponsorQuery.include = post_ids;
	}

	const sponsorLevelQuery = {
		orderby : 'id',
		order: 'asc',
		per_page: MAX_PAGE,
		_embed: true
	};

	if ( Array.isArray( term_ids ) ) {
		sponsorLevelQuery.include = term_ids;
	}

	return {
		sponsorPosts: apiFetch( { path: addQueryArgs( '/wp/v2/sponsors', sponsorQuery ) } ),
		sponsorLevels: apiFetch( { path: addQueryArgs('/wp/v2/sponsor_level', sponsorLevelQuery ) } )
	}
};

export const edit = withSelect( sponsorSelect )( SponsorsEdit );