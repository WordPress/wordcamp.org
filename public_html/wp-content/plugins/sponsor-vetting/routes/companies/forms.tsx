import type { Field, Form } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { formatDay } from './dates';
import type { AnyStatus } from './entities';
import { StatusBadge, getName } from './fields';
import type { Company, CompanyDetails, Edited, SponsorshipDetails } from './types';

export type CompanyForm = Edited< CompanyDetails >;
export type SponsorshipForm = Edited< SponsorshipDetails >;

type Labels = Record< string, string >;

/**
 * Lists the statuses a record's status picker offers: the one it has, and the ones it may change to.
 *
 * @param current The record's saved status.
 * @param allowed The statuses it may change to.
 * @param labels  The statuses' labels, keyed by status.
 * @return The options.
 */
export function getStatusOptions( current: string, allowed: string[], labels: Labels ) {
	return [ current, ...allowed ].map( ( status ) => ( { value: status, label: labels[ status ] ?? status } ) );
}

type Saved = { status: string; allowed_statuses: string[] };

/**
 * Builds the status field of a record's form.
 *
 * @param record The saved record.
 * @param labels The statuses' labels, keyed by status.
 * @return The field.
 */
function getStatusField< Item extends { status: string } >( record: Saved, labels: Labels ): Field< Item > {
	return {
		id: 'status',
		label: __( 'Status', 'wordcamporg' ),
		elements: getStatusOptions( record.status, record.allowed_statuses, labels ),
		render: ( { item } ) => (
			<StatusBadge status={ item.status as AnyStatus } label={ labels[ item.status ] ?? item.status } />
		),
	};
}

/* Shared by both forms: the note that's added when the record is saved. */
const NOTE = {
	id: 'note',
	type: 'text',
	label: __( 'Add a note', 'wordcamporg' ),
	description: __( 'Private: only vetters see notes. Added when you save.', 'wordcamporg' ),
	Edit: { control: 'textarea', rows: 3 },
} as const;

/**
 * Reads and writes one of a company's meta values as a field of its own.
 *
 * @param key The meta key.
 * @return The field's accessors.
 */
function meta( key: keyof CompanyDetails[ 'meta' ] ) {
	return {
		getValue: ( { item }: { item: CompanyForm } ) => item.meta[ key ],
		setValue: ( { value }: { value?: string } ) => ( {
			meta: { [ key ]: value ?? '' } as Partial< CompanyDetails[ 'meta' ] >,
		} ),
	};
}

/**
 * Builds the fields a company is edited with.
 *
 * @param company The saved company.
 * @param labels  The statuses' labels, keyed by status.
 * @return The fields.
 */
