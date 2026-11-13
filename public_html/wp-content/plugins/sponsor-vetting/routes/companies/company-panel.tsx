import apiFetch from '@wordpress/api-fetch';
import { store as coreStore, useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { DataForm, useFormValidity } from '@wordpress/dataviews';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Stack, Text } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { formatDay, getToday } from './dates';
import { COMPANY, SPONSORSHIP, TRASH, getSponsorshipsQuery } from './entities';
import { Domains, Flags, StatusBadge, getName } from './fields';
import { DETAILS_FORM, FINDINGS_FORM, NOTE_FORM, VETTING_FORM, getCompanyFormFields } from './forms';
import type { CompanyForm } from './forms';
import { useOpenRecord, useStatusLabels, useTask } from './hooks';
import { Emails, Hint, History, PanelHeader, PanelPlaceholder, Row, Section } from './panel';
import { buildTimeline, sortByEvent } from './timeline';
import type { CompanyDetails, CompanySponsorship } from './types';

type EditorProps = {
	company: CompanyDetails;
	edited: CompanyForm;
	hasEdits: boolean;
	edit: ( edits: Partial< CompanyForm > ) => void;
	save: () => Promise< unknown >;
	sponsorships: CompanySponsorship[];
	nav: ReactNode;
};

/**
 * The form that sends a company's compliance request for one of its pending sponsorships, which names the event
 * and copies it in.
 *
 * @param props          Props.
 * @param props.pending  The company's pending sponsorships.
 * @param props.hasEdits Whether the company has changes that sending saves first.
 * @param props.isBusy   Whether the request is being sent.
 * @param props.onSend   Sends the request for a sponsorship.
 * @return The form.
 */
function ComplianceRequest( {
	pending,
	hasEdits,
	isBusy,
	onSend,
}: {
	pending: CompanySponsorship[];
	hasEdits: boolean;
	isBusy: boolean;
	onSend: ( sponsorshipId: number ) => void;
} ) {
	const [ picked, setPicked ] = useState< number >();
	const sponsorship = pending.find( ( { id } ) => id === picked ) ?? pending[ 0 ];

	if ( ! sponsorship ) {
		return (
			<Hint>
				{ __(
					'A compliance request needs a pending sponsorship, which names the event and copies it in.',
					'wordcamporg'
				) }
			</Hint>
		);
	}

	return (
		<Stack direction="column" gap="sm">
			<DataForm
				data={ { sponsorship: sponsorship.id } }
				fields={ [
					{
						id: 'sponsorship',
						type: 'integer',
						label: __( 'Event', 'wordcamporg' ),
						elements: pending.map( ( { id, event } ) => ( { value: id, label: event.name } ) ),
					},
				] }
				form={ { fields: [ 'sponsorship' ] } }
				onChange={ ( edits ) => setPicked( Number( edits.sponsorship ) ) }
			/>
			<Stack direction="row">
				<Button variant="outline" loading={ isBusy } onClick={ () => onSend( sponsorship.id ) }>
					{ hasEdits
						? __( 'Save and send compliance request', 'wordcamporg' )
						: __( 'Send compliance request', 'wordcamporg' ) }
				</Button>
			</Stack>
			<Hint>
				{ __(
					'Emails the findings to the contact from the Community mailbox, copying the event, and moves the company to Compliance Requested.',
					'wordcamporg'
				) }
			</Hint>
		</Stack>
	);
}

/**
 * A company, with everything a vetter changes about it: its vetting, findings, details and notes, the request to
 * its sponsor, and its events.
 *
 * @param props              Props.
 * @param props.company      The saved company.
 * @param props.edited       The company with the vetter's unsaved changes.
 * @param props.hasEdits     Whether there are any.
 * @param props.edit         Changes the company, without saving it.
 * @param props.save         Saves the changes.
 * @param props.sponsorships Its sponsorships.
 * @param props.nav          The way to the companies before and after it.
 * @return The panel.
 */
