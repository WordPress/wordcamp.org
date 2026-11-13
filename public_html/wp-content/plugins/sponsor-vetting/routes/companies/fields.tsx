import type { Field, View } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { Badge, Link, Stack, Text } from '@wordpress/ui';
import { compareDays, formatDay } from './dates';
import { COMPANY_STATUSES, COMPANY_STATUS_ORDER } from './statuses';
import type { Company, CompanyStatus, Flag } from './types';

/* What each layout shows beside the company's name and website. A list row has no column headers, so it keeps to
   the fields that explain themselves. */
export const DEFAULT_LAYOUTS = {
	table: { fields: [ 'status', 'follow_up', 'next_event', 'sponsorships', 'last_vetted' ] },
	list: { fields: [ 'status', 'follow_up', 'next_event' ] },
};

export const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 50,
	search: '',
	titleField: 'name',
	descriptionField: 'domains',
	sort: { field: 'next_event', direction: 'asc' },
	...DEFAULT_LAYOUTS.table,
};

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

export const fields: Field< Company >[] = [
	{
		id: 'name',
		label: __( 'Company', 'wordcamporg' ),
		enableGlobalSearch: true,
		enableHiding: false,
		filterBy: false,
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
		elements: COMPANY_STATUS_ORDER.map( ( value ) => ( { value, label: COMPANY_STATUSES[ value ].label } ) ),
		filterBy: { operators: [ 'isAny', 'isNone' ], isPrimary: true },
		render: ( { item } ) => (
			<Badge className="sponsor-vetting__status" intent={ COMPANY_STATUSES[ item.status ].intent }>
				{ COMPANY_STATUSES[ item.status ].label }
			</Badge>
		),
		// In the order of the pipeline, not of the alphabet.
		sort: ( a: CompanyStatus, b: CompanyStatus, direction ) => {
			const delta = COMPANY_STATUS_ORDER.indexOf( a ) - COMPANY_STATUS_ORDER.indexOf( b );

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
