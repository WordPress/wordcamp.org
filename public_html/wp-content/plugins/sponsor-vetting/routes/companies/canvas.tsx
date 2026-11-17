import { __ } from '@wordpress/i18n';
import { CanvasPlaceholder } from './canvas-parts';
import { CompanyCanvas } from './company-canvas';
import { SPONSORSHIP, SPONSORSHIPS_QUERY, TRASH } from './entities';
import { useOpenRecord, useRecords } from './hooks';
import { SponsorshipCanvas } from './sponsorship-canvas';
import type { Sponsorship } from './types';

/**
 * The sponsorship the address names: as one of its company's events or, before it has a company, on its own.
 *
 * Which of the two comes from the list of events, which the screen has loaded or is loading.
 *
 * @param props    Props.
 * @param props.id The sponsorship.
 * @return The company or the sponsorship, or how finding out is going.
 */
function OpenSponsorship( { id }: { id: number } ) {
	const { open } = useOpenRecord();
	const { records, error } = useRecords< Sponsorship >( SPONSORSHIP, SPONSORSHIPS_QUERY );
	const sponsorship = records?.find( ( candidate ) => candidate.id === id );

	if ( ! sponsorship ) {
		return (
			<CanvasPlaceholder
				error={ error ?? ( records && new Error( __( 'It may have been deleted.', 'wordcamporg' ) ) ) }
				title={ __( 'The sponsorship couldn’t be loaded.', 'wordcamporg' ) }
				onClose={ () => open() }
			/>
		);
	}

	if ( sponsorship.company && sponsorship.status !== TRASH ) {
		return <CompanyCanvas key={ sponsorship.company } id={ sponsorship.company } sponsorshipId={ id } />;
	}

	return <SponsorshipCanvas key={ id } id={ id } />;
}

/**
 * The open record, beside the list: the company the address names, or the one whose event it names.
 *
 * @return The canvas, or nothing while no record is open.
 */
function Canvas() {
	const { companyId, sponsorshipId } = useOpenRecord();

	if ( ! companyId && ! sponsorshipId ) {
		return null;
	}

	return (
		<div className="sponsor-vetting__canvas">
			{ sponsorshipId ? (
				<OpenSponsorship id={ sponsorshipId } />
			) : (
				<CompanyCanvas key={ companyId } id={ companyId as number } />
			) }
		</div>
	);
}

export const canvas = Canvas;
