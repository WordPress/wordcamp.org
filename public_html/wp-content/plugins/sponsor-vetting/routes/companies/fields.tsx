import type { Field } from '@wordpress/dataviews';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { Badge, Link, Stack, Text } from '@wordpress/ui';
import { compareDays, formatDay } from './dates';
import { COMPANY_STATUSES, STATUS_INTENTS } from './entities';
import type { Company, Flag } from './types';

/**
 * A company's or sponsorship's status.
 *
 * @param props        Props.
 * @param props.status The status.
 * @param props.label  Its label.
 * @return The status, on one line however narrow its column.
 */
export function StatusBadge( { status, label }: { status: keyof typeof STATUS_INTENTS; label: string } ) {
	return (
		<Badge className="sponsor-vetting__status" intent={ STATUS_INTENTS[ status ] }>
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
					{ flag.url ? <Link href={ flag.url }>{ flag.text }</Link> : flag.text }
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
 * Gets a company's name. Titles are stored escaped.
 *
 * @param company The company.
 * @return The name.
 */
export function getCompanyName( company: Company ): string {
	return decodeEntities( company.title.raw );
}

/**
 * Builds the fields of the company list.
 *
 * @param labels The statuses' labels, keyed by status.
 * @return The fields.
 */
export function getCompanyFields( labels: Record< string, string > ): Field< Company >[] {
	const statusLabels = COMPANY_STATUSES.map( ( status ) => labels[ status ] ?? status );

	return [
		{
			id: 'title',
			label: __( 'Company', 'wordcamporg' ),
			enableGlobalSearch: true,
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => getCompanyName( item ),
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
		{
			id: 'status',
			label: __( 'Status', 'wordcamporg' ),
			// A group is headed by its field's value, so the value is the status's label, not its slug.
			getValue: ( { item } ) => labels[ item.status ] ?? item.status,
			elements: statusLabels.map( ( label ) => ( { value: label, label } ) ),
			filterBy: { operators: [ 'isAny', 'isNone' ], isPrimary: true },
			render: ( { item } ) => (
				<StatusBadge status={ item.status } label={ labels[ item.status ] ?? item.status } />
			),
			// In the order of the pipeline, not of the alphabet.
			sort: ( a: string, b: string, direction ) => {
				const delta = statusLabels.indexOf( a ) - statusLabels.indexOf( b );

				return direction === 'asc' ? delta : -delta;
			},
		},
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
