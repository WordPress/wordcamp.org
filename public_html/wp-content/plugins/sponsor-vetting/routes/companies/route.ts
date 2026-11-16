import { store as coreStore } from '@wordpress/core-data';
import { resolveSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { COMPANIES_QUERY, COMPANY } from './entities';

export const route = {
	title: () => __( 'Sponsor Vetting', 'wordcamporg' ),

	/* Starts the requests both lists make while the screen's script loads. Not awaited, so nothing waits. */
	loader: () => {
		void resolveSelect( coreStore ).getEntityRecords( 'postType', COMPANY, COMPANIES_QUERY );
		void resolveSelect( coreStore ).getEntityRecords( 'root', 'status', {} );
	},

	/*
	 * The record the address names fills the canvas, which narrows the list beside it. Null asks for this route's
	 * own canvas; undefined for none.
	 */
	canvas: ( { search }: { search: { company?: unknown; sponsorship?: unknown } } ) =>
		search.company || search.sponsorship ? null : undefined,
};
