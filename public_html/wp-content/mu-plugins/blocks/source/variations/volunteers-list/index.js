/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';

const NAMESPACE = 'wordcamp/volunteers-query';
const POST_TYPE = 'wcb_volunteer';

registerBlockVariation( 'core/query', {
	name: NAMESPACE,
	title: __( 'Volunteers List', 'wordcamporg' ),
	description: 'Display a list of volunteers',
	isActive: [ 'namespace' ],
	icon: 'hammer',
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
				[
					'core/group',
					{ layout: { type: 'flex', flexWrap: 'nowrap' } },
					[
						[ 'wordcamp/avatar', { size: 96 } ],
						[ 'core/post-title', { isLink: true } ],
					],
				],
				[ 'core/post-excerpt' ],
				[
					'core/group',
					{ layout: { type: 'flex', flexWrap: 'wrap' } },
					[
						[
							'core/paragraph',
							{ content: __( 'Team:', 'wordcamporg' ) },
						],
						[ 'core/post-terms', { term: 'wcb_volunteer_team' } ],
					],
				],
			],
		],
		[ 'core/query-pagination' ],
	],
	allowedControls: [
		'inherit',
		'order',
		'taxQuery',
		'search',
		'postCount',
		'offset',
		'pages',
	],
	scope: [ 'inserter' ],
} );
