import apiFetch from '@wordpress/api-fetch';
import { store as coreStore, useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { DataForm, useFormValidity } from '@wordpress/dataviews';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Link, Notice, Stack, Text } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { formatDay } from './dates';
import { COMPANIES_QUERY, COMPANY, SPONSORSHIP, TRASH } from './entities';
import { StatusBadge, getName } from './fields';
import { APPROVAL_FORM, LINK_FORM, NOTE_FORM, getSponsorshipFormFields } from './forms';
import type { SponsorshipForm } from './forms';
import { useOpenRecord, useStatusLabels, useTask } from './hooks';
import { Emails, Hint, History, PanelHeader, PanelPlaceholder, Row, Section } from './panel';
import type { Company, CompanyDetails, SponsorshipDetails } from './types';

type EditorProps = {
	sponsorship: SponsorshipDetails;
	edited: SponsorshipForm;
	hasEdits: boolean;
	edit: ( edits: Partial< SponsorshipForm > ) => void;
	save: () => Promise< unknown >;
	companies: Company[];
	nav: ReactNode;
};

/**
 * Says what saving a sponsorship came to, which is more than a save when its new company is already vetted.
 *
 * @param before The sponsorship before the save.
 * @param after  The sponsorship after it.
 * @return The message.
 */
function describeSave( before: SponsorshipDetails, after: SponsorshipDetails ): string {
	if ( before.status === 'svs-pending' && after.status === 'svs-approved' ) {
		return after.needs_approval_email
			? __(
					'The sponsorship was approved automatically: its company is vetted. The approval email wasn’t sent (see history); send it again from here.',
					'wordcamporg'
			  )
			: __( 'The sponsorship was approved automatically: its company is vetted.', 'wordcamporg' );
	}

	return __( 'Sponsorship saved.', 'wordcamporg' );
}

/**
 * A sponsorship, with everything a vetter does about it: approve it, link it to its company, and note things.
 *
 * @param props             Props.
 * @param props.sponsorship The saved sponsorship.
 * @param props.edited      The sponsorship with the vetter's unsaved changes.
 * @param props.hasEdits    Whether there are any.
 * @param props.edit        Changes the sponsorship, without saving it.
 * @param props.save        Saves the changes.
 * @param props.companies   The companies it can be linked to.
 * @param props.nav         The way to the sponsorships before and after it.
 * @return The panel.
 */
function Editor( { sponsorship, edited, hasEdits, edit, save, companies, nav }: EditorProps ) {
	const { id, sponsor, suggestion, event } = sponsorship;
	const { labels } = useStatusLabels();
	const { companyId, open } = useOpenRecord();
	// Back to the company it was opened from, if any.
	const close = () => open( { company: companyId } );
	const { deleteEntityRecord, saveEntityRecord } = useDispatch( coreStore );
	const { running, run } = useTask();
	const fields = useMemo(
		() => getSponsorshipFormFields( sponsorship, companies, labels ),
		[ sponsorship, companies, labels ]
	);
	const { validity, isValid } = useFormValidity( edited, fields, APPROVAL_FORM );
	const company = companies.find( ( candidate ) => candidate.id === sponsorship.company );
	const suggested = suggestion ? companies.find( ( candidate ) => candidate.id === suggestion.id ) : undefined;
	const isApproved = sponsorship.status === 'svs-approved';
	const form = { data: edited, fields, onChange: edit };

	// Writes fields straight to the sponsorship, apart from whatever the vetter has changed but not saved.
	const write = ( values: Record< string, unknown > ) =>
		saveEntityRecord(
			'postType',
			SPONSORSHIP,
			{ id, ...values },
			{ throwOnError: true }
		) as Promise< SponsorshipDetails >;

	const onSave = () =>
		run( 'save', async () => describeSave( sponsorship, ( await save() ) as SponsorshipDetails ) );

	const onLink = ( target: number ) =>
		run( 'link', async () => describeSave( sponsorship, await write( { company: target } ) ) );

	const onCreate = () =>
		run( 'create', async () => {
			if ( ! sponsor ) {
				return undefined;
			}

			const created = ( await saveEntityRecord(
				'postType',
				COMPANY,
				{
					title: sponsor.name,
					status: 'sv-needs-vetting',
					domains: [ sponsor.website ],
					meta: {
						_sv_contact_first_name: sponsor.first_name,
						_sv_contact_last_name: sponsor.last_name,
						_sv_contact_email: sponsor.email,
					},
				},
				{ throwOnError: true }
			) ) as CompanyDetails;

			await write( { company: created.id } );

			/* translators: %s: Company name. */
			return sprintf( __( 'Created “%s” and linked the sponsorship to it.', 'wordcamporg' ), sponsor.name );
		} );

	const onApprove = ( goodFaith: boolean ) =>
		run( 'approve', async () => {
			// The approval is for the website the vetter was shown, so the server refuses another.
			const approved = await write( {
				status: 'svs-approved',
				good_faith: goodFaith,
				shown_domain: sponsorship.domain,
			} );

			if ( approved.needs_approval_email ) {
				throw new Error(
					__(
						'The sponsorship was approved, but the approval email wasn’t sent (see history). Send it again from here.',
						'wordcamporg'
					)
				);
			}

			return __( 'The sponsorship was approved.', 'wordcamporg' );
		} );

	const onConfirmWebsite = () =>
		run( 'confirm', async () => {
			await write( { approved_domain: sponsorship.domain } );

			return __( 'The new website was confirmed.', 'wordcamporg' );
		} );

	const onSendApproval = () =>
		run( 'send', async () => {
			const { message } = await apiFetch< { message: string } >( {
				path: `/sponsor-vetting/v1/sponsorships/${ id }/approval-email`,
				method: 'POST',
			} );

			return message;
		} );

	const onTrash = () =>
		run( 'trash', async () => {
			await deleteEntityRecord( 'postType', SPONSORSHIP, id, {}, { throwOnError: true } );
			close();

			return __( 'Sponsorship moved to the Trash.', 'wordcamporg' );
		} );

	return (
		<>
			<PanelHeader nav={ nav } onClose={ close }>
				<Button
					size="compact"
					disabled={ ! hasEdits || ! isValid }
					focusableWhenDisabled
					loading={ running === 'save' }
					onClick={ onSave }
				>
					{ __( 'Save', 'wordcamporg' ) }
				</Button>
				{ hasEdits && <Hint>{ __( 'Unsaved changes', 'wordcamporg' ) }</Hint> }
			</PanelHeader>
			<div className="sponsor-vetting__panel-body">
				<Stack direction="column" gap="lg">
					<Stack direction="column" gap="xs">
						<Text variant="heading-lg" render={ <h2 /> }>
							{ getName( sponsorship ) }
						</Text>
						<Hint>{ [ event.name, formatDay( event.date ) ].filter( Boolean ).join( ' · ' ) }</Hint>
					</Stack>

					<Section title={ __( 'Approval', 'wordcamporg' ) }>
						<DataForm { ...form } form={ APPROVAL_FORM } validity={ validity } />
						<Row label={ __( 'Approved', 'wordcamporg' ) }>{ sponsorship.approved }</Row>

						{ sponsorship.approvable && (
							<Stack direction="row">
								<Button
									variant={ sponsorship.approvable === 'approve' ? 'solid' : 'outline' }
									loading={ running === 'approve' }
									onClick={ () => onApprove( sponsorship.approvable === 'good_faith' ) }
								>
									{ sponsorship.approvable === 'approve'
										? __( 'Approve', 'wordcamporg' )
										: __( 'Approve in Good Faith', 'wordcamporg' ) }
								</Button>
							</Stack>
						) }
						{ sponsorship.approval_hint && <Hint>{ sponsorship.approval_hint }</Hint> }

						{ sponsorship.website_changed && (
							<Notice.Root intent="warning">
								<Notice.Title>
									{ __( 'The sponsor’s website changed', 'wordcamporg' ) }
								</Notice.Title>
								<Notice.Description>
									{ sprintf(
										/* translators: 1: Approved website domain, 2: New website domain. */
										__(
											'The camp changed it from %1$s to %2$s after approval. Invoices can’t be approved until you confirm the new website. If it’s a different company now, cancel the sponsorship instead.',
											'wordcamporg'
										),
										sponsorship.approved_domain || __( 'none', 'wordcamporg' ),
										sponsorship.domain || __( 'none', 'wordcamporg' )
									) }
								</Notice.Description>
								<Notice.Actions>
									<Notice.ActionButton
										loading={ running === 'confirm' }
										onClick={ onConfirmWebsite }
									>
										{ __( 'Confirm new website', 'wordcamporg' ) }
									</Notice.ActionButton>
								</Notice.Actions>
							</Notice.Root>
						) }

						{ isApproved && (
							<Emails
								label={ __( 'Approval emails sent', 'wordcamporg' ) }
								conversations={ sponsorship.conversations.filter(
									( { type } ) => type === 'approval'
								) }
							/>
						) }
						{ sponsorship.needs_approval_email && (
							<Stack direction="row">
								<Button
									variant="outline"
									loading={ running === 'send' }
									onClick={ onSendApproval }
								>
									{ __( 'Send approval email', 'wordcamporg' ) }
								</Button>
							</Stack>
						) }
					</Section>

					<Section title={ __( 'Company', 'wordcamporg' ) }>
						{ company && (
							<Stack direction="row" justify="space-between" align="start" gap="md">
								<Button
									variant="unstyled"
									className="sponsor-vetting__open"
									onClick={ () => open( { company: company.id } ) }
								>
									{ getName( company ) }
								</Button>
								<StatusBadge
									status={ company.status }
									label={ labels[ company.status ] ?? company.status }
								/>
							</Stack>
						) }
						{ ! company && suggested && suggestion && (
							<Stack direction="column" gap="sm">
								<Text>
									{ sprintf(
										/* translators: 1: Company name. 2: Why it's suggested. */
										__( 'Suggested: %1$s. %2$s', 'wordcamporg' ),
										getName( suggested ),
										suggestion.reason
									) }
								</Text>
								<Stack direction="row">
									<Button
										variant="outline"
										loading={ running === 'link' }
										onClick={ () => onLink( suggested.id ) }
									>
										{ __( 'Confirm', 'wordcamporg' ) }
									</Button>
								</Stack>
							</Stack>
						) }
						{ ! company && ! suggested && (
							<Text>{ __( 'Not linked to a company yet.', 'wordcamporg' ) }</Text>
						) }

						{ isApproved ? (
							<Hint>
								{ __(
									'To move an approved sponsorship to another company, cancel it and reopen it first.',
									'wordcamporg'
								) }
							</Hint>
						) : (
							<DataForm { ...form } form={ LINK_FORM } />
						) }
						{ ! company && sponsor && (
							<Stack direction="row">
								<Button variant="outline" loading={ running === 'create' } onClick={ onCreate }>
									{ __( 'Create a company from this sponsor', 'wordcamporg' ) }
								</Button>
							</Stack>
						) }
					</Section>

					<Section title={ __( 'Event sponsor', 'wordcamporg' ) }>
						{ sponsor ? (
							<>
								<Row label={ __( 'Event', 'wordcamporg' ) }>{ event.name }</Row>
								<Row label={ __( 'Event date', 'wordcamporg' ) }>{ formatDay( event.date ) }</Row>
								<Row label={ __( 'Name', 'wordcamporg' ) }>{ sponsor.name }</Row>
								<Row label={ __( 'Website', 'wordcamporg' ) }>
									{ sponsor.website && (
										<Link href={ sponsor.website } openInNewTab>
											{ sponsor.website }
										</Link>
									) }
								</Row>
								<Row label={ __( 'Contact', 'wordcamporg' ) }>
									{ [ sponsor.first_name, sponsor.last_name ].filter( Boolean ).join( ' ' ) }
								</Row>
								<Row label={ __( 'Email', 'wordcamporg' ) }>{ sponsor.email }</Row>
								<Link href={ sponsor.edit_url } openInNewTab>
									{ __( 'Open on the event site', 'wordcamporg' ) }
								</Link>
							</>
						) : (
							<Text>{ __( 'The event’s sponsor has been deleted.', 'wordcamporg' ) }</Text>
						) }
					</Section>

					<Section title={ __( 'Notes and history', 'wordcamporg' ) }>
						<DataForm { ...form } form={ NOTE_FORM } />
						<History entries={ sponsorship.log } />
					</Section>

					<Stack direction="row">
						<Button
							variant="minimal"
							tone="neutral"
							loading={ running === 'trash' }
							onClick={ onTrash }
						>
							{ __( 'Move to Trash', 'wordcamporg' ) }
						</Button>
					</Stack>
				</Stack>
			</div>
		</>
	);
}

