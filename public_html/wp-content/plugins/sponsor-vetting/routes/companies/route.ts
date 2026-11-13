import { store as coreStore } from '@wordpress/core-data';
import { resolveSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { COMPANIES_QUERY, COMPANY } from './entities';

export const route = {
	title: () => __( 'Sponsor Vetting', 'wordcamporg' ),

	/* Starts the list's own two requests while the screen's script loads. Not awaited, so nothing waits. */
	loader: () => {
		void resolveSelect( coreStore ).getEntityRecords( 'postType', COMPANY, COMPANIES_QUERY );
		void resolveSelect( coreStore ).getEntityRecords( 'root', 'status', {} );
	},

	/* The company panel opens beside the list while the address names a company. */
	inspector: ( { search }: { search: { company?: unknown } } ) => Boolean( search.company ),
};
