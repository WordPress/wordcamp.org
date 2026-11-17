import apiFetch from '@wordpress/api-fetch';
import { store as coreStore, useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { DataForm, useFormValidity } from '@wordpress/dataviews';
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Card, Notice, Stack, Text } from '@wordpress/ui';
import { CanvasHeader, CanvasPlaceholder, Emails, Hint, History, Part, Row, Section } from './canvas-parts';
import { formatDay, getToday } from './dates';
import { COMPANY, SPONSORSHIP, TRASH, getEventActionsQuery, getSponsorshipsQuery } from './entities';
import { EventRow } from './event-row';
import { getEventsHint, splitEvents } from './events';
import { Domains, Flags, StatusBadge, getName } from './fields';
import { DETAILS_FORM, FINDINGS_FORM, NOTE_FORM, VETTING_FORM, getCompanyFormFields } from './forms';
import type { CompanyForm } from './forms';
import { useOpenRecord, useStatusLabels, useTask } from './hooks';
import { MoveSponsorship } from './move-sponsorship';
import { useSponsorshipChanges } from './sponsorships';
import { buildTimeline, sortByEvent } from './timeline';
import type { CompanyDetails, CompanySponsorship, EventActions } from './types';

type EditorProps = {
	company: CompanyDetails;
	edited: CompanyForm;
	hasEdits: boolean;
	edit: ( edits: Partial< CompanyForm > ) => void;
	save: () => Promise< unknown >;
	sponsorships: CompanySponsorship[];
	sponsorshipId?: number;
};

/**
 * The form that sends a company's compliance request for one of its pending sponsorships, which names the event
 * and copies it in.
 *
 * @param props          Props.
 * @param props.pending  The company's pending sponsorships, the one to offer first.
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
					'A compliance request needs a pending event, which it names and copies in.',
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
 * A company with its events: what a vetter approves event by event, above what they vet once, which is the
 * company's findings, vetting, details and notes, and the request to its sponsor.
 *
 * @param props               Props.
 * @param props.company       The saved company.
 * @param props.edited        The company with the vetter's unsaved changes.
 * @param props.hasEdits      Whether there are any.
 * @param props.edit          Changes the company, without saving it.
 * @param props.save          Saves the changes.
 * @param props.sponsorships  Its sponsorships.
 * @param props.sponsorshipId The sponsorship it was opened from, if it was opened from an event.
 * @return The company.
 */
