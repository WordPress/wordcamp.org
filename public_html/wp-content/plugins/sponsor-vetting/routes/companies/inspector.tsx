import { useSelect } from '@wordpress/data';
import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp, close } from '@wordpress/icons';
import { Badge, ButtonLink, Card, IconButton, Link, Notice, Spinner, Stack, Text } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { formatDay, formatTime } from './dates';
import { Domains, Flags } from './fields';
import { useCompanyList, useOpenCompany } from './hooks';
import { COMPANY_STATUSES, SPONSORSHIP_STATUSES } from './statuses';
import { store } from './store';
import type { CompanyDetails, CompanyEvent, LogEntry } from './types';

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
 * @param props       Props.
 * @param props.event The sponsorship.
 * @return The event.
 */
function Event( { event }: { event: CompanyEvent } ) {
	const status = SPONSORSHIP_STATUSES[ event.status ];

	return (
		<Stack direction="column" gap="xs" className="sponsor-vetting__event">
			<Stack direction="row" justify="space-between" align="start" gap="md">
				<Link href={ event.edit_url } tone="neutral">
					{ event.name }
				</Link>
				<Badge intent={ status.intent }>{ status.label }</Badge>
			</Stack>
			<Text variant="body-sm" className="sponsor-vetting__label">
				{ [
					formatDay( event.date ),
					event.approved && `${ __( 'Approved', 'wordcamporg' ) } ${ event.approved }`,
				]
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
function Entry( { entry }: { entry: LogEntry } ) {
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
 * @param props         Props.
 * @param props.company The company.
 * @return The details.
 */
function Details( { company }: { company: CompanyDetails } ) {
	const status = COMPANY_STATUSES[ company.status ];
	const contact = [ company.contact.first_name, company.contact.last_name ].filter( Boolean ).join( ' ' );

	return (
		<Stack direction="column" gap="lg">
			<Stack direction="column" gap="xs">
				<Text variant="heading-lg" render={ <h2 /> }>
					{ company.name }
				</Text>
				<Domains domains={ company.domains } />
			</Stack>

			<Section title={ __( 'Vetting', 'wordcamporg' ) }>
				<Row label={ __( 'Status', 'wordcamporg' ) }>
					<Badge intent={ status.intent }>{ status.label }</Badge>
				</Row>
				<Row label={ __( 'Follow-up', 'wordcamporg' ) }>
					{ company.flags.length > 0 && <Flags flags={ company.flags } /> }
				</Row>
				<Row label={ __( 'Last vetted', 'wordcamporg' ) }>
					{ formatDay( company.last_vetted ) || __( 'Never', 'wordcamporg' ) }
				</Row>
				<Row label={ __( 'Not Eligible until', 'wordcamporg' ) }>
					{ formatDay( company.not_eligible_until ) }
				</Row>
				<Row label={ __( 'Good Faith due', 'wordcamporg' ) }>{ formatDay( company.good_faith_due ) }</Row>
			</Section>

			<Section title={ __( 'Events', 'wordcamporg' ) }>
				{ company.events.length === 0 && <Text>{ __( 'No sponsorships yet.', 'wordcamporg' ) }</Text> }
				{ company.events.map( ( event ) => (
					<Event key={ event.id } event={ event } />
				) ) }
			</Section>

			<Section title={ __( 'Findings', 'wordcamporg' ) }>
				<Row label={ __( 'GPL', 'wordcamporg' ) } stacked>
					{ company.gpl_findings || __( 'Not written yet.', 'wordcamporg' ) }
				</Row>
				<Row label={ __( 'Trademark', 'wordcamporg' ) } stacked>
					{ company.trademark_findings || __( 'Not written yet.', 'wordcamporg' ) }
				</Row>
				<Row label={ __( 'Compliance requests', 'wordcamporg' ) } stacked>
					{ company.conversations.length > 0 && (
						<Stack direction="column" gap="xs">
							{ company.conversations.map( ( conversation ) => (
								<Link key={ conversation.url } href={ conversation.url } openInNewTab>
									{ formatTime( conversation.time ) }
								</Link>
							) ) }
						</Stack>
					) }
				</Row>
			</Section>

			<Section title={ __( 'Company', 'wordcamporg' ) }>
				<Row label={ __( 'Contact', 'wordcamporg' ) }>{ contact }</Row>
				<Row label={ __( 'Email', 'wordcamporg' ) }>{ company.contact.email }</Row>
				<Row label={ __( 'Multi-Event Sponsor', 'wordcamporg' ) }>{ company.mes }</Row>
				{ ! contact && ! company.contact.email && ! company.mes && (
					<Text>{ __( 'No contact yet.', 'wordcamporg' ) }</Text>
				) }
			</Section>

			<Section title={ __( 'Notes and history', 'wordcamporg' ) }>
				{ company.log.map( ( entry, index ) => (
					<Entry key={ index } entry={ entry } />
				) ) }
			</Section>
		</Stack>
	);
}

/**
 * The panel beside the list: the company the address names, and the way to the ones before and after it.
 *
 * @return The inspector, or nothing while no company is open.
 */
function Inspector() {
	const [ openId, open ] = useOpenCompany();
	const { data } = useCompanyList();
	const { company, error } = useSelect(
		( select ) => ( {
			company: openId ? select( store ).getCompany( openId ) : undefined,
			error: openId
				? ( select( store ).getResolutionError( 'getCompany', [ openId ] ) as Error | undefined )
				: undefined,
		} ),
		[ openId ]
	);

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
				{ company ? (
					<ButtonLink href={ company.edit_url } variant="outline" size="compact">
						{ __( 'Edit', 'wordcamporg' ) }
					</ButtonLink>
				) : (
					<span />
				) }
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
				{ error && (
					<Notice.Root intent="error">
						<Notice.Title>{ __( 'The company couldn’t be loaded.', 'wordcamporg' ) }</Notice.Title>
						<Notice.Description>{ error.message }</Notice.Description>
					</Notice.Root>
				) }
				{ ! error && ! company && (
					<Stack justify="center" className="sponsor-vetting__loading">
						<Spinner />
					</Stack>
				) }
				{ company && <Details company={ company } /> }
			</div>
		</>
	);
}

export const inspector = Inspector;
