import { store as coreStore, useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { RawHTML, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp, close } from '@wordpress/icons';
import { ButtonLink, Card, IconButton, Link, Notice, Spinner, Stack, Text } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { formatDay, formatTime } from './dates';
import { COMPANY, SPONSORSHIP, getSponsorshipsQuery } from './entities';
import { Domains, Flags, StatusBadge, getCompanyName } from './fields';
import { useCompanyList, useOpenCompany, useStatusLabels } from './hooks';
import { buildTimeline, sortByEvent } from './timeline';
import type { TimelineEntry } from './timeline';
import type { CompanyDetails, Sponsorship } from './types';

/**
 * Builds the address of a record's own screen, which is where it's edited for now.
 *
 * @param id The company or sponsorship.
 * @return The address, relative to wp-admin.
 */
function getEditUrl( id: number ): string {
	return `post.php?post=${ id }&action=edit`;
}

/**
 * A titled group of the panel.
 *
 * @param props          Props.
 * @param props.title    The group's title.
 * @param props.children Its contents.
 * @return The group.
 */
function Section( { title, children }: { title: string; children: ReactNode } ) {
	return (
		<Card.Root className="sponsor-vetting__section">
			<Card.Header>
				<Card.Title>
					<Text variant="heading-sm" render={ <h3 /> }>
						{ title }
					</Text>
				</Card.Title>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="md">
					{ children }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}

/**
 * A label with its value, side by side or, for longer values, stacked.
 *
 * @param props          Props.
 * @param props.label    The label.
 * @param props.stacked  Whether the value goes under the label.
 * @param props.children The value. Nothing is rendered without one.
 * @return The row.
 */
function Row( { label, stacked = false, children }: { label: string; stacked?: boolean; children: ReactNode } ) {
	if ( ! children ) {
		return null;
	}

	return (
		<Stack
			direction={ stacked ? 'column' : 'row' }
			justify="space-between"
			align={ stacked ? 'stretch' : 'baseline' }
			gap={ stacked ? 'xs' : 'md' }
		>
			<Text variant="body-sm" className="sponsor-vetting__label">
				{ label }
			</Text>
			<Text
				render={ <div /> }
				className={ stacked ? 'sponsor-vetting__long-text' : 'sponsor-vetting__value' }
			>
				{ children }
			</Text>
		</Stack>
	);
}

/**
 * One of the company's sponsorships, shown as the event it's for.
 *
 * @param props             Props.
 * @param props.sponsorship The sponsorship.
 * @param props.label       Its status's label.
 * @return The event.
 */
function Event( { sponsorship, label }: { sponsorship: Sponsorship; label: string } ) {
	const { event, approved } = sponsorship;

	return (
		<Stack direction="column" gap="xs" className="sponsor-vetting__event">
			<Stack direction="row" justify="space-between" align="start" gap="md">
				<Link href={ getEditUrl( sponsorship.id ) } tone="neutral">
					{ event.name }
				</Link>
				<StatusBadge status={ sponsorship.status } label={ label } />
			</Stack>
			<Text variant="body-sm" className="sponsor-vetting__label">
				{ [ formatDay( event.date ), approved && `${ __( 'Approved', 'wordcamporg' ) } ${ approved }` ]
					.filter( Boolean )
					.join( ' · ' ) }
			</Text>
		</Stack>
	);
}

/**
 * A note or a status change, of the company or of one of its sponsorships.
 *
 * @param props       Props.
 * @param props.entry The entry.
 * @return The entry.
 */
function Entry( { entry }: { entry: TimelineEntry } ) {
	return (
		<Stack direction="column" gap="xs" className="sponsor-vetting__entry">
			<RawHTML>{ entry.html }</RawHTML>
			<Text variant="body-sm" className="sponsor-vetting__label">
				{ [ entry.event, formatTime( entry.time ) ].filter( Boolean ).join( ' · ' ) }
			</Text>
		</Stack>
	);
}

/**
 * Everything about a company: its vetting, events, findings, contact, and history.
 *
 * @param props              Props.
 * @param props.company      The company.
 * @param props.sponsorships Its sponsorships.
 * @return The details.
 */
function Details( { company, sponsorships }: { company: CompanyDetails; sponsorships: Sponsorship[] } ) {
	const { labels } = useStatusLabels();
	const { meta } = company;
	const contact = [ meta._sv_contact_first_name, meta._sv_contact_last_name ].filter( Boolean ).join( ' ' );
	const requests = company.conversations.filter( ( { type } ) => type === 'compliance' );
	const events = useMemo(
		() => sortByEvent( sponsorships, new Date().toISOString().slice( 0, 10 ) ),
		[ sponsorships ]
	);
	const timeline = useMemo( () => buildTimeline( company.log, sponsorships ), [ company.log, sponsorships ] );

	return (
		<Stack direction="column" gap="lg">
			<Stack direction="column" gap="xs">
				<Text variant="heading-lg" render={ <h2 /> }>
					{ getCompanyName( company ) }
				</Text>
				<Domains domains={ company.domains } />
			</Stack>

			<Section title={ __( 'Vetting', 'wordcamporg' ) }>
				<Row label={ __( 'Status', 'wordcamporg' ) }>
					<StatusBadge status={ company.status } label={ labels[ company.status ] ?? company.status } />
				</Row>
				<Row label={ __( 'Follow-up', 'wordcamporg' ) }>
					{ company.flags.length > 0 && <Flags flags={ company.flags } /> }
				</Row>
				<Row label={ __( 'Last vetted', 'wordcamporg' ) }>
					{ formatDay( company.last_vetted ) || __( 'Never', 'wordcamporg' ) }
				</Row>
				<Row label={ __( 'Not Eligible until', 'wordcamporg' ) }>
					{ formatDay( meta._sv_not_eligible_until ) }
				</Row>
				<Row label={ __( 'Good Faith due', 'wordcamporg' ) }>{ formatDay( meta._sv_good_faith_due ) }</Row>
			</Section>

			<Section title={ __( 'Events', 'wordcamporg' ) }>
				{ events.length === 0 && <Text>{ __( 'No sponsorships yet.', 'wordcamporg' ) }</Text> }
				{ events.map( ( sponsorship ) => (
					<Event
						key={ sponsorship.id }
						sponsorship={ sponsorship }
						label={ labels[ sponsorship.status ] ?? sponsorship.status }
					/>
				) ) }
			</Section>

			<Section title={ __( 'Findings', 'wordcamporg' ) }>
				<Row label={ __( 'GPL', 'wordcamporg' ) } stacked>
					{ meta._sv_gpl_findings || __( 'Not written yet.', 'wordcamporg' ) }
				</Row>
				<Row label={ __( 'Trademark', 'wordcamporg' ) } stacked>
					{ meta._sv_trademark_findings || __( 'Not written yet.', 'wordcamporg' ) }
				</Row>
				<Row label={ __( 'Compliance requests', 'wordcamporg' ) } stacked>
					{ requests.length > 0 && (
						<Stack direction="column" gap="xs">
							{ requests.map( ( request ) => (
								<Link key={ request.url } href={ request.url } openInNewTab>
									{ formatTime( request.time ) }
								</Link>
							) ) }
						</Stack>
					) }
				</Row>
			</Section>

			<Section title={ __( 'Company', 'wordcamporg' ) }>
				<Row label={ __( 'Contact', 'wordcamporg' ) }>{ contact }</Row>
				<Row label={ __( 'Email', 'wordcamporg' ) }>{ meta._sv_contact_email }</Row>
				<Row label={ __( 'Multi-Event Sponsor', 'wordcamporg' ) }>{ company.mes }</Row>
				{ ! contact && ! meta._sv_contact_email && ! company.mes && (
					<Text>{ __( 'No contact yet.', 'wordcamporg' ) }</Text>
				) }
			</Section>

			<Section title={ __( 'Notes and history', 'wordcamporg' ) }>
				{ timeline.map( ( entry, index ) => (
					<Entry key={ index } entry={ entry } />
				) ) }
			</Section>
		</Stack>
	);
}

/**
 * The open company: its record in full, which the list only has in part, and its sponsorships.
 *
 * @param props    Props.
 * @param props.id The company.
 * @return The company's details, or how loading them is going.
 */
function Company( { id }: { id: number } ) {
	const { record: company, hasResolved } = useEntityRecord< CompanyDetails >( 'postType', COMPANY, id );
	const query = useMemo( () => getSponsorshipsQuery( id ), [ id ] );
	const { records: sponsorships } = useEntityRecords< Sponsorship >( 'postType', SPONSORSHIP, query );
	const error = useSelect(
		( select ) => {
			const { getResolutionError } = select( coreStore );

			return ( getResolutionError( 'getEntityRecord', [ 'postType', COMPANY, id ] ) ??
				getResolutionError( 'getEntityRecords', [ 'postType', SPONSORSHIP, query ] ) ) as
				| Error
				| undefined;
		},
		[ id, query ]
	);

	if ( error ) {
		return (
			<Notice.Root intent="error">
				<Notice.Title>{ __( 'The company couldn’t be loaded.', 'wordcamporg' ) }</Notice.Title>
				<Notice.Description>{ error.message }</Notice.Description>
			</Notice.Root>
		);
	}

	if ( ! hasResolved || ! company || ! sponsorships ) {
		return (
			<Stack justify="center" className="sponsor-vetting__loading">
				<Spinner />
			</Stack>
		);
	}

	return <Details company={ company } sponsorships={ sponsorships } />;
}

/**
 * The panel beside the list: the company the address names, and the way to the ones before and after it.
 *
 * @return The inspector, or nothing while no company is open.
 */
function Inspector() {
	const [ openId, open ] = useOpenCompany();
	const { data } = useCompanyList();

	if ( ! openId ) {
		return null;
	}

	// The rows the list shows, in its order.
	const position = data.findIndex( ( { id } ) => id === openId );
	const previous = position > 0 ? data[ position - 1 ] : undefined;
	const next = position > -1 ? data[ position + 1 ] : undefined;

	return (
		<>
			<Stack
				direction="row"
				align="center"
				justify="space-between"
				gap="xs"
				className="sponsor-vetting__panel-header"
			>
				<ButtonLink href={ getEditUrl( openId ) } variant="outline" size="compact">
					{ __( 'Edit', 'wordcamporg' ) }
				</ButtonLink>
				<Stack direction="row" gap="xs">
					<IconButton
						icon={ chevronUp }
						label={ __( 'Previous company', 'wordcamporg' ) }
						variant="minimal"
						tone="neutral"
						size="compact"
						disabled={ ! previous }
						focusableWhenDisabled
						onClick={ () => open( previous?.id ) }
					/>
					<IconButton
						icon={ chevronDown }
						label={ __( 'Next company', 'wordcamporg' ) }
						variant="minimal"
						tone="neutral"
						size="compact"
						disabled={ ! next }
						focusableWhenDisabled
						onClick={ () => open( next?.id ) }
					/>
					<IconButton
						icon={ close }
						label={ __( 'Close', 'wordcamporg' ) }
						variant="minimal"
						tone="neutral"
						size="compact"
						onClick={ () => open() }
					/>
				</Stack>
			</Stack>
			<div className="sponsor-vetting__panel-body">
				<Company key={ openId } id={ openId } />
			</div>
		</>
	);
}

export const inspector = Inspector;
