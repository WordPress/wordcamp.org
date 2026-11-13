import { describe, expect, it } from 'vitest';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { View } from '@wordpress/dataviews';
import { getCompanyFields, getSponsorshipFields } from '../fields';
import type { Company, Sponsorship } from '../types';
import { COMPANY_VIEW, GROUP_BY_STATUS, SPONSORSHIP_VIEW, toggleNeedsCompany, withoutTrash } from '../view';

const LABELS = {
	'sv-needs-vetting': 'Needs Vetting',
	'sv-in-progress': 'In Progress',
	'sv-compliance-req': 'Compliance Requested',
	'sv-compliant': 'Compliant',
	'svs-pending': 'Pending',
	'svs-approved': 'Approved',
	trash: 'Trash',
};

const COMPANIES: Company[] = [
	{
		id: 1,
		title: { raw: 'Harbor &amp; Sons', rendered: 'Harbor &#038; Sons' },
		status: 'sv-needs-vetting',
		domains: [ 'harbor.test' ],
		flags: [],
		next_event: '',
		sponsorships: 0,
		last_vetted: '2026-10-01',
		trashed_status: '',
	},
	{
		id: 2,
		title: { raw: 'Maple Digital', rendered: 'Maple Digital' },
		status: 'sv-compliance-req',
		domains: [ 'maple.test', 'maple-digital.test' ],
		flags: [ { type: 'replied', text: 'Sponsor replied' } ],
		next_event: '2027-01-15',
		sponsorships: 1,
		last_vetted: '',
		trashed_status: '',
	},
	{
		id: 3,
		title: { raw: 'Acme Hosting', rendered: 'Acme Hosting' },
		status: 'sv-needs-vetting',
		domains: [ 'acme.test' ],
		flags: [],
		next_event: '2026-11-23',
		sponsorships: 2,
		last_vetted: '',
		trashed_status: '',
	},
	{
		id: 4,
		title: { raw: 'Binned Co', rendered: 'Binned Co' },
		status: 'trash',
		domains: [],
		flags: [],
		next_event: '',
		sponsorships: 0,
		last_vetted: '',
		trashed_status: 'sv-in-progress',
	},
];

/**
 * Builds a sponsorship of one of the companies, or of none, for an event.
 *
 * @param id      Its ID.
 * @param name    Its name.
 * @param company Its company's ID, or 0.
 * @param event   The event's name.
 * @param date    When the event starts, as `Y-m-d`, or an empty string.
 * @param status  Its status.
 * @return The sponsorship.
 */
function sponsorship(
	id: number,
	name: string,
	company: number,
	event: string,
	date: string,
	status: Sponsorship[ 'status' ] = 'svs-pending'
): Sponsorship {
	return {
		id,
		title: { raw: name, rendered: name },
		status,
		company,
		event: { name: event, date },
		approved: '',
		trashed_status: '',
	};
}

const SPONSORSHIPS: Sponsorship[] = [
	sponsorship( 1, 'Maple at Past', 2, 'Camp Past', '2026-11-01', 'svs-approved' ),
	sponsorship( 2, 'Acme at Later', 3, 'Camp Later', '2027-01-15' ),
	sponsorship( 3, 'Stranger at Soon', 0, 'Camp Soon', '2026-11-23' ),
	sponsorship( 4, 'Acme at Soon', 3, 'Camp Soon', '2026-11-23', 'svs-approved' ),
	sponsorship( 5, 'Harbor at Undated', 1, 'Camp Undated', '' ),
];

/**
 * Lists the companies as a view shows them.
 *
 * @param changes What the view changes from the default.
 * @return The names of the listed companies, in order.
 */
function listCompanies( changes: Partial< View > = {} ): string[] {
	const fields = getCompanyFields( LABELS );
	const title = fields.find( ( { id } ) => id === 'title' );
	const view = { ...COMPANY_VIEW, ...changes } as View;
	const { data } = filterSortAndPaginate( withoutTrash( COMPANIES, view, LABELS.trash ), view, fields );

	return data.map( ( item ) => title?.getValue?.( { item } ) );
}

/**
 * Lists the sponsorships as a view of the events shows them.
 *
 * @param view The view.
 * @return The names of the listed sponsorships, in order.
 */
function listSponsorships( view: View ): string[] {
	const fields = getSponsorshipFields( LABELS, COMPANIES, SPONSORSHIPS, '2026-11-13' );
	const { data } = filterSortAndPaginate( SPONSORSHIPS, view, fields );

	return data.map( ( { title } ) => title.raw );
}

describe( 'the company list', () => {
	it( 'opens sorted by next event, with companies that have none last, names unescaped, and the Trash left out', () => {
		expect( listCompanies() ).toEqual( [ 'Acme Hosting', 'Maple Digital', 'Harbor & Sons' ] );
	} );

	it( 'groups by status in the order of the pipeline, not of the alphabet', () => {
		expect( listCompanies( { groupBy: GROUP_BY_STATUS } ) ).toEqual( [
			'Acme Hosting',
			'Harbor & Sons',
			'Maple Digital',
		] );
		expect( listCompanies( { groupBy: { ...GROUP_BY_STATUS, direction: 'desc' } } ) ).toEqual( [
			'Maple Digital',
			'Acme Hosting',
			'Harbor & Sons',
		] );
	} );

	it( 'heads a group with the status’s label', () => {
		const status = getCompanyFields( LABELS ).find( ( { id } ) => id === 'status' );

		expect( status?.getValue?.( { item: COMPANIES[ 1 ] } ) ).toBe( 'Compliance Requested' );
	} );

	it.each( [
		[ 'a company’s second website', { search: 'maple-digital' }, [ 'Maple Digital' ] ],
		[
			'a follow-up',
			{ filters: [ { field: 'follow_up', operator: 'isAny', value: [ 'replied' ] } ] },
			[ 'Maple Digital' ],
		],
		[
			'statuses',
			{ filters: [ { field: 'status', operator: 'isNone', value: [ 'Needs Vetting' ] } ] },
			[ 'Maple Digital' ],
		],
		[
			'the Trash, which only its own filter shows',
			{ filters: [ { field: 'status', operator: 'isAny', value: [ 'Trash' ] } ] },
			[ 'Binned Co' ],
		],
	] as [ string, Partial< View >, string[] ][] )( 'finds companies by %s', ( name, view, expected ) => {
		expect( listCompanies( view ) ).toEqual( expected );
	} );
} );

describe( 'the list of events', () => {
	it( 'opens by event, the soonest still to come first and past ones last, with what’s pending first in each', () => {
		expect( listSponsorships( SPONSORSHIP_VIEW ) ).toEqual( [
			'Stranger at Soon',
			'Acme at Soon',
			'Acme at Later',
			'Harbor at Undated',
			'Maple at Past',
		] );
	} );

	it( 'narrows to the sponsorships waiting for a company, and back', () => {
		const narrowed = toggleNeedsCompany( SPONSORSHIP_VIEW, true );

		expect( listSponsorships( narrowed ) ).toEqual( [ 'Stranger at Soon' ] );
		expect( listSponsorships( toggleNeedsCompany( narrowed, false ) ) ).toHaveLength( SPONSORSHIPS.length );
	} );
} );
