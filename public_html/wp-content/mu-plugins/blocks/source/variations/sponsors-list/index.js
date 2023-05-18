/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import SponsorsQueryIcon from '../../icons/sponsors-query';

const NAMESPACE = 'wordcamp/sponsors-query';
const POST_TYPE = 'wcb_sponsor';

registerBlockVariation( 'core/query', {
	name: NAMESPACE,
	title: __( 'Sponsors List', 'wordcamporg' ),
	description: 'Display a list of sponsors',
	isActive: [ 'namespace' ],
	icon: SponsorsQueryIcon,
	attributes: {
		namespace: NAMESPACE,
		query: {
			perPage: 10,
			pages: 0,
			offset: 0,
			postType: POST_TYPE,
			order: 'asc',
			orderBy: 'title',
			author: '',
			search: '',
			exclude: [],
			sticky: '',
			inherit: false,
		},
	},
	innerBlocks: [
		[
			'core/post-template',
			{},
			[
				[ 'core/post-featured-image', { isLink: true } ],
				[ 'core/post-title', { isLink: true } ],
				[ 'core/post-excerpt' ],
			],
		],
		[ 'core/query-pagination' ],
	],
	allowedControls: [ 'inherit', 'order', 'taxQuery', 'search' ],
	scope: [ 'inserter' ],
} );
