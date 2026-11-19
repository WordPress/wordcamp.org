/**
 * The fields of the two lists, and how a record's name, status and flags are shown.
 */

import type { Field } from '@wordpress/dataviews';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Link, Stack, Text } from '@wordpress/ui';
import { compareDays, compareEventDays, formatDay } from './dates';
import { STATUS_INTENTS, TRASH } from './entities';
import type { Intent } from './entities';
import { getEventFlag } from './events';
import type { Company, CompanySummary, Event, Flag, Sponsorship, Statuses } from './types';

/**
 * A status as a list offers it: its slug, or a name of the screen's own, and its label.
 */
type StatusOption = { value: string; label: string };

/**
 * What the list of events calls a sponsorship that waits for a company, which is no status of its own.
 */
const NEEDS_COMPANY = 'needs-company';

/**
 * What the list of events calls an approval given in good faith, which it tells from the rest.
 */
const GOOD_FAITH = 'good-faith';

/**
 * A company's or sponsorship's status.
 *
 * @param props        Props.
 * @param props.status The status.
 * @param props.label  Its label.
 * @return The status, on one line however narrow its column.
 */
export function StatusBadge( { status, label }: { status: string; label: string } ) {
	return (
		<Badge className="sponsor-vetting__status" intent={ STATUS_INTENTS[ status ] ?? 'none' }>
			{ label }
		</Badge>
	);
}

/**
 * A company's flags: what a reviewer should know beyond its status.
 *
 * @param props       Props.
 * @param props.flags The flags.
 * @return The flags, one per line.
 */
export function Flags( { flags }: { flags: Flag[] } ) {
	return (
		<Stack direction="column" gap="xs">
			{ flags.map( ( flag ) => (
				<Text
					key={ flag.type }
					className={
						flag.overdue || flag.type === 'event_soon' ? 'sponsor-vetting__urgent' : undefined
					}
					title={ flag.date ? formatDay( flag.date ) : undefined }
				>
					{ flag.url ? (
						<Link href={ flag.url } openInNewTab>
							{ flag.text }
						</Link>
					) : (
						flag.text
					) }
				</Text>
			) ) }
		</Stack>
	);
}

/**
 * A company's websites, as links that open in a new tab.
 *
 * @param props         Props.
 * @param props.domains The domains.
 * @return The links.
 */
export function Domains( { domains }: { domains: string[] } ) {
	return (
		<Stack direction="row" gap="sm" wrap="wrap">
			{ domains.map( ( domain ) => (
				<Link key={ domain } href={ `https://${ domain }` } tone="neutral" openInNewTab>
					{ domain }
				</Link>
			) ) }
		</Stack>
	);
}

/**
 * Gets a record's name: a company's, or a sponsorship's. Titles are stored escaped.
 *
 * @param record           The company or sponsorship.
 * @param record.title     Its title.
 * @param record.title.raw
 * @return The name.
 */
export function getName( { title }: { title: { raw: string } } ): string {
	return decodeEntities( title.raw );
}

/**
 * Builds the two status fields of a list: the one that's shown, sorted and grouped by, and the one that filters.
 *
 * DataViews heads a group with its field's value, so the first has the status's label for one. A filter is kept
 * with the vetter's view, so the second has the slug: a kept filter still matches once a label is reworded, or
 * read in another language.
 *
 * @param options   The statuses the list has, in the order they sort in.
 * @param getStatus Gets the status of a row.
 * @param render    Shows it.
 * @return The fields.
 */
function getStatusFields< Item >(
	options: StatusOption[],
	getStatus: ( item: Item ) => string,
	render: NonNullable< Field< Item >[ 'render' ] >
): Field< Item >[] {
	const labels = new Map( options.map( ( { value, label } ) => [ value, label ] ) );
	const order = options.map( ( { label } ) => label );

	return [
		{
			id: 'status',
			label: __( 'Status', 'wordcamporg' ),
			getValue: ( { item } ) => labels.get( getStatus( item ) ) ?? getStatus( item ),
			filterBy: false,
			render,
			sort: ( a: string, b: string, direction ) => {
				const delta = order.indexOf( a ) - order.indexOf( b );

				return direction === 'asc' ? delta : -delta;
			},
		},
		{
			id: 'status_filter',
			label: __( 'Status', 'wordcamporg' ),
			getValue: ( { item } ) => getStatus( item ),
			elements: options,
			filterBy: { operators: [ 'isAny', 'isNone' ], isPrimary: true },
			enableHiding: false,
			enableSorting: false,
		},
	];
}

