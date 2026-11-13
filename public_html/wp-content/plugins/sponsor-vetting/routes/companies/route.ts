import { __ } from '@wordpress/i18n';

export const route = {
	title: () => __( 'Sponsor Vetting', 'wordcamporg' ),

	/* The company panel opens beside the list while the address names a company. */
	inspector: ( { search }: { search: { company?: unknown } } ) => Boolean( search.company ),
};
