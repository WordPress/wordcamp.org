import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp } from '@wordpress/icons';
import { IconButton } from '@wordpress/ui';
import { CompanyPanel } from './company-panel';
import { useCompanyList, useOpenRecord, useSponsorshipList } from './hooks';
import { SponsorshipPanel } from './sponsorship-panel';

/**
 * The way from the open record to the ones before and after it, among the rows its list shows, in its order.
 *
 * @param props        Props.
 * @param props.rows   The rows.
 * @param props.openId The open record.
 * @param props.open   Opens a record.
 * @param props.labels What the two buttons are called.
 * @return The buttons.
 */
function Nav( {
	rows,
	openId,
	open,
	labels,
}: {
	rows: { id: number }[];
	openId: number;
	open: ( id?: number ) => void;
	labels: [ string, string ];
} ) {
	const position = rows.findIndex( ( { id } ) => id === openId );
	const previous = position > 0 ? rows[ position - 1 ] : undefined;
	const next = position > -1 ? rows[ position + 1 ] : undefined;

	return (
		<>
			<IconButton
				icon={ chevronUp }
				label={ labels[ 0 ] }
				variant="minimal"
				tone="neutral"
				size="compact"
				disabled={ ! previous }
				focusableWhenDisabled
				onClick={ () => open( previous?.id ) }
			/>
			<IconButton
				icon={ chevronDown }
				label={ labels[ 1 ] }
				variant="minimal"
				tone="neutral"
				size="compact"
				disabled={ ! next }
				focusableWhenDisabled
				onClick={ () => open( next?.id ) }
			/>
		</>
	);
}

/**
 * The way through the companies the list shows.
 *
 * @param props        Props.
 * @param props.openId The open company.
 * @return The buttons.
 */
function CompanyNav( { openId }: { openId: number } ) {
	const { data } = useCompanyList();
	const { open } = useOpenRecord();

	return (
		<Nav
			rows={ data }
			openId={ openId }
			open={ ( company ) => open( { company } ) }
			labels={ [ __( 'Previous company', 'wordcamporg' ), __( 'Next company', 'wordcamporg' ) ] }
		/>
	);
}

/**
 * The way through the sponsorships the list of events shows.
 *
 * @param props        Props.
 * @param props.openId The open sponsorship.
 * @return The buttons.
 */
function SponsorshipNav( { openId }: { openId: number } ) {
	const { data } = useSponsorshipList();
	const { open } = useOpenRecord();

	return (
		<Nav
			rows={ data }
			openId={ openId }
			open={ ( sponsorship ) => open( { sponsorship } ) }
			labels={ [ __( 'Previous sponsorship', 'wordcamporg' ), __( 'Next sponsorship', 'wordcamporg' ) ] }
		/>
	);
}

/**
 * The panel beside the list: the sponsorship the address names or, without one, the company it names.
 *
 * A record leads to the ones before and after it only beside its own list.
 *
 * @return The inspector, or nothing while no record is open.
 */
function Inspector() {
	const { tab, companyId, sponsorshipId } = useOpenRecord();

	if ( sponsorshipId ) {
		return (
			<SponsorshipPanel
				key={ sponsorshipId }
				id={ sponsorshipId }
				nav={ tab === 'events' && <SponsorshipNav openId={ sponsorshipId } /> }
			/>
		);
	}

	if ( companyId ) {
		return (
			<CompanyPanel
				key={ companyId }
				id={ companyId }
				nav={ tab === 'companies' && <CompanyNav openId={ companyId } /> }
			/>
		);
	}

	return null;
}

export const inspector = Inspector;