/**
 * A sponsorship in the Trash, which can only come back out of it.
 *
 * @param props             Props.
 * @param props.sponsorship The sponsorship.
 * @param props.nav         The way to the sponsorships before and after it.
 * @return The panel.
 */
function Trashed( { sponsorship, nav }: { sponsorship: SponsorshipDetails; nav: ReactNode } ) {
	const { companyId, open } = useOpenRecord();
	const close = () => open( { company: companyId } );
	const { saveEntityRecord } = useDispatch( coreStore );
	const { running, run } = useTask();
	const onRestore = () =>
		run( 'restore', async () => {
			await saveEntityRecord(
				'postType',
				SPONSORSHIP,
				{ id: sponsorship.id, status: sponsorship.trashed_status },
				{ throwOnError: true }
			);

			return __( 'Sponsorship restored.', 'wordcamporg' );
		} );

	return (
		<>
			<PanelHeader nav={ nav } onClose={ close } />
			<div className="sponsor-vetting__panel-body">
				<Stack direction="column" gap="lg">
					<Text variant="heading-lg" render={ <h2 /> }>
						{ getName( sponsorship ) }
					</Text>
					<Notice.Root intent="warning">
						<Notice.Title>{ __( 'This sponsorship is in the Trash.', 'wordcamporg' ) }</Notice.Title>
						<Notice.Description>
							{ __( 'Restore it to see and change it again.', 'wordcamporg' ) }
						</Notice.Description>
					</Notice.Root>
					<Stack direction="row">
						<Button loading={ running === 'restore' } onClick={ onRestore }>
							{ __( 'Restore', 'wordcamporg' ) }
						</Button>
					</Stack>
				</Stack>
			</div>
		</>
	);
}

