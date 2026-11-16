import { store as coreStore, useEntityRecord } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { DataForm } from '@wordpress/dataviews';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Link, Notice, Stack, Text } from '@wordpress/ui';
import { CanvasHeader, CanvasPlaceholder, Hint, History, Row, Section } from './canvas-parts';
import { formatDay } from './dates';
import { COMPANIES_QUERY, COMPANY, SPONSORSHIP, TRASH } from './entities';
import { StatusBadge, getName, getSponsorName, needsCompany } from './fields';
import { COMPANY_PICK_FORM, getCompanyPickField } from './forms';
import type { CompanyPick } from './forms';
import { useOpenRecord, useRecords, useStatusLabels, useTask } from './hooks';
import { getStatusChangeLabel, useSponsorshipChanges } from './sponsorships';
import type { Company, CompanyDetails, SponsorshipDetails } from './types';

/**
 * A sponsorship that has no company yet, with what gives it one: the suggested company, another, or a new one made
 * from the sponsor as its event entered it.
 *
 * @param props             Props.
 * @param props.sponsorship The sponsorship.
 * @param props.companies   The companies it can be linked to.
 * @return The sponsorship.
 */
function Unlinked( { sponsorship, companies }: { sponsorship: SponsorshipDetails; companies: Company[] } ) {
	const { id, sponsor, suggestion, event, status } = sponsorship;
	const { labels } = useStatusLabels();
	const { open } = useOpenRecord();
	const { saveEntityRecord } = useDispatch( coreStore );
	const { running, run } = useTask();
	const changes = useSponsorshipChanges( run );
	const [ pick, setPick ] = useState< CompanyPick >( { company: 0 } );
	const fields = useMemo(
		() => [ getCompanyPickField( companies, __( 'Another company', 'wordcamporg' ) ) ],
		[ companies ]
	);
	const suggested = suggestion ? companies.find( ( candidate ) => candidate.id === suggestion.id ) : undefined;
	// Approving takes a company first.
	const others = sponsorship.allowed_statuses.filter( ( next ) => next !== 'svs-approved' );

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

			await saveEntityRecord( 'postType', SPONSORSHIP, { id, company: created.id }, { throwOnError: true } );

			/* translators: %s: Company name. */
			return sprintf( __( 'Created “%s” and linked the sponsorship to it.', 'wordcamporg' ), sponsor.name );
		} );

	const onTrash = async () => {
		if ( await changes.trash( sponsorship ) ) {
			open();
		}
	};

	return (
		<>
			<CanvasHeader
				title={ getSponsorName( sponsorship ) }
				badge={
					needsCompany( sponsorship ) ? (
						<Badge className="sponsor-vetting__status" intent="informational">
							{ __( 'Needs a company', 'wordcamporg' ) }
						</Badge>
					) : (
						<StatusBadge status={ status } label={ labels[ status ] ?? status } />
					)
				}
				onClose={ () => open() }
			>
				<Hint>{ [ event.name, formatDay( event.date ) ].filter( Boolean ).join( ' · ' ) }</Hint>
			</CanvasHeader>
			<Stack direction="column" gap="lg" className="sponsor-vetting__canvas-body is-narrow">
				<Section title={ __( 'Link to a company', 'wordcamporg' ) }>
					{ suggested && suggestion ? (
						<>
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
									loading={ running === `link-${ id }` && ! pick.company }
									onClick={ () => changes.link( sponsorship, suggested.id ) }
								>
									{ sprintf(
										/* translators: %s: Company name. */
										__( 'Link to %s', 'wordcamporg' ),
										getName( suggested )
									) }
								</Button>
							</Stack>
						</>
					) : (
						<Text>{ __( 'Not linked to a company yet.', 'wordcamporg' ) }</Text>
					) }
					<Stack direction="row" align="end" gap="sm">
						<div className="sponsor-vetting__pick">
							<DataForm
								data={ pick }
								fields={ fields }
								form={ COMPANY_PICK_FORM }
								onChange={ ( edits ) => setPick( ( current ) => ( { ...current, ...edits } ) ) }
							/>
						</div>
						<Button
							variant="outline"
							disabled={ ! pick.company }
							focusableWhenDisabled
							loading={ running === `link-${ id }` && Boolean( pick.company ) }
							onClick={ () => changes.link( sponsorship, pick.company ) }
						>
							{ __( 'Link', 'wordcamporg' ) }
						</Button>
					</Stack>
					{ sponsor && (
						<Stack direction="row">
							<Button variant="outline" loading={ running === 'create' } onClick={ onCreate }>
								{ __( 'Create a company from this sponsor', 'wordcamporg' ) }
							</Button>
						</Stack>
					) }
					<Hint>
						{ __(
							'Once it’s linked, this opens the company, with this event among its events.',
							'wordcamporg'
						) }
					</Hint>
				</Section>

				<Section title={ __( 'From the event', 'wordcamporg' ) }>
					<Row label={ __( 'Event', 'wordcamporg' ) }>{ event.name }</Row>
					<Row label={ __( 'Event date', 'wordcamporg' ) }>{ formatDay( event.date ) }</Row>
					{ sponsor ? (
						<>
							<Row label={ __( 'Sponsor', 'wordcamporg' ) }>{ sponsor.name }</Row>
							<Row label={ __( 'Website', 'wordcamporg' ) }>
								{ sponsor.website_url ? (
									<Link href={ sponsor.website_url } openInNewTab>
										{ sponsor.website }
									</Link>
								) : (
									sponsor.website
								) }
							</Row>
							<Row label={ __( 'Contact', 'wordcamporg' ) }>
								{ [ sponsor.first_name, sponsor.last_name ].filter( Boolean ).join( ' ' ) }
							</Row>
							<Row label={ __( 'Email', 'wordcamporg' ) }>{ sponsor.email }</Row>
							<Link href={ sponsor.edit_url } openInNewTab>
								{ __( 'Open the sponsor on the event site', 'wordcamporg' ) }
							</Link>
						</>
					) : (
						<Text>{ __( 'The event’s sponsor has been deleted.', 'wordcamporg' ) }</Text>
					) }
				</Section>

				{ sponsorship.log.length > 0 && (
					<Section title={ __( 'History', 'wordcamporg' ) }>
						<History entries={ sponsorship.log } />
					</Section>
				) }

				<Stack direction="row" gap="sm" wrap="wrap">
					{ others.map( ( next ) => (
						<Button
							key={ next }
							variant="minimal"
							tone="neutral"
							loading={ running === `status-${ id }` }
							onClick={ () => changes.setStatus( sponsorship, next, labels[ next ] ?? next ) }
						>
							{ getStatusChangeLabel( next, labels ) }
						</Button>
					) ) }
					<Button
						variant="minimal"
						tone="neutral"
						loading={ running === `trash-${ id }` }
						onClick={ onTrash }
					>
						{ __( 'Move to Trash', 'wordcamporg' ) }
					</Button>
				</Stack>
			</Stack>
		</>
	);
}

