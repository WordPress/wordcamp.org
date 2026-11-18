/**
 * The fields and forms a company is edited with.
 */

import type { Field, Form } from '@wordpress/dataviews';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { formatDay } from './dates';
import { StatusBadge, getName } from './fields';
import type { Company, CompanyDetails, Edited } from './types';

export type CompanyForm = Edited< CompanyDetails > & { original_status?: string };

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
			<StatusBadge status={ item.status } label={ labels[ item.status ] ?? item.status } />
		),
	};
}

/**
 * The note that's added when the company is saved.
 */
const NOTE = {
	id: 'note',
	type: 'text',
	label: __( 'Add a note', 'wordcamporg' ),
	description: __( 'Private: only vetters see notes. Added when you save.', 'wordcamporg' ),
	Edit: { control: 'textarea', rows: 3 },
} as const;

type Stored =
	| 'contact_first_name'
	| 'contact_last_name'
	| 'contact_email'
	| 'gpl_findings'
	| 'trademark_findings'
	| 'not_eligible_until'
	| 'good_faith_due';

/**
 * Names a field after the company value it edits, and writes an emptied one as an empty string, which is what the
 * server takes for no value.
 *
 * Each value is a field of the record, so a save sends only the ones the vetter changed.
 *
 * @param id The value's name.
 * @return The field's ID and how it's written.
 */
function stored( id: Stored ) {
	return {
		id,
		setValue: ( { value }: { value?: string } ) => ( { [ id ]: value ?? '' } ) as Partial< CompanyForm >,
	};
}

/**
 * A company's name, as it's asked for when the company is added and when it's edited.
 */
export const NAME_FIELD = {
	id: 'title',
	type: 'text',
	label: __( 'Name', 'wordcamporg' ),
	isValid: { required: true },
} as const;

/**
 * A company's websites, as they're asked for when the company is added and when it's edited.
 */
export const DOMAINS_FIELD = {
	id: 'domains',
	type: 'text',
	label: __( 'Website domains', 'wordcamporg' ),
	description: __( 'One per line. Addresses are reduced to their domain, without www.', 'wordcamporg' ),
	Edit: { control: 'textarea', rows: 3 },
} as const;

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
			...stored( 'not_eligible_until' ),
			type: 'date',
			label: __( 'Not Eligible until', 'wordcamporg' ),
			description: __( 'Leave empty for no end date.', 'wordcamporg' ),
			render: ( { item } ) => formatDay( item.not_eligible_until ) || __( 'No end date', 'wordcamporg' ),
			// Only being Not Eligible has an end, so it shows once that's the status, saved or about to be.
			isVisible: ( item ) => item.status === 'sv-not-eligible',
		},
		{
			...stored( 'good_faith_due' ),
			type: 'date',
			label: __( 'Good Faith due', 'wordcamporg' ),
			description: __( 'Set when a sponsorship is approved in good faith.', 'wordcamporg' ),
			render: ( { item } ) => formatDay( item.good_faith_due ) || __( 'None', 'wordcamporg' ),
			// A Good Faith approval sets it, so before one there's nothing to show or change.
			isVisible: ( item ) => Boolean( company.good_faith_due || item.good_faith_due ),
		},
		{
			...NAME_FIELD,
			// Stored with its `&` and the like encoded, and edited as the text they stand for.
			getValue: ( { item } ) => decodeEntities( item.title ),
		},
		{
			...DOMAINS_FIELD,
			getValue: ( { item } ) => item.domains.join( '\n' ),
			setValue: ( { value }: { value?: string } ) => ( { domains: ( value ?? '' ).split( '\n' ) } ),
		},
		{
			...stored( 'contact_first_name' ),
			type: 'text',
			label: __( 'Contact first name', 'wordcamporg' ),
		},
		{
			...stored( 'contact_last_name' ),
			type: 'text',
			label: __( 'Contact last name', 'wordcamporg' ),
		},
		{
			...stored( 'contact_email' ),
			type: 'email',
			label: __( 'Contact email', 'wordcamporg' ),
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
			...stored( 'gpl_findings' ),
			type: 'text',
			label: __( 'GPL', 'wordcamporg' ),
			Edit: { control: 'textarea', rows: 6 },
		},
		{
			...stored( 'trademark_findings' ),
			type: 'text',
			label: __( 'Trademark', 'wordcamporg' ),
			description: __( 'Markdown. The compliance request sends these to the sponsor.', 'wordcamporg' ),
			Edit: { control: 'textarea', rows: 6 },
		},
		NOTE,
	];
}

/**
 * A company's forms, one per group of its view. The first shows each value with the control to change it.
 */
export const VETTING_FORM: Form = {
	layout: { type: 'panel', labelPosition: 'side', editVisibility: 'always' },
	fields: [ 'status', 'not_eligible_until', 'good_faith_due' ],
};

/**
 * The findings of a company's vetting.
 */
export const FINDINGS_FORM: Form = { fields: [ 'gpl_findings', 'trademark_findings' ] };

/**
 * Who the company is and who to write to.
 */
export const DETAILS_FORM: Form = {
	fields: [
		'title',
		'domains',
		{ id: 'contact', layout: { type: 'row' }, children: [ 'contact_first_name', 'contact_last_name' ] },
		'contact_email',
		'mes',
	],
};

/**
 * The note a vetter adds.
 */
export const NOTE_FORM: Form = { fields: [ 'note' ] };

/**
 * The company a vetter picks for a sponsorship, or 0 before they have.
 */
export type CompanyPick = { company: number };

/**
 * Builds the field that picks the company a sponsorship is linked to, or moved to.
 *
 * @param companies The companies to pick from.
 * @param label     What the field is for.
 * @return The field.
 */
export function getCompanyPickField( companies: Company[], label: string ): Field< CompanyPick > {
	return {
		id: 'company',
		type: 'integer',
		label,
		elements: companies.map( ( company ) => ( { value: company.id, label: getName( company ) } ) ),
		setValue: ( { value }: { value?: number | string } ) => ( { company: Number( value ) || 0 } ),
	};
}

/**
 * The form a company is picked with.
 */
export const COMPANY_PICK_FORM: Form = { fields: [ 'company' ] };