/**
 * The open sponsorship: its record in full, which the list only has in part.
 *
 * @param props     Props.
 * @param props.id  The sponsorship.
 * @param props.nav The way to the sponsorships before and after it.
 * @return The sponsorship's panel, or how loading it is going.
 */
export function SponsorshipPanel( { id, nav }: { id: number; nav: ReactNode } ) {
	const { companyId, open } = useOpenRecord();
	const close = () => open( { company: companyId } );
	const { record, editedRecord, hasEdits, edit, save } = useEntityRecord< SponsorshipDetails >(
		'postType',
		SPONSORSHIP,
		id
	);
	const { records: companies } = useEntityRecords< Company >( 'postType', COMPANY, COMPANIES_QUERY );
	const error = useSelect(
		( select ) => {
			const { getResolutionError } = select( coreStore );

			return (
				getResolutionError( 'getEntityRecord', [ 'postType', SPONSORSHIP, id ] ) ??
				getResolutionError( 'getEntityRecords', [ 'postType', COMPANY, COMPANIES_QUERY ] )
			);
		},
		[ id ]
	);
	const linkable = useMemo(
		() => ( companies ?? [] ).filter( ( { status } ) => status !== TRASH ),
		[ companies ]
	);

	if ( ! record || ! companies ) {
		return (
			<PanelPlaceholder
				error={ error }
				title={ __( 'The sponsorship couldn’t be loaded.', 'wordcamporg' ) }
				onClose={ close }
			/>
		);
	}

	if ( record.status === TRASH ) {
		return <Trashed sponsorship={ record } nav={ nav } />;
	}

	return (
		<Editor
			sponsorship={ record }
			edited={ editedRecord as unknown as SponsorshipForm }
			hasEdits={ hasEdits }
			edit={ edit as unknown as ( edits: Partial< SponsorshipForm > ) => void }
			save={ save }
			companies={ linkable }
			nav={ nav }
		/>
	);
}
