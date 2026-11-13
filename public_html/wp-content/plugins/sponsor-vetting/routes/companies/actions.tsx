import { store as coreStore } from '@wordpress/core-data';
import { useDispatch } from '@wordpress/data';
import type { Action } from '@wordpress/dataviews';
import { useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Stack, Text } from '@wordpress/ui';
import { COMPANY, TRASH } from './entities';
import { getName } from './fields';
import { useOpenRecord, useTask } from './hooks';

/* What a list needs of a record to trash it, restore it, or delete it. */
type Listed = {
	id: number;
	title: { raw: string };
	status: string;
	trashed_status: string;
};

/**
 * Names the records an action is about: the one record, or how many there are.
 *
 * @param items The records.
 * @return The name, for the start of a sentence.
 */
function describe( items: Listed[] ): string {
	if ( items.length === 1 ) {
		return `“${ getName( items[ 0 ] ) }”`;
	}

	/* translators: %d: Number of companies or sponsorships. */
	return sprintf( _n( '%d record', '%d records', items.length, 'wordcamporg' ), items.length );
}

/**
 * Asks before records are deleted for good, then deletes them.
 *
 * @param props            Props.
 * @param props.name       The records' post type.
 * @param props.items      The records.
 * @param props.closeModal Closes the question.
 * @return The question.
 */
function DeleteModal( { name, items, closeModal }: { name: string; items: Listed[]; closeModal?: () => void } ) {
	const { deleteEntityRecord } = useDispatch( coreStore );
	const { companyId, sponsorshipId, open } = useOpenRecord();
	const { running, run } = useTask();
	const onDelete = async () => {
		await run( 'delete', async () => {
			await Promise.all(
				items.map( ( { id } ) =>
					deleteEntityRecord( 'postType', name, id, { force: true }, { throwOnError: true } )
				)
			);

			// A record that's gone can't stay open.
			if ( items.some( ( { id } ) => id === ( name === COMPANY ? companyId : sponsorshipId ) ) ) {
				open();
			}

			/* translators: %s: A record's name in quotes, or a number of records. */
			return sprintf( __( '%s deleted permanently.', 'wordcamporg' ), describe( items ) );
		} );
		closeModal?.();
	};

	return (
		<Stack direction="column" gap="lg">
			<Text>
				{ sprintf(
					/* translators: %s: A record's name in quotes, or a number of records. */
					__(
						'Delete %s permanently? Notes and history are deleted too. This can’t be undone.',
						'wordcamporg'
					),
					describe( items )
				) }
			</Text>
			<Stack direction="row" justify="end" gap="sm">
				<Button variant="minimal" tone="neutral" onClick={ closeModal }>
					{ __( 'Cancel', 'wordcamporg' ) }
				</Button>
				<Button loading={ running === 'delete' } onClick={ onDelete }>
					{ __( 'Delete permanently', 'wordcamporg' ) }
				</Button>
			</Stack>
		</Stack>
	);
}

/**
 * Builds the actions of a list's rows: to the Trash, back out of it, and gone for good.
 *
 * @param name The list's post type.
 * @return The actions.
 */
export function useRecordActions< Item extends Listed >( name: string ): Action< Item >[] {
	const { deleteEntityRecord, saveEntityRecord } = useDispatch( coreStore );
	const { run } = useTask();

	return useMemo(
		() => [
			{
				id: 'trash',
				label: __( 'Move to Trash', 'wordcamporg' ),
				supportsBulk: true,
				isEligible: ( { status } ) => status !== TRASH,
				callback: ( items ) =>
					void run( 'trash', async () => {
						await Promise.all(
							items.map( ( { id } ) =>
								deleteEntityRecord( 'postType', name, id, {}, { throwOnError: true } )
							)
						);

						/* translators: %s: A record's name in quotes, or a number of records. */
						return sprintf( __( '%s moved to the Trash.', 'wordcamporg' ), describe( items ) );
					} ),
			},
			{
				id: 'restore',
				label: __( 'Restore', 'wordcamporg' ),
				supportsBulk: true,
				isEligible: ( { status } ) => status === TRASH,
				callback: ( items ) =>
					void run( 'restore', async () => {
						// Written back to the status it had, which is how a record leaves the Trash.
						await Promise.all(
							items.map( ( { id, trashed_status: status } ) =>
								saveEntityRecord( 'postType', name, { id, status }, { throwOnError: true } )
							)
						);

						/* translators: %s: A record's name in quotes, or a number of records. */
						return sprintf( __( '%s restored.', 'wordcamporg' ), describe( items ) );
					} ),
			},
			{
				id: 'delete',
				label: __( 'Delete permanently', 'wordcamporg' ),
				supportsBulk: true,
				isEligible: ( { status } ) => status === TRASH,
				modalHeader: __( 'Delete permanently', 'wordcamporg' ),
				RenderModal: ( { items, closeModal } ) => (
					<DeleteModal name={ name } items={ items } closeModal={ closeModal } />
				),
			},
		],
		[ deleteEntityRecord, name, run, saveEntityRecord ]
	);
}