function Editor( { company, edited, hasEdits, edit, save, sponsorships, nav }: EditorProps ) {
	const { labels } = useStatusLabels();
	const { open } = useOpenRecord();
	const { deleteEntityRecord } = useDispatch( coreStore );
	const { running, run } = useTask();
	const fields = useMemo( () => getCompanyFormFields( company, labels ), [ company, labels ] );
	const { validity, isValid } = useFormValidity( edited, fields, DETAILS_FORM );
	const events = useMemo( () => sortByEvent( sponsorships, getToday() ), [ sponsorships ] );
	const timeline = useMemo( () => buildTimeline( company.log, sponsorships ), [ company.log, sponsorships ] );
	const pending = events.filter( ( { status } ) => status === 'svs-pending' );
	const form = { data: edited, fields, onChange: edit };

	const onSave = () =>
		run( 'save', async () => {
			await save();

			return __( 'Company saved.', 'wordcamporg' );
		} );

	const onSend = ( sponsorship: number ) =>
		run( 'send', async () => {
			if ( hasEdits ) {
				await save();
			}

			const { message } = await apiFetch< { message: string } >( {
				path: `/sponsor-vetting/v1/companies/${ company.id }/compliance-request`,
				method: 'POST',
				data: { sponsorship },
			} );

			return message;
		} );

	const onTrash = () =>
		run( 'trash', async () => {
			await deleteEntityRecord( 'postType', COMPANY, company.id, {}, { throwOnError: true } );
			open();

			return __( 'Company moved to the Trash.', 'wordcamporg' );
		} );

	return (
		<>
			<PanelHeader nav={ nav } onClose={ () => open() }>
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
							{ getName( company ) }
						</Text>
						<Domains domains={ company.domains } />
					</Stack>

					<Section title={ __( 'Vetting', 'wordcamporg' ) }>
						<DataForm { ...form } form={ VETTING_FORM } />
						<Row label={ __( 'Follow-up', 'wordcamporg' ) }>
							{ company.flags.length > 0 && <Flags flags={ company.flags } /> }
						</Row>
						<Row label={ __( 'Last vetted', 'wordcamporg' ) }>
							{ formatDay( company.last_vetted ) || __( 'Never', 'wordcamporg' ) }
						</Row>
					</Section>

					<Section title={ __( 'Events', 'wordcamporg' ) }>
						{ events.length === 0 && <Text>{ __( 'No sponsorships yet.', 'wordcamporg' ) }</Text> }
						{ events.map( ( { id, event, status, approved } ) => (
							<Stack key={ id } direction="column" gap="xs" className="sponsor-vetting__event">
								<Stack direction="row" justify="space-between" align="start" gap="md">
									<Button
										variant="unstyled"
										className="sponsor-vetting__open"
										onClick={ () => open( { company: company.id, sponsorship: id } ) }
									>
										{ event.name }
									</Button>
									<StatusBadge status={ status } label={ labels[ status ] ?? status } />
								</Stack>
								<Hint>
									{ [
										formatDay( event.date ),
										approved && `${ __( 'Approved', 'wordcamporg' ) } ${ approved }`,
									]
										.filter( Boolean )
										.join( ' · ' ) }
								</Hint>
							</Stack>
						) ) }
					</Section>

					<Section title={ __( 'Findings', 'wordcamporg' ) }>
						<DataForm { ...form } form={ FINDINGS_FORM } />
						<Emails
							label={ __( 'Compliance requests sent', 'wordcamporg' ) }
							conversations={ company.conversations.filter( ( { type } ) => type === 'compliance' ) }
						/>
						<ComplianceRequest
							pending={ pending }
							hasEdits={ hasEdits }
							isBusy={ running === 'send' }
							onSend={ onSend }
						/>
					</Section>

					<Section title={ __( 'Company', 'wordcamporg' ) }>
						<DataForm { ...form } form={ DETAILS_FORM } validity={ validity } />
					</Section>

					<Section title={ __( 'Notes and history', 'wordcamporg' ) }>
						<DataForm { ...form } form={ NOTE_FORM } />
						<History entries={ timeline } />
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
 * A company in the Trash, which can only come back out of it.
 *
 * @param props         Props.
 * @param props.company The company.
 * @param props.nav     The way to the companies before and after it.
 * @return The panel.
 */
function Trashed( { company, nav }: { company: CompanyDetails; nav: ReactNode } ) {
	const { open } = useOpenRecord();
	const { saveEntityRecord } = useDispatch( coreStore );
	const { running, run } = useTask();
	const onRestore = () =>
		run( 'restore', async () => {
			await saveEntityRecord(
				'postType',
				COMPANY,
				{ id: company.id, status: company.trashed_status },
				{ throwOnError: true }
			);

			return __( 'Company restored.', 'wordcamporg' );
		} );

	return (
		<>
			<PanelHeader nav={ nav } onClose={ () => open() } />
			<div className="sponsor-vetting__panel-body">
				<Stack direction="column" gap="lg">
					<Text variant="heading-lg" render={ <h2 /> }>
						{ getName( company ) }
					</Text>
					<Notice.Root intent="warning">
						<Notice.Title>{ __( 'This company is in the Trash.', 'wordcamporg' ) }</Notice.Title>
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
 * The open company: its record in full, which the list only has in part, and its sponsorships.
 *
 * @param props     Props.
 * @param props.id  The company.
 * @param props.nav The way to the companies before and after it.
 * @return The company's panel, or how loading it is going.
 */
export function CompanyPanel( { id, nav }: { id: number; nav: ReactNode } ) {
	const { open } = useOpenRecord();
	const { record, editedRecord, hasEdits, edit, save } = useEntityRecord< CompanyDetails >(
		'postType',
		COMPANY,
		id
	);
	const query = useMemo( () => getSponsorshipsQuery( id ), [ id ] );
	const { records: sponsorships } = useEntityRecords< CompanySponsorship >( 'postType', SPONSORSHIP, query );
	const error = useSelect(
		( select ) => {
			const { getResolutionError } = select( coreStore );

			return (
				getResolutionError( 'getEntityRecord', [ 'postType', COMPANY, id ] ) ??
				getResolutionError( 'getEntityRecords', [ 'postType', SPONSORSHIP, query ] )
			);
		},
		[ id, query ]
	);

	if ( ! record || ! sponsorships ) {
		return (
			<PanelPlaceholder
				error={ error }
				title={ __( 'The company couldn’t be loaded.', 'wordcamporg' ) }
				onClose={ () => open() }
			/>
		);
	}

	if ( record.status === TRASH ) {
		return <Trashed company={ record } nav={ nav } />;
	}

	return (
		<Editor
			company={ record }
			edited={ editedRecord as unknown as CompanyForm }
			hasEdits={ hasEdits }
			edit={ edit as unknown as ( edits: Partial< CompanyForm > ) => void }
			save={ save }
			sponsorships={ sponsorships }
			nav={ nav }
		/>
	);
}