function Editor( { company, edited, hasEdits, edit, save, sponsorships, sponsorshipId }: EditorProps ) {
	const { labels } = useStatusLabels();
	const { open } = useOpenRecord();
	const { deleteEntityRecord } = useDispatch( coreStore );
	const { running, run } = useTask();
	const changes = useSponsorshipChanges( run );
	const [ moving, setMoving ] = useState< CompanySponsorship >();
	const today = getToday();
	const fields = useMemo( () => getCompanyFormFields( company, labels ), [ company, labels ] );
	const { validity, isValid } = useFormValidity( edited, fields, DETAILS_FORM );
	const events = useMemo( () => sortByEvent( sponsorships, today ), [ sponsorships, today ] );
	const [ showsPast, setShowsPast ] = useState( false );
	const { shown, past } = useMemo(
		() => splitEvents( events, today, sponsorshipId ),
		[ events, today, sponsorshipId ]
	);
	const listed = showsPast ? events : shown;
	// Read only for the events listed, so years of past ones don't slow a company down.
	const actionsQuery = useMemo( () => getEventActionsQuery( listed.map( ( { id } ) => id ) ), [ listed ] );
	const { records: actionRecords, hasResolved: hasActions } = useEntityRecords< EventActions >(
		'postType',
		SPONSORSHIP,
		actionsQuery,
		{ enabled: listed.length > 0 }
	);
	const actions = useMemo(
		() => new Map( ( actionRecords ?? [] ).map( ( record ) => [ record.id, record ] ) ),
		[ actionRecords ]
	);
	const timeline = useMemo( () => buildTimeline( company.log, sponsorships ), [ company.log, sponsorships ] );
	// The request is most likely about the event the company was opened from.
	const pending = events
		.filter( ( { status } ) => status === 'svs-pending' )
		.sort( ( a, b ) => Number( b.id === sponsorshipId ) - Number( a.id === sponsorshipId ) );
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
			<CanvasHeader
				title={ getName( company ) }
				badge={
					<StatusBadge status={ company.status } label={ labels[ company.status ] ?? company.status } />
				}
				actions={
					<>
						{ hasEdits && <Hint>{ __( 'Unsaved changes', 'wordcamporg' ) }</Hint> }
						<Button
							size="compact"
							disabled={ ! hasEdits || ! isValid }
							focusableWhenDisabled
							loading={ running === 'save' }
							onClick={ onSave }
						>
							{ __( 'Save', 'wordcamporg' ) }
						</Button>
					</>
				}
				onClose={ () => open() }
			>
				<Domains domains={ company.domains } />
			</CanvasHeader>
			<Stack direction="column" gap="2xl" className="sponsor-vetting__canvas-body">
				<Part title={ __( 'Events', 'wordcamporg' ) } hint={ getEventsHint( company, events ) }>
					{ events.length === 0 ? (
						<Text>{ __( 'No events yet.', 'wordcamporg' ) }</Text>
					) : (
						<Card.Root className="sponsor-vetting__events">
							<ul>
								{ listed.map( ( sponsorship ) => (
									<EventRow
										key={ sponsorship.id }
										sponsorship={ sponsorship }
										actions={ actions.get( sponsorship.id ) }
										isLoading={ ! hasActions }
										isMarked={ sponsorship.id === sponsorshipId }
										labels={ labels }
										today={ today }
										running={ running }
										changes={ changes }
										onMove={ () => setMoving( sponsorship ) }
									/>
								) ) }
							</ul>
							{ past.length > 0 && (
								<div className="sponsor-vetting__events-more">
									<Button
										size="compact"
										variant="minimal"
										tone="neutral"
										aria-expanded={ showsPast }
										onClick={ () => setShowsPast( ! showsPast ) }
									>
										{ showsPast
											? __( 'Hide past events', 'wordcamporg' )
											: sprintf(
													/* translators: %d: Number of events. */
													_n(
														'Show %d past event',
														'Show %d past events',
														past.length,
														'wordcamporg'
													),
													past.length
											  ) }
									</Button>
								</div>
							) }
						</Card.Root>
					) }
				</Part>

				<Part title={ __( 'Company', 'wordcamporg' ) }>
					<div className="sponsor-vetting__columns">
						<Stack direction="column" gap="lg">
							<Section title={ __( 'Findings', 'wordcamporg' ) } level={ 4 }>
								<DataForm { ...form } form={ FINDINGS_FORM } />
								<Emails
									label={ __( 'Compliance requests sent', 'wordcamporg' ) }
									conversations={ company.conversations.filter(
										( { type } ) => type === 'compliance'
									) }
								/>
								<ComplianceRequest
									pending={ pending }
									hasEdits={ hasEdits }
									isBusy={ running === 'send' }
									onSend={ onSend }
								/>
							</Section>

							<Section title={ __( 'Notes and history', 'wordcamporg' ) } level={ 4 }>
								<DataForm { ...form } form={ NOTE_FORM } />
								<History entries={ timeline } />
							</Section>
						</Stack>

						<Stack direction="column" gap="lg">
							<Section title={ __( 'Vetting', 'wordcamporg' ) } level={ 4 }>
								<DataForm { ...form } form={ VETTING_FORM } />
								<Row label={ __( 'Follow-up', 'wordcamporg' ) }>
									{ company.flags.length > 0 && <Flags flags={ company.flags } /> }
								</Row>
								<Row label={ __( 'Last vetted', 'wordcamporg' ) }>
									{ formatDay( company.last_vetted ) || __( 'Never', 'wordcamporg' ) }
								</Row>
							</Section>

							<Section title={ __( 'Details', 'wordcamporg' ) } level={ 4 }>
								<DataForm { ...form } form={ DETAILS_FORM } validity={ validity } />
							</Section>
						</Stack>
					</div>
				</Part>

				<Stack direction="row">
					<Button variant="minimal" tone="neutral" loading={ running === 'trash' } onClick={ onTrash }>
						{ __( 'Move to Trash', 'wordcamporg' ) }
					</Button>
				</Stack>
			</Stack>
			{ moving && (
				<MoveSponsorship
					sponsorship={ moving }
					companyId={ company.id }
					onClose={ () => setMoving( undefined ) }
				/>
			) }
		</>
	);
}

/**
 * A company in the Trash, which can only come back out of it.
 *
 * @param props         Props.
 * @param props.company The company.
 * @return The company.
 */
function Trashed( { company }: { company: CompanyDetails } ) {
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
			<CanvasHeader title={ getName( company ) } onClose={ () => open() } />
			<Stack direction="column" gap="lg" className="sponsor-vetting__canvas-body">
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
		</>
	);
}

/**
 * The open company: its record in full, which the list only has in part, and its sponsorships.
 *
 * @param props               Props.
 * @param props.id            The company.
 * @param props.sponsorshipId The sponsorship it was opened from, if it was opened from an event.
 * @return The company, or how loading it is going.
 */
export function CompanyCanvas( { id, sponsorshipId }: { id: number; sponsorshipId?: number } ) {
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
			<CanvasPlaceholder
				error={ error }
				title={ __( 'The company couldn’t be loaded.', 'wordcamporg' ) }
				onClose={ () => open() }
			/>
		);
	}

	if ( record.status === TRASH ) {
		return <Trashed company={ record } />;
	}

	return (
		<Editor
			company={ record }
			edited={ editedRecord as unknown as CompanyForm }
			hasEdits={ hasEdits }
			edit={ edit as unknown as ( edits: Partial< CompanyForm > ) => void }
			save={ save }
			sponsorships={ sponsorships }
			sponsorshipId={ sponsorshipId }
		/>
	);
}
