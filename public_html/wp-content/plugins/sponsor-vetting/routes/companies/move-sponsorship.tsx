import { Modal } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Stack } from '@wordpress/ui';
import { COMPANIES_QUERY, COMPANY, TRASH } from './entities';
import { COMPANY_PICK_FORM, getCompanyPickField } from './forms';
import type { CompanyPick } from './forms';
import { useRecords, useTask } from './hooks';
import { useSponsorshipChanges } from './sponsorships';
import type { Company, CompanySponsorship } from './types';

/**
 * Asks which company a sponsorship belongs to instead, and moves it there.
 *
 * @param props             Props.
 * @param props.sponsorship The sponsorship.
 * @param props.companyId   The company it's linked to now.
 * @param props.onClose     Closes the dialog.
 * @return The dialog.
 */
export function MoveSponsorship( {
	sponsorship,
	companyId,
	onClose,
}: {
	sponsorship: CompanySponsorship;
	companyId: number;
	onClose: () => void;
} ) {
	const { records } = useRecords< Company >( COMPANY, COMPANIES_QUERY );
	const { running, run } = useTask();
	const { link } = useSponsorshipChanges( run );
	const [ pick, setPick ] = useState< CompanyPick >( { company: 0 } );
	const fields = useMemo(
		() => [
			getCompanyPickField(
				( records ?? [] ).filter( ( { id, status } ) => id !== companyId && status !== TRASH ),
				__( 'Company', 'wordcamporg' )
			),
		],
		[ records, companyId ]
	);
	const onMove = async () => {
		if ( await link( sponsorship, pick.company ) ) {
			onClose();
		}
	};

	return (
		<Modal
			/* translators: %s: Event name. */
			title={ sprintf( __( 'Move %s to another company', 'wordcamporg' ), sponsorship.event.name ) }
			size="medium"
			onRequestClose={ onClose }
		>
			<Stack direction="column" gap="lg">
				<DataForm
					data={ pick }
					fields={ fields }
					form={ COMPANY_PICK_FORM }
					onChange={ ( edits ) => setPick( ( current ) => ( { ...current, ...edits } ) ) }
				/>
				<Stack direction="row" justify="end" gap="sm">
					<Button variant="minimal" tone="neutral" onClick={ onClose }>
						{ __( 'Cancel', 'wordcamporg' ) }
					</Button>
					<Button
						disabled={ ! pick.company }
						focusableWhenDisabled
						loading={ running === `link-${ sponsorship.id }` }
						onClick={ onMove }
					>
						{ __( 'Move', 'wordcamporg' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
}
