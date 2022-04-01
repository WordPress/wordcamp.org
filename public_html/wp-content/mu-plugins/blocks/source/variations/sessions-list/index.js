/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { registerBlockVariation } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import OrderControl from './order-control';

const NAMESPACE = 'wordcamp/sessions-query';
const POST_TYPE = 'wcb_session';

registerBlockVariation( 'core/query', {
	name: NAMESPACE,
	title: __( 'Sessions List', 'wordcamporg' ),
	description: 'Display a list of sessions',
	isActive: [ 'namespace' ],
	attributes: {
		namespace: NAMESPACE,
		query: {
			perPage: 10,
			pages: 0,
			offset: 0,
			postType: POST_TYPE,
			order: 'asc',
			orderBy: 'session_date',
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
				[ 'core/post-title' ],
				[ 'wordcamp/session-speakers' ],
				[ 'core/post-excerpt' ],
				[
					'core/group',
					{ layout: { type: 'flex', allowOrientation: false } },
					[
						[ 'core/paragraph', { content: __( 'Tracks:', 'wordcamporg' ) } ],
						[ 'core/post-terms', { term: 'wcb_track' } ],
					],
				],
				[
					'core/group',
					{ layout: { type: 'flex', allowOrientation: false } },
					[
						[ 'core/paragraph', { content: __( 'Time:', 'wordcamporg' ) } ],
						[ 'wordcamp/session-date' ],
					],
				],
			],
		],
		[ 'core/query-pagination' ],
	],
	// Omit `order` in favor of our custom OrderControl.
	allowedControls: [ 'inherit', 'taxQuery', 'search' ],
	scope: [ 'inserter' ],
} );

/**
 * Inject the OrderControl component into the Sessions List query variation.
 *
 * @param {Function} BlockEdit Original component
 * @return {Function}           Wrapped component
 */
const sessionQueryOrder = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		const { attributes, name, setAttributes } = props;
		const { query = {}, namespace } = attributes;

		if ( name !== 'core/query' || namespace !== NAMESPACE ) {
			return <BlockEdit key="edit" { ...props } />;
		}

		const { order, orderBy } = query;
		const updateQuery = ( newQuery ) => setAttributes( { query: { ...query, ...newQuery } } );

		return (
			<>
				<BlockEdit key="edit" { ...props } />
				<InspectorControls>
					<PanelBody title={ __( 'Order by', 'wordcamporg' ) }>
						<OrderControl order={ order } orderBy={ orderBy } onChange={ updateQuery } />
					</PanelBody>
				</InspectorControls>
			</>
		);
	},
	'withInspectorControls'
);

addFilter( 'editor.BlockEdit', 'wordcamp/session-query', sessionQueryOrder );
