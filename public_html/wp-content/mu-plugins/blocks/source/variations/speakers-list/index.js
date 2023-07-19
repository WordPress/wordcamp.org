/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import SpeakersQueryIcon from '../../icons/speakers-query';

const NAMESPACE = 'wordcamp/speakers-query';
const POST_TYPE = 'wcb_speaker';

registerBlockVariation( 'core/query', {
	name: NAMESPACE,
	title: __( 'Speakers List', 'wordcamporg' ),
	description: 'Display a list of speakers',
	isActive: [ 'namespace' ],
	icon: SpeakersQueryIcon,
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
				[ 'wordcamp/speaker-sessions', { hasSessionDetails: true, isLink: true } ],
			],
		],
		[ 'core/query-pagination' ],
	],
	allowedControls: [ 'inherit', 'order', 'taxQuery', 'search' ],
	scope: [ 'inserter' ],
} );
