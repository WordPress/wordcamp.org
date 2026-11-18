/**
 * Tests for the fields of the two lists.
 */

import { describe, expect, it } from 'vitest';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { View } from '@wordpress/dataviews';
import { getCompanyFields, getSponsorshipFields } from '../fields';
import type { Company, Sponsorship, Statuses } from '../types';
import {
	COMPANY_LAYOUT_CHOICES,
	COMPANY_VIEW,
	GROUP_BY_STATUS,
	SPONSORSHIP_VIEW,
	applyLayoutChoice,
	getLayoutChoice,
	withoutHiddenFilters,
	withoutTrash,
} from '../view';

const STATUSES: Statuses = {
	company: [
		{ slug: 'sv-needs-vetting', name: 'Needs Vetting' },
		{ slug: 'sv-in-progress', name: 'In Progress' },
		{ slug: 'sv-compliance-req', name: 'Compliance Requested' },
		{ slug: 'sv-compliant', name: 'Compliant' },
	],
	sponsorship: [
		{ slug: 'svs-pending', name: 'Pending' },
		{ slug: 'svs-approved', name: 'Approved' },
		{ slug: 'svs-canceled', name: 'Canceled' },
	],
	labels: {
		'sv-needs-vetting': 'Needs Vetting',
		'sv-in-progress': 'In Progress',
		'sv-compliance-req': 'Compliance Requested',
		'sv-compliant': 'Compliant',
		'svs-pending': 'Pending',
		'svs-approved': 'Approved',
		'svs-canceled': 'Canceled',
		trash: 'Trash',
	},
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
		good_faith: false,
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
	const fields = getCompanyFields( STATUSES );
	const title = fields.find( ( { id } ) => id === 'title' );
	const view = { ...COMPANY_VIEW, ...changes } as View;
	const { data } = filterSortAndPaginate(
		withoutTrash( COMPANIES, view ),
		withoutHiddenFilters( view, fields ),
		fields
	);

	return data.map( ( item ) => title?.getValue?.( { item } ) );
}

/**
 * Lists the sponsorships as a view of the events shows them.
 *
 * @param view The view.
 * @return The names of the listed sponsorships, in order.
 */
function listSponsorships( view: View ): string[] {
	const fields = getSponsorshipFields( STATUSES, COMPANIES, SPONSORSHIPS, '2026-11-13' );
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

	it( 'changes layout from the menu: grouping keeps the table’s columns, the list starts from its own', () => {
		const [ table, grouped, list ] = COMPANY_LAYOUT_CHOICES;
		const narrowed: View = { ...COMPANY_VIEW, fields: [ 'status' ], page: 3 };
		const byStatus = applyLayoutChoice( narrowed, grouped );

		expect( getLayoutChoice( COMPANY_VIEW, COMPANY_LAYOUT_CHOICES ) ).toBe( table );
		expect( byStatus ).toMatchObject( {
			type: 'table',
			fields: [ 'status' ],
			groupBy: GROUP_BY_STATUS,
			page: 1,
		} );
		expect( getLayoutChoice( byStatus, COMPANY_LAYOUT_CHOICES ) ).toBe( grouped );

		const asList = applyLayoutChoice( byStatus, list );

		expect( asList ).toMatchObject( { type: 'list', fields: list.fields } );
		expect( asList.groupBy ).toBeUndefined();
		expect( getLayoutChoice( asList, COMPANY_LAYOUT_CHOICES ) ).toBe( list );
	} );

	it( 'heads a group with the status’s label', () => {
		const status = getCompanyFields( STATUSES ).find( ( { id } ) => id === 'status' );

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
			'statuses, which a filter names by slug so it outlives their labels',
			{ filters: [ { field: 'status_filter', operator: 'isNone', value: [ 'sv-needs-vetting' ] } ] },
			[ 'Maple Digital' ],
		],
		[
			'no filter it has no control for, like one a kept view holds on the status’s label',
			{ filters: [ { field: 'status', operator: 'isAny', value: [ 'Compliance Requested' ] } ] },
			[ 'Acme Hosting', 'Maple Digital', 'Harbor & Sons' ],
		],
		[
			'the Trash, which only its own filter shows',
			{ filters: [ { field: 'status_filter', operator: 'isAny', value: [ 'trash' ] } ] },
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

	it( 'shows a sponsorship as its sponsor, with one status: its own once decided, until then what it waits for', () => {
		const rows = [
			...SPONSORSHIPS,
			sponsorship( 6, 'Stranger — Camp Soon', 0, 'Camp Soon', '2026-11-23', 'svs-canceled' ),
			{
				...sponsorship( 7, 'Maple at Soon', 2, 'Camp Soon', '2026-11-23', 'svs-approved' ),
				good_faith: true,
			},
			sponsorship( 8, 'Vetted at Soon', 5, 'Camp Soon', '2026-11-23' ),
		];
		const vetted: Company = {
			...COMPANIES[ 0 ],
			id: 5,
			title: { raw: 'Vetted', rendered: 'Vetted' },
			status: 'sv-compliant',
		};
		const fields = getSponsorshipFields( STATUSES, [ ...COMPANIES, vetted ], rows, '2026-11-13' );
		const read = ( id: string, item: Sponsorship ) =>
			fields.find( ( field ) => field.id === id )?.getValue?.( { item } );

		expect(
			rows.map( ( item ) => [
				read( 'title', item ),
				read( 'status', item ),
				read( 'status_filter', item ),
			] )
		).toEqual( [
			[ 'Maple Digital', 'Approved', 'svs-approved' ],
			[ 'Acme Hosting', 'Needs Vetting', 'sv-needs-vetting' ],
			[ 'Stranger at Soon', 'Needs a company', 'needs-company' ],
			[ 'Acme Hosting', 'Approved', 'svs-approved' ],
			[ 'Harbor & Sons', 'Needs Vetting', 'sv-needs-vetting' ],
			[ 'Stranger', 'Canceled', 'svs-canceled' ],
			[ 'Maple Digital', 'Approved in Good Faith', 'good-faith' ],
			[ 'Vetted', 'Pending', 'svs-pending' ],
		] );
	} );

	it( 'counts the sponsorships waiting for a company in the filter that narrows to them', () => {
		const waiting = getSponsorshipFields( STATUSES, COMPANIES, SPONSORSHIPS, '2026-11-13' ).find(
			( { id } ) => id === 'needs_company'
		);
		const narrowed: View = {
			...SPONSORSHIP_VIEW,
			filters: [ { field: 'needs_company', operator: 'is', value: 'yes' } ],
		};

		expect( waiting?.label ).toBe( 'Needs a company (1)' );
		expect( listSponsorships( narrowed ) ).toEqual( [ 'Stranger at Soon' ] );
	} );
} );