/**
 * Offers a status in a list's filter, under its label.
 *
 * @param status The status.
 * @param labels The statuses' labels, keyed by status.
 * @return The option.
 */
function toOption( status: string, labels: Record< string, string > ): StatusOption {
	return { value: status, label: labels[ status ] ?? status };
}

/**
 * Builds the Follow-up field of a list: what a reviewer should know about a company beyond its status.
 *
 * @param getFlags Gets the flags of a row: its company's, and any of its own.
 * @param own      The flags a row can have of its own, as the filter offers them.
 * @return The field.
 */
function getFollowUpField< Item >(
	getFlags: ( item: Item ) => Flag[],
	own: { value: string; label: string }[] = []
): Field< Item > {
	return {
		id: 'follow_up',
		label: __( 'Follow-up', 'wordcamporg' ),
		type: 'array',
		elements: [
			...own,
			{ value: 'replied', label: __( 'Sponsor replied', 'wordcamporg' ) },
			{ value: 'good_faith', label: __( 'Good Faith', 'wordcamporg' ) },
			{ value: 'lapsed', label: __( 'Vetting has lapsed', 'wordcamporg' ) },
			{ value: 'not_eligible_ended', label: __( 'Not Eligible period has ended', 'wordcamporg' ) },
		],
		filterBy: { operators: [ 'isAny', 'isNone' ] },
		enableSorting: false,
		getValue: ( { item } ) => getFlags( item ).map( ( flag ) => flag.type ),
		render: ( { item } ) => <Flags flags={ getFlags( item ) } />,
	};
}

/**
 * Builds the fields of the company list.
 *
 * @param statuses         The statuses.
 * @param statuses.company Those of a company, in the order of the pipeline.
 * @param statuses.labels  Every status's label, keyed by status.
 * @return The fields.
 */
export function getCompanyFields( {
	company,
	labels,
}: Pick< Statuses, 'company' | 'labels' > ): Field< Company >[] {
	return [
		{
			id: 'title',
			label: __( 'Company', 'wordcamporg' ),
			enableGlobalSearch: true,
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => getName( item ),
		},
		{
			id: 'domains',
			label: __( 'Website', 'wordcamporg' ),
			enableGlobalSearch: true,
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => item.domains.join( ' ' ),
			render: ( { item } ) => <Domains domains={ item.domains } />,
		},
		...getStatusFields< Company >(
			[ ...company.map( ( { slug } ) => slug ), TRASH ].map( ( status ) => toOption( status, labels ) ),
			( { status } ) => status,
			( { item } ) => <StatusBadge status={ item.status } label={ labels[ item.status ] ?? item.status } />
		),
		{
			...getFollowUpField< Company >( ( { flags } ) => flags ),
			filterBy: { operators: [ 'isAny', 'isNone' ], isPrimary: true },
		},
		{
			id: 'next_event',
			label: __( 'Next event', 'wordcamporg' ),
			type: 'date',
			render: ( { item } ) => formatDay( item.next_event ),
			sort: compareDays,
		},
		{
			id: 'sponsorships',
			label: __( 'Events', 'wordcamporg' ),
			type: 'integer',
			filterBy: false,
		},
		{
			id: 'last_vetted',
			label: __( 'Last vetted', 'wordcamporg' ),
			type: 'date',
			render: ( { item } ) => formatDay( item.last_vetted ),
			sort: compareDays,
		},
	];
}

/**
 * Names an event for a list: by its name and, once it has one, its date.
 *
 * @param event      The event.
 * @param event.name
 * @param event.date
 * @return The heading.
 */
