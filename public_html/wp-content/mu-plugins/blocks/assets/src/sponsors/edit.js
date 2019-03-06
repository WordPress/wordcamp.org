/**
 * Displays sponsor block.
 */
import SponsorInspectorControls from './inspector-controls';
import SponsorBlockControls from './block-controls';

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
		this.state = {
			allSponsorPosts : null,
			allSponsorTerms : null,
			isLoading       : null,
		}
	}

	/**
	 * Renders SponsorEdit component.
	 */
	render() {
		const { mode } = this.props.attributes;

		return (
			<Fragment>
				{
					<SponsorBlockControls
						{ ...this.props }
					/>
				}
				<Fragment>
					<SponsorInspectorControls {...this.props} />
				</Fragment>
			</Fragment>
		)
	}
}

/**
 * API call for wcb_sponsor post type data. Fetches all sponsor and terms posts.
 *
 * @param select
 * @param props
 */
const sponsorSelect = ( select, props ) => {
	const { post_ids, term_ids } = props.attributes;

	const sponsorQuery = {
		orderby : 'title',
		order   : 'asc',
		per_page: MAX_PAGE,
		_embed  : true,
	};

	const sponsorLevelQuery = {
		orderby : 'id',
		order: 'asc',
		per_page: MAX_PAGE,
		_embed: true
	};

	return {
		sponsorPosts: apiFetch( { path: addQueryArgs( '/wp/v2/sponsors', sponsorQuery ) } ),
		sponsorLevels: apiFetch( { path: addQueryArgs('/wp/v2/sponsor_level', sponsorLevelQuery ) } )
	}
};

export const edit = withSelect( sponsorSelect )( SponsorsEdit );