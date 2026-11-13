import type { Field } from '@wordpress/dataviews';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { Badge, Link, Stack, Text } from '@wordpress/ui';
import { compareDays, compareEventDays, formatDay } from './dates';
import { COMPANY_STATUSES, SPONSORSHIP_STATUSES, STATUS_INTENTS, TRASH } from './entities';
import type { AnyStatus } from './entities';
import type { Company, Event, Flag, Sponsorship } from './types';
import { NEEDS_COMPANY } from './view';

/**
 * A company's or sponsorship's status.
 *
 * @param props        Props.
 * @param props.status The status.
 * @param props.label  Its label.
 * @return The status, on one line however narrow its column.
 */
export function StatusBadge( { status, label }: { status: AnyStatus; label: string } ) {
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
					className={ flag.overdue ? 'sponsor-vetting__overdue' : undefined }
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
 * Builds the status field of a list, which filters by status, the Trash included, and sorts in the statuses' own
 * order.
 *
 * @param statuses The post type's statuses, in order.
 * @param labels   The statuses' labels, keyed by status.
 * @return The field.
 */
function getStatusField< Item extends { status: AnyStatus } >(
	statuses: readonly AnyStatus[],
	labels: Record< string, string >
): Field< Item > {
	const order = [ ...statuses, TRASH ].map( ( status ) => labels[ status ] ?? status );

	return {
		id: 'status',
		label: __( 'Status', 'wordcamporg' ),
		// A group is headed by its field's value, so the value is the status's label, not its slug.
		getValue: ( { item } ) => labels[ item.status ] ?? item.status,
		elements: order.map( ( label ) => ( { value: label, label } ) ),
		filterBy: { operators: [ 'isAny', 'isNone' ], isPrimary: true },
		render: ( { item } ) => (
			<StatusBadge status={ item.status } label={ labels[ item.status ] ?? item.status } />
		),
		sort: ( a: string, b: string, direction ) => {
			const delta = order.indexOf( a ) - order.indexOf( b );

			return direction === 'asc' ? delta : -delta;
		},
	};
}

/**
 * Builds the fields of the company list.
 *
 * @param labels The statuses' labels, keyed by status.
 * @return The fields.
 */
export function getCompanyFields( labels: Record< string, string > ): Field< Company >[] {
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
		getStatusField< Company >( COMPANY_STATUSES, labels ),
		{
			id: 'follow_up',
			label: __( 'Follow-up', 'wordcamporg' ),
			type: 'array',
			elements: [
				{ value: 'replied', label: __( 'Sponsor replied', 'wordcamporg' ) },
				{ value: 'good_faith', label: __( 'Good Faith', 'wordcamporg' ) },
				{ value: 'lapsed', label: __( 'Vetting has lapsed', 'wordcamporg' ) },
				{ value: 'not_eligible_ended', label: __( 'Not Eligible period has ended', 'wordcamporg' ) },
			],
			filterBy: { operators: [ 'isAny', 'isNone' ], isPrimary: true },
			enableSorting: false,
			getValue: ( { item } ) => item.flags.map( ( flag ) => flag.type ),
			render: ( { item } ) => <Flags flags={ item.flags } />,
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
 * Builds the fields of the list of events, which shows each sponsorship with its event and its company.
 *
 * @param labels       The statuses' labels, keyed by status.
 * @param companies    The companies, for their names and statuses.
 * @param sponsorships The sponsorships, for when their events are.
 * @param today        Today, as `Y-m-d`.
 * @return The fields.
 */
export function getSponsorshipFields(
	labels: Record< string, string >,
	companies: Company[],
	sponsorships: Sponsorship[],
	today: string
): Field< Sponsorship >[] {
	const byId = new Map( companies.map( ( company ) => [ company.id, company ] ) );
	const getCompany = ( { company }: Sponsorship ) => byId.get( company );
	// A group is headed by its field's value, and sorted by it: so the heading says when, and this knows when.
	const days = new Map( sponsorships.map( ( { event } ) => [ getEventHeading( event ), event.date ] ) );
	const compareEvents = ( a: string, b: string ) =>
		compareEventDays( days.get( a ) ?? '', days.get( b ) ?? '', today ) || a.localeCompare( b );

	return [
		{
			id: 'title',
			label: __( 'Sponsorship', 'wordcamporg' ),
			enableGlobalSearch: true,
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => getName( item ),
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
				direction === 'asc' ? compareEventDays( a, b, today ) : compareEventDays( b, a, today ),
		},
		{
			id: 'company',
			label: __( 'Company', 'wordcamporg' ),
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => {
				const company = getCompany( item );

				return company ? getName( company ) : '';
			},
			render: ( { item } ) => {
				const company = getCompany( item );

				if ( ! company ) {
					return item.company ? '' : __( 'Needs a company', 'wordcamporg' );
				}

				return `${ getName( company ) } (${ labels[ company.status ] ?? company.status })`;
			},
		},
		{
			id: NEEDS_COMPANY.field,
			label: __( 'Linked to a company', 'wordcamporg' ),
			elements: [
				{ value: 'yes', label: __( 'Yes', 'wordcamporg' ) },
				{ value: NEEDS_COMPANY.value, label: __( 'No', 'wordcamporg' ) },
			],
			filterBy: { operators: [ 'is' ] },
			enableSorting: false,
			getValue: ( { item } ) => ( item.company ? 'yes' : NEEDS_COMPANY.value ),
		},
		getStatusField< Sponsorship >( SPONSORSHIP_STATUSES, labels ),
		{
			id: 'approved',
			label: __( 'Approved', 'wordcamporg' ),
			enableSorting: false,
			filterBy: false,
		},
	];
}