export function getEventHeading( { name, date }: Event ): string {
	return date ? `${ name } · ${ formatDay( date ) }` : name;
}

/**
 * Says how a sponsorship's approval stands, telling one given in good faith from the rest.
 *
 * @param sponsorship            The sponsorship.
 * @param sponsorship.status     Its status.
 * @param sponsorship.good_faith Whether it was approved in good faith.
 * @param labels                 The statuses' labels, keyed by status.
 * @return The label.
 */
export function getApprovalLabel(
	{ status, good_faith: goodFaith }: Pick< Sponsorship, 'status' | 'good_faith' >,
	labels: Record< string, string >
): string {
	return status === 'svs-approved' && goodFaith
		? __( 'Approved in Good Faith', 'wordcamporg' )
		: labels[ status ] ?? status;
}

/**
 * Tells whether a sponsorship waits for a vetter to link it to a company.
 *
 * @param sponsorship         The sponsorship.
 * @param sponsorship.company Its company, or 0.
 * @param sponsorship.status  Its status.
 * @return Whether it does.
 */
export function needsCompany( { company, status }: Pick< Sponsorship, 'company' | 'status' > ): boolean {
	return ! company && status === 'svs-pending';
}

/**
 * Gets the one status the list of events shows for a sponsorship: its own once that's decided, and until then what
 * it waits for, which is its company's vetting or, before that, a company. One that waits for neither is Pending:
 * its company is vetted, and a vetter has yet to approve it.
 *
 * @param sponsorship The sponsorship, with what it shows of its company, if it has one.
 * @param labels      The statuses' labels, keyed by status.
 * @return The status, its label, and the intent of its badge.
 */
export function getSponsorStatus(
	sponsorship: Sponsorship,
	labels: Record< string, string >
): StatusOption & { intent: Intent } {
	const company = sponsorship.company_summary;

	if ( needsCompany( sponsorship ) ) {
		return { value: NEEDS_COMPANY, label: __( 'Needs a company', 'wordcamporg' ), intent: 'informational' };
	}

	if ( sponsorship.status === 'svs-pending' && company && company.status !== 'sv-compliant' ) {
		return { ...toOption( company.status, labels ), intent: STATUS_INTENTS[ company.status ] ?? 'none' };
	}

	return {
		value: sponsorship.status === 'svs-approved' && sponsorship.good_faith ? GOOD_FAITH : sponsorship.status,
		label: getApprovalLabel( sponsorship, labels ),
		intent: STATUS_INTENTS[ sponsorship.status ] ?? 'none',
	};
}

/**
 * Gets who a sponsorship is from: its company or, before it has one, the sponsor as its event named it.
 *
 * @param sponsorship The sponsorship, whose own name is the sponsor's followed by the event's.
 * @param company     Its company, if it has one.
 * @return The name.
 */
export function getSponsorName(
	sponsorship: Pick< Sponsorship, 'title' | 'event' >,
	company?: Pick< CompanySummary, 'name' > | null
): string {
	if ( company ) {
		return company.name;
	}

	const name = getName( sponsorship );
	const suffix = ` — ${ sponsorship.event.name }`;

	return name.endsWith( suffix ) ? name.slice( 0, -suffix.length ) : name;
}

/**
 * Builds the fields of the list of events, which shows each sponsorship as its sponsor at an event.
 *
 * @param statuses     The statuses of the two post types, and every status's label.
 * @param sponsorships The sponsorships, for when their events are and how many wait for a company.
 * @param today        Today, as `Y-m-d`.
 * @return The fields.
 */
export function getSponsorshipFields(
	statuses: Statuses,
	sponsorships: Sponsorship[],
	today: string
): Field< Sponsorship >[] {
	const { labels } = statuses;
	const getStatus = ( item: Sponsorship ) => getSponsorStatus( item, labels );
	/*
	 * A field is sorted by its value, and a group is headed by it: so the heading says when, the date is the day
	 * the event starts, and these know its other days. Events that start the same day sort as the last to end.
	 */
	const undated = { date: '', end: '' };
	const byHeading = new Map( sponsorships.map( ( { event } ) => [ getEventHeading( event ), event ] ) );
	const byStart = new Map< string, Event >();

	for ( const { event } of sponsorships ) {
		if ( event.end > ( byStart.get( event.date )?.end ?? '' ) ) {
			byStart.set( event.date, event );
		}
	}

	const compareEvents = ( a: string, b: string ) =>
		compareEventDays( byHeading.get( a ) ?? undated, byHeading.get( b ) ?? undated, today ) ||
		a.localeCompare( b );
	const compareStarts = ( a: string, b: string ) =>
		compareEventDays( byStart.get( a ) ?? undated, byStart.get( b ) ?? undated, today );
	// What still takes a vetter comes first: a company, an approval, its company's vetting. Then what's decided.
	const options: StatusOption[] = [
		{ value: NEEDS_COMPANY, label: __( 'Needs a company', 'wordcamporg' ) },
		toOption( 'svs-pending', labels ),
		...statuses.company
			.filter( ( { slug } ) => slug !== 'sv-compliant' )
			.map( ( { slug } ) => toOption( slug, labels ) ),
		...statuses.sponsorship
			.filter( ( { slug } ) => slug !== 'svs-pending' )
			.flatMap( ( { slug } ) =>
				slug === 'svs-approved'
					? [
							toOption( slug, labels ),
							{ value: GOOD_FAITH, label: __( 'Approved in Good Faith', 'wordcamporg' ) },
					  ]
					: [ toOption( slug, labels ) ]
			),
		toOption( TRASH, labels ),
	];

	return [
		{
			id: 'title',
			label: __( 'Sponsor', 'wordcamporg' ),
			enableGlobalSearch: true,
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => getSponsorName( item, item.company_summary ),
		},
		{
			id: 'event',
			label: __( 'Event', 'wordcamporg' ),
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => getEventHeading( item.event ),
			// Ascending is the order a vetter works in: the soonest event still to come first.
			sort: ( a: string, b: string, direction ) =>
				direction === 'asc' ? compareEvents( a, b ) : compareEvents( b, a ),
		},
		{
			id: 'event_date',
			label: __( 'Event date', 'wordcamporg' ),
			type: 'date',
			getValue: ( { item } ) => item.event.date,
			render: ( { item } ) => formatDay( item.event.date ),
			sort: ( a: string, b: string, direction ) =>
				direction === 'asc' ? compareStarts( a, b ) : compareStarts( b, a ),
		},
		...getStatusFields< Sponsorship >(
			options,
			( item ) => getStatus( item ).value,
			( { item } ) => {
				const { label, intent } = getStatus( item );

				return (
					<Badge className="sponsor-vetting__status" intent={ intent }>
						{ label }
					</Badge>
				);
			}
		),
		getFollowUpField< Sponsorship >(
			( item ) => {
				const flag = getEventFlag( item, today );

				return [ ...( flag ? [ flag ] : [] ), ...( item.company_summary?.flags ?? [] ) ];
			},
			[ { value: 'event_soon', label: __( 'Event within a week', 'wordcamporg' ) } ]
		),
		{
			id: 'needs_company',
			label: sprintf(
				/* translators: %d: Number of sponsorships. */
				__( 'Needs a company (%d)', 'wordcamporg' ),
				sponsorships.filter( needsCompany ).length
			),
			elements: [
				{ value: 'yes', label: __( 'Yes', 'wordcamporg' ) },
				{ value: 'no', label: __( 'No', 'wordcamporg' ) },
			],
			// Pinned, so the count of what's waiting is always in the row of filters.
			filterBy: { operators: [ 'is' ], isPrimary: true },
			enableSorting: false,
			getValue: ( { item } ) => ( needsCompany( item ) ? 'yes' : 'no' ),
		},
		{
			id: 'approved',
			label: __( 'Approved', 'wordcamporg' ),
			enableSorting: false,
			filterBy: false,
		},
	];
}