/**
 * A sponsorship in the Trash, which can only come back out of it.
 *
 * @param props             Props.
 * @param props.sponsorship The sponsorship.
 * @return The sponsorship.
 */
function Trashed( { sponsorship }: { sponsorship: SponsorshipDetails } ) {
	const { open } = useOpenRecord();
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
			<CanvasHeader title={ getName( sponsorship ) } onClose={ () => open() } />
			<Stack direction="column" gap="lg" className="sponsor-vetting__canvas-body is-narrow">
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
		</>
	);
}

/**
 * The open sponsorship, where it isn't shown as one of its company's events: because it has no company yet, or is
 * in the Trash.
 *
 * @param props    Props.
 * @param props.id The sponsorship.
 * @return The sponsorship, or how loading it is going.
 */
export function SponsorshipCanvas( { id }: { id: number } ) {
	const { open } = useOpenRecord();
	const { record } = useEntityRecord< SponsorshipDetails >( 'postType', SPONSORSHIP, id );
	const { records: companies, error: companiesError } = useRecords< Company >( COMPANY, COMPANIES_QUERY );
	const error = useSelect(
		( select ) => select( coreStore ).getResolutionError( 'getEntityRecord', [ 'postType', SPONSORSHIP, id ] ),
		[ id ]
	);
	const linkable = useMemo(
		() => ( companies ?? [] ).filter( ( { status } ) => status !== TRASH ),
		[ companies ]
	);

	if ( ! record || ! companies ) {
		return (
			<CanvasPlaceholder
				error={ error ?? companiesError }
				title={ __( 'The sponsorship couldn’t be loaded.', 'wordcamporg' ) }
				onClose={ () => open() }
			/>
		);
	}

	if ( record.status === TRASH ) {
		return <Trashed sponsorship={ record } />;
	}

	return <Unlinked sponsorship={ record } companies={ linkable } />;
}
