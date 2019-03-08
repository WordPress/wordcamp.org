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
		this.state = {};
	}

	componentWillMount() {
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

		this.setState(
			{
				sponsorPosts: apiFetch( { path: addQueryArgs( '/wp/v2/sponsors', sponsorQuery ) } ),
				sponsorLevels: apiFetch( { path: addQueryArgs('/wp/v2/sponsor_level', sponsorLevelQuery ) } )
			}
		);
	}

	/**
	 * Renders SponsorEdit component.
	 */
	render() {
		const { sponsorPosts, sponsorLevels } = this.state;

		return (
			<Fragment>
				{
					<SponsorBlockControls
						sponsorPosts = { sponsorPosts }
						sponsorLevels = { sponsorLevels }
						{ ...this.props }
					/>
				}
				<Fragment>
					<SponsorInspectorControls
						sponsorPosts = { sponsorPosts }
						sponsorLevels = { sponsorLevels }
						{...this.props} />
				</Fragment>
			</Fragment>
		)
	}
}

export const edit = SponsorsEdit;