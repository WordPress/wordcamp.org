/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import OrganizersQueryIcon from '../../icons/organizers-query';

const NAMESPACE = 'wordcamp/organizers-query';
const POST_TYPE = 'wcb_organizer';

registerBlockVariation( 'core/query', {
	name: NAMESPACE,
	title: __( 'Organizers List', 'wordcamporg' ),
	description: 'Display a list of organizers',
	isActive: [ 'namespace' ],
	icon: OrganizersQueryIcon,
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
						[ 'core/paragraph', { content: __( 'Team:', 'wordcamporg' ) } ],
						[ 'core/post-terms', { term: 'wcb_organizer_team' } ],
					],
				],
			],
		],
		[ 'core/query-pagination' ],
	],
	allowedControls: [ 'inherit', 'order', 'taxQuery', 'search' ],
	scope: [ 'inserter' ],
} );