export function getCompanyFormFields( company: CompanyDetails, labels: Labels ): Field< CompanyForm >[] {
	return [
		getStatusField< CompanyForm >( company, labels ),
		{
			id: 'not_eligible_until',
			type: 'date',
			label: __( 'Not Eligible until', 'wordcamporg' ),
			description: __( 'Leave empty for no end date.', 'wordcamporg' ),
			render: ( { item } ) =>
				formatDay( item.meta._sv_not_eligible_until ) || __( 'No end date', 'wordcamporg' ),
			...meta( '_sv_not_eligible_until' ),
		},
		{
			id: 'good_faith_due',
			type: 'date',
			label: __( 'Good Faith due', 'wordcamporg' ),
			description: __( 'Set when a sponsorship is approved in good faith.', 'wordcamporg' ),
			render: ( { item } ) => formatDay( item.good_faith_due ) || __( 'None', 'wordcamporg' ),
			setValue: ( { value }: { value?: string } ) => ( { good_faith_due: value ?? '' } ),
		},
		{
			id: 'title',
			type: 'text',
			label: __( 'Name', 'wordcamporg' ),
			isValid: { required: true },
		},
		{
			id: 'domains',
			type: 'text',
			label: __( 'Website domains', 'wordcamporg' ),
			description: __( 'One per line. Addresses are reduced to their domain, without www.', 'wordcamporg' ),
			Edit: { control: 'textarea', rows: 3 },
			getValue: ( { item } ) => item.domains.join( '\n' ),
			setValue: ( { value }: { value?: string } ) => ( { domains: ( value ?? '' ).split( '\n' ) } ),
		},
		{
			id: 'first_name',
			type: 'text',
			label: __( 'Contact first name', 'wordcamporg' ),
			...meta( '_sv_contact_first_name' ),
		},
		{
			id: 'last_name',
			type: 'text',
			label: __( 'Contact last name', 'wordcamporg' ),
			...meta( '_sv_contact_last_name' ),
		},
		{
			id: 'email',
			type: 'email',
			label: __( 'Contact email', 'wordcamporg' ),
			...meta( '_sv_contact_email' ),
		},
		{
			id: 'mes',
			type: 'integer',
			label: __( 'Multi-Event Sponsor', 'wordcamporg' ),
			description: __(
				'Camp copies of this Multi-Event Sponsor are linked to this company.',
				'wordcamporg'
			),
			elements: [
				{ value: 0, label: __( 'None', 'wordcamporg' ) },
				...company.mes_options.map( ( { id, name } ) => ( { value: id, label: name } ) ),
			],
			setValue: ( { value }: { value?: number | string } ) => ( { mes: Number( value ) } ),
			isVisible: () => company.mes_options.length > 0,
		},
		{
			id: 'gpl_findings',
			type: 'text',
			label: __( 'GPL', 'wordcamporg' ),
			Edit: { control: 'textarea', rows: 6 },
			...meta( '_sv_gpl_findings' ),
		},
		{
			id: 'trademark_findings',
			type: 'text',
			label: __( 'Trademark', 'wordcamporg' ),
			description: __( 'Markdown. The compliance request sends these to the sponsor.', 'wordcamporg' ),
			Edit: { control: 'textarea', rows: 6 },
			...meta( '_sv_trademark_findings' ),
		},
		NOTE,
	];
}

/* A company's forms, one per group of its panel. The first shows each value with the control to change it. */
export const VETTING_FORM: Form = {
	layout: { type: 'panel', labelPosition: 'side', editVisibility: 'always' },
	fields: [ 'status', 'not_eligible_until', 'good_faith_due' ],
};
export const FINDINGS_FORM: Form = { fields: [ 'gpl_findings', 'trademark_findings' ] };
export const DETAILS_FORM: Form = {
	fields: [
		'title',
		'domains',
		{ id: 'contact', layout: { type: 'row' }, children: [ 'first_name', 'last_name' ] },
		'email',
		'mes',
	],
};
export const NOTE_FORM: Form = { fields: [ 'note' ] };

/* What a new company is created with; the rest is filled in once it's open. */
export const NEW_COMPANY_FORM: Form = { fields: [ 'title', 'domains' ] };

/**
 * Builds the fields a sponsorship is edited with.
 *
 * @param sponsorship The saved sponsorship.
 * @param companies   The companies it can be linked to.
 * @param labels      The statuses' labels, keyed by status.
 * @return The fields.
 */
export function getSponsorshipFormFields(
	sponsorship: SponsorshipDetails,
	companies: Company[],
	labels: Labels
): Field< SponsorshipForm >[] {
	return [
		{
			id: 'title',
			type: 'text',
			label: __( 'Name', 'wordcamporg' ),
			isValid: { required: true },
		},
		getStatusField< SponsorshipForm >( sponsorship, labels ),
		{
			id: 'company',
			type: 'integer',
			label: sponsorship.company
				? __( 'Move to another company', 'wordcamporg' )
				: __( 'Link to a company', 'wordcamporg' ),
			elements: companies.map( ( company ) => ( { value: company.id, label: getName( company ) } ) ),
			// Clearing the control leaves the sponsorship where it is: one that's linked can't be unlinked.
			setValue: ( { value }: { value?: number | string } ) => ( {
				company: Number( value ) || sponsorship.company,
			} ),
		},
		NOTE,
	];
}

export const APPROVAL_FORM: Form = {
	layout: { type: 'panel', labelPosition: 'side', editVisibility: 'always' },
	fields: [ 'title', 'status' ],
};
export const LINK_FORM: Form = { fields: [ 'company' ] };
