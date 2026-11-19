/**
 * The route of the vetting screen.
 */

import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { resolveSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { COMPANIES_QUERY, COMPANY, SPONSORSHIP, SPONSORSHIPS_QUERY } from './entities';
import { fetchPagesAtOnce } from './fetch-pages';

apiFetch.use( fetchPagesAtOnce );

/**
 * The route: its title, what it starts loading, and whether it has a canvas.
 */
export const route = {
	title: () => __( 'Sponsor Vetting', 'wordcamporg' ),

	/*
	 * Starts loading the list the address shows while the screen's script loads, and the other list once that's
	 * in, so the first waits for nothing else. Not awaited, so nothing waits. The statuses come with the page: see
	 * preload_post_types().
	 */
	loader: ( { search }: { search?: { tab?: unknown } } = {} ) => {
		const { getEntityRecords } = resolveSelect( coreStore );
		const loadSponsorships = () => getEntityRecords( 'postType', SPONSORSHIP, SPONSORSHIPS_QUERY );
		const loadCompanies = () => getEntityRecords( 'postType', COMPANY, COMPANIES_QUERY );
		const [ first, then ] =
			search?.tab === 'companies'
				? [ loadCompanies, loadSponsorships ]
				: [ loadSponsorships, loadCompanies ];

		// The list shows its own error, so the other still loads if it fails.
		void first().then( then, then );
	},

	/*
	 * The record the address names fills the canvas, which narrows the list beside it. Null asks for this route's
	 * own canvas; undefined for none.
	 */
	canvas: ( { search }: { search: { company?: unknown; sponsorship?: unknown } } ) =>
		search.company || search.sponsorship ? null : undefined,
};
