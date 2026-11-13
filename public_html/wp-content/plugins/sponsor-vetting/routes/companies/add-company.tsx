import { Modal } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch } from '@wordpress/data';
import { DataForm, useFormValidity } from '@wordpress/dataviews';
import type { Field } from '@wordpress/dataviews';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Stack } from '@wordpress/ui';
import { COMPANY } from './entities';
import { useOpenRecord, useTask } from './hooks';

type NewCompany = { title: string; domains: string };

const FIELDS: Field< NewCompany >[] = [
	{
		id: 'title',
		type: 'text',
		label: __( 'Name', 'wordcamporg' ),
		isValid: { required: true },
	},
	{
		id: 'domains',
		type: 'text',
		label: __( 'Website domains', 'wordcamporg' ),
		description: __( 'One per line. Addresses are reduced to their domain, without www.', 'wordcamporg' ),
		Edit: { control: 'textarea', rows: 3 },
	},
];

const FORM = { fields: [ 'title', 'domains' ] };

/**
 * Asks for a new company's name and websites, creates it, and opens it for the rest.
 *
 * @param props         Props.
 * @param props.onClose Closes the dialog.
 * @return The dialog.
 */
export function AddCompany( { onClose }: { onClose: () => void } ) {
	const { saveEntityRecord } = useDispatch( coreStore );
	const { open } = useOpenRecord();
	const { running, run } = useTask();
	const [ company, setCompany ] = useState< NewCompany >( { title: '', domains: '' } );
	const { validity, isValid } = useFormValidity( company, FIELDS, FORM );
	const onAdd = async () => {
		const isAdded = await run( 'add', async () => {
			const { id } = ( await saveEntityRecord(
				'postType',
				COMPANY,
				{ title: company.title, status: 'sv-needs-vetting', domains: company.domains.split( '\n' ) },
				{ throwOnError: true }
			) ) as { id: number };

			open( { company: id } );

			return __( 'Company added.', 'wordcamporg' );
		} );

		if ( isAdded ) {
			onClose();
		}
	};

	return (
		<Modal title={ __( 'Add company', 'wordcamporg' ) } size="medium" onRequestClose={ onClose }>
			<Stack direction="column" gap="lg">
				<DataForm
					data={ company }
					fields={ FIELDS }
					form={ FORM }
					validity={ validity }
					onChange={ ( edits ) => setCompany( ( current ) => ( { ...current, ...edits } ) ) }
				/>
				<Stack direction="row" justify="end" gap="sm">
					<Button variant="minimal" tone="neutral" onClick={ onClose }>
						{ __( 'Cancel', 'wordcamporg' ) }
					</Button>
					<Button
						disabled={ ! isValid }
						focusableWhenDisabled
						loading={ running === 'add' }
						onClick={ onAdd }
					>
						{ __( 'Add company', 'wordcamporg' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
}
