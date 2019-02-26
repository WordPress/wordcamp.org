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
					{ ...this.state }
				/>
				{ mode &&
				<Fragment>
					<SponsorInspectorControls {...this.props} />
				</Fragment>
				}
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

	const { mode, post_ids, sort } = props.attributes;

	const query = {
		orderby : 'title',
		order   : 'asc',
		per_page: MAX_PAGE,
		_embed  : true,
	};

	if ( Array.isArray( post_ids ) ) {
		query.include = post_ids;
	}

	let sponsorPosts = getEntityRecords( 'postType', 'wcb_sponsor', query );

	return {
		sponsorPosts
	}
};

export const edit = withSelect( sponsorSelect )( SponsorsEdit );