import type { Badge } from '@wordpress/ui';
import type { ComponentProps } from 'react';

/*
 * Companies and sponsorships are post types, read and edited through core's own REST endpoints and core-data. What
 * the endpoints add for them is in `includes/rest.php`.
 */
export const COMPANY = 'sv_company';
export const SPONSORSHIP = 'sv_sponsorship';

/* The vetting pipeline, in the order companies move through it. */
export const COMPANY_STATUSES = [
	'sv-needs-vetting',
	'sv-in-progress',
	'sv-compliance-req',
	'sv-compliant',
	'sv-not-eligible',
	'sv-inactive',
] as const;

export const SPONSORSHIP_STATUSES = [ 'svs-pending', 'svs-approved', 'svs-not-approved', 'svs-canceled' ] as const;

export const TRASH = 'trash';

export type CompanyStatus = ( typeof COMPANY_STATUSES )[ number ];
export type SponsorshipStatus = ( typeof SPONSORSHIP_STATUSES )[ number ];
export type AnyStatus = CompanyStatus | SponsorshipStatus | typeof TRASH;

type Intent = NonNullable< ComponentProps< typeof Badge >[ 'intent' ] >;

export const STATUS_INTENTS: Record< AnyStatus, Intent > = {
	'sv-needs-vetting': 'informational',
	'sv-in-progress': 'draft',
	'sv-compliance-req': 'low',
	'sv-compliant': 'stable',
	'sv-not-eligible': 'high',
	'sv-inactive': 'none',
	'svs-pending': 'none',
	'svs-approved': 'stable',
	'svs-not-approved': 'high',
	'svs-canceled': 'draft',
	trash: 'none',
};

/*
 * Every company, with only what the list shows. The statuses are named because the endpoint lists published posts
 * unless told otherwise, and `any` leaves these statuses out. One object, since core-data caches by query.
 */
export const COMPANIES_QUERY = {
	status: [ ...COMPANY_STATUSES, TRASH ],
	per_page: -1,
	_fields: [
		'id',
		'title',
		'status',
		'domains',
		'flags',
		'next_event',
		'sponsorships',
		'last_vetted',
		'trashed_status',
	],
};

/* Every sponsorship, with only what the list of events shows. */
export const SPONSORSHIPS_QUERY = {
	status: [ ...SPONSORSHIP_STATUSES, TRASH ],
	per_page: -1,
	_fields: [ 'id', 'title', 'status', 'company', 'event', 'approved', 'trashed_status' ],
};

/**
 * Builds the query for one company's sponsorships, with what its panel shows of them.
 *
 * @param companyId The company.
 * @return The query.
 */
export function getSponsorshipsQuery( companyId: number ) {
	return {
		parent: [ companyId ],
		status: SPONSORSHIP_STATUSES,
		per_page: -1,
		_fields: [ 'id', 'title', 'status', 'event', 'approved', 'log' ],
	};
}
