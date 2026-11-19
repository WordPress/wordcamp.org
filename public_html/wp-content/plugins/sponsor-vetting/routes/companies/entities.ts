/**
 * The two post types, what their statuses convey, and the queries the screen reads them with.
 *
 * Companies and sponsorships are post types, read and edited through core's own REST endpoints and core-data. What
 * the endpoints add for them is in `includes/rest.php`.
 */

import type { Badge } from '@wordpress/ui';
import type { ComponentProps } from 'react';

/**
 * The company post type.
 */
export const COMPANY = 'sv_company';

/**
 * The sponsorship post type.
 */
export const SPONSORSHIP = 'sv_sponsorship';

/**
 * Core's status of a trashed record.
 */
export const TRASH = 'trash';

/**
 * Every status a post type has, the Trash aside. The screen learns which those are from the server, so a list
 * asks for them this way: see list_any_status() in `includes/rest.php`.
 */
export const ANY = 'any';

export type Intent = NonNullable< ComponentProps< typeof Badge >[ 'intent' ] >;

/**
 * What each status's badge conveys. One that isn't here conveys nothing.
 */
export const STATUS_INTENTS: Record< string, Intent > = {
	'sv-needs-vetting': 'informational',
	'sv-in-progress': 'draft',
	'sv-compliance-req': 'low',
	'sv-on-hold': 'medium',
	'sv-compliant': 'stable',
	'sv-not-eligible': 'high',
	'sv-inactive': 'none',
	'svs-pending': 'none',
	'svs-approved': 'stable',
	'svs-not-approved': 'high',
	'svs-canceled': 'draft',
	trash: 'none',
};

/**
 * Every company, with only what the list shows. The endpoint lists published posts unless told otherwise, which
 * these never are. One object, since core-data caches by query.
 */
export const COMPANIES_QUERY = {
	status: [ ANY, TRASH ],
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

/**
 * Every sponsorship, with only what the list of events shows.
 */
export const SPONSORSHIPS_QUERY = {
	status: [ ANY, TRASH ],
	per_page: -1,
	_fields: [ 'id', 'title', 'status', 'company', 'event', 'approved', 'good_faith', 'trashed_status' ],
};

/**
 * Builds the query for one company's sponsorships, with what its list of events shows of each.
 *
 * @param companyId The company.
 * @return The query.
 */
export function getSponsorshipsQuery( companyId: number ) {
	return {
		parent: [ companyId ],
		status: ANY,
		per_page: -1,
		_fields: [ 'id', 'title', 'status', 'event', 'approved', 'good_faith', 'log' ],
	};
}

/**
 * Builds the query for what a vetter does about some of a company's events: the ones its list shows.
 *
 * Kept apart from the list itself because most of these are looked up on each event's site, which a company with
 * years of events behind it would wait for.
 *
 * @param ids The sponsorships.
 * @return The query.
 */
export function getEventActionsQuery( ids: number[] ) {
	return {
		include: ids,
		status: ANY,
		per_page: -1,
		_fields: [
			'id',
			'allowed_statuses',
			'approvable',
			'domain',
			'approved_domain',
			'website_changed',
			'needs_approval_email',
			'conversations',
			'sponsor',
		],
	};
}
