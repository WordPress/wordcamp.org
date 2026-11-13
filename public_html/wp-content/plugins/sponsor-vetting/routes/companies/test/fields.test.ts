import { describe, expect, it } from 'vitest';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { View } from '@wordpress/dataviews';
import { getCompanyFields } from '../fields';
import type { Company } from '../types';
import { DEFAULT_VIEW, GROUP_BY_STATUS } from '../view';

const LABELS = {
	'sv-needs-vetting': 'Needs Vetting',
	'sv-in-progress': 'In Progress',
	'sv-compliance-req': 'Compliance Requested',
	'sv-compliant': 'Compliant',
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
	},
];

/**
 * Lists the companies as a view shows them.
 *
 * @param view What the view changes from the default.
 * @return The names of the listed companies, in order.
 */
function list( view: Partial< View > = {} ): string[] {
	const fields = getCompanyFields( LABELS );
	const title = fields.find( ( { id } ) => id === 'title' );
	const { data } = filterSortAndPaginate( COMPANIES, { ...DEFAULT_VIEW, ...view } as View, fields );

	return data.map( ( item ) => title?.getValue?.( { item } ) );
}

describe( 'the company list', () => {
	it( 'opens sorted by next event, with companies that have none last, and names unescaped', () => {
		expect( list() ).toEqual( [ 'Acme Hosting', 'Maple Digital', 'Harbor & Sons' ] );
	} );

	it( 'groups by status in the order of the pipeline, not of the alphabet', () => {
		expect( list( { groupBy: GROUP_BY_STATUS } ) ).toEqual( [
			'Acme Hosting',
			'Harbor & Sons',
			'Maple Digital',
		] );
		expect( list( { groupBy: { ...GROUP_BY_STATUS, direction: 'desc' } } ) ).toEqual( [
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
	] as [ string, Partial< View >, string[] ][] )( 'finds companies by %s', ( name, view, expected ) => {
		expect( list( view ) ).toEqual( expected );
	} );
} );
