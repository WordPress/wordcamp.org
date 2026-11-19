/**
 * The screen's hooks: what the address names, the records, the two lists, and running a change.
 */

import { store as coreStore, useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Field, View } from '@wordpress/dataviews';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { useNavigate, useSearch } from '@wordpress/route';
import { useView } from '@wordpress/views';
import { getToday } from './dates';
import { COMPANIES_QUERY, COMPANY, SPONSORSHIP, SPONSORSHIPS_QUERY, TRASH } from './entities';
import { getCompanyFields, getSponsorshipFields } from './fields';
import type { Company, PostType, Sponsorship, Statuses } from './types';
import {
	COMPANY_LAYOUTS,
	COMPANY_VIEW,
	SPONSORSHIP_LAYOUTS,
	SPONSORSHIP_VIEW,
	withoutHiddenFilters,
	withoutTrash,
} from './view';
import type { Layouts } from './view';

/**
 * The route tree is assembled at runtime, so the router can't infer this route's search params. These describe the
 * params the screen keeps there, and the one call it makes.
 */
type Search = { tab?: unknown; company?: unknown; sponsorship?: unknown; page?: number; search?: string };
type Navigate = ( options: {
	search?: ( previous: Record< string, unknown > ) => Record< string, unknown >;
} ) => void;

/**
 * Reads the screen's params from the address, and changes them.
 *
 * @return The params, and a function that merges new ones in. An undefined param is removed.
 */
function useParams(): [ Search, ( params: Search ) => void ] {
	const search = useSearch( { strict: false } ) as Search;
	const navigate = useNavigate() as Navigate;
	const update = useCallback(
		( params: Search ) => navigate( { search: ( previous ) => ( { ...previous, ...params } ) } ),
		[ navigate ]
	);

	return [ search, update ];
}

/**
 * Reads an ID from a param of the address.
 *
 * @param param The param.
 * @return The ID, if it is one.
 */
function toId( param: unknown ): number | undefined {
	const id = Number( param );

	return Number.isInteger( id ) && id > 0 ? id : undefined;
}

/**
 * Reads what the address has open, and changes what's open by changing the address.
 *
 * A sponsorship opens its company, with its event marked, or what links it to one. The events are the list the
 * screen opens on, so the address names the list only for the companies.
 *
 * @return The list shown, the open company and sponsorship, if any, a function that opens the record it's given
 *         and closes the rest, and one that shows the other list.
 */
export function useOpenRecord() {
	const [ search, update ] = useParams();
	const navigate = useNavigate() as Navigate;
	const open = useCallback(
		( { company, sponsorship }: { company?: number; sponsorship?: number } = {} ) =>
			update( { company, sponsorship } ),
		[ update ]
	);
	// The other list starts from a clean address: nothing open, and none of this list's params.
	const openTab = useCallback(
		( tab: 'companies' | 'events' ) => navigate( { search: () => ( tab === 'companies' ? { tab } : {} ) } ),
		[ navigate ]
	);

	return {
		tab: search.tab === 'companies' ? ( 'companies' as const ) : ( 'events' as const ),
		companyId: toId( search.company ),
		sponsorshipId: toId( search.sponsorship ),
		open,
		openTab,
	};
}

/**
 * Gets the statuses of companies and of sponsorships, which REST lists with each post type.
 *
 * @return The statuses of each, in their own order, every status's label, keyed by status, and whether they've
 *         loaded.
 */
export function useStatuses(): Statuses & { hasResolved: boolean } {
	const company = useEntityRecord< PostType >( 'root', 'postType', COMPANY );
	const sponsorship = useEntityRecord< PostType >( 'root', 'postType', SPONSORSHIP );

	return useMemo( () => {
		const statuses = {
			company: company.record?.statuses ?? [],
			sponsorship: sponsorship.record?.statuses ?? [],
		};

		return {
			...statuses,
			labels: {
				[ TRASH ]: __( 'Trash', 'wordcamporg' ),
				...Object.fromEntries(
					[ ...statuses.company, ...statuses.sponsorship ].map( ( { slug, name } ) => [ slug, name ] )
				),
			},
			hasResolved: company.hasResolved && sponsorship.hasResolved,
		};
	}, [ company.record, company.hasResolved, sponsorship.record, sponsorship.hasResolved ] );
}

/**
 * Gets every record of a post type, through core-data.
 *
 * @param name  The post type.
 * @param query The query. One object per list, since core-data caches by query.
 * @return The records, once they've loaded, and the error if they couldn't be.
 */
export function useRecords< Item >( name: string, query: Record< string, unknown > ) {
	const { records } = useEntityRecords< Item >( 'postType', name, query );
	const error = useSelect(
		( select ) =>
			select( coreStore ).getResolutionError( 'getEntityRecords', [ 'postType', name, query ] ) as
				| Error
				| undefined,
		[ name, query ]
	);

	return { records, error };
}

/**
 * A list as its DataViews shows it: the page of records, its pagination and fields, the view and how to change it,
 * and how loading went.
 */
export type RecordList< Item > = {
	data: Item[];
	paginationInfo: { totalItems: number; totalPages: number };
	fields: Field< Item >[];
	view: View;
	updateView: ( view: View ) => void;
	resetToDefault?: () => void;
	error?: unknown;
	isLoading: boolean;
};

/**
 * Shows records as a list does: searched, filtered, sorted and paged by the vetter's view.
 *
 * Every record is loaded once, through core-data, and the view is applied here: the lists sort and group in ways
 * the endpoint can't. The view is the vetter's own, kept with their preferences, with its page and search in the
 * address.
 *
 * @param list           The list.
 * @param list.name      Its post type.
 * @param list.view      How it looks until a vetter changes it.
 * @param list.layouts   What each of its layouts shows.
 * @param list.records   Its records, once they've loaded.
 * @param list.fields    Its fields.
 * @param list.error     What loading it threw, if it failed.
 * @param list.hasLoaded Whether everything it shows has loaded.
 * @return The list.
 */
function useList< Item extends { status: string } >( {
	name,
	view: defaultView,
	layouts: defaultLayouts,
	records,
	fields,
	error,
	hasLoaded,
}: {
	name: string;
	view: View;
	layouts: Layouts;
	records: Item[] | null;
	fields: Field< Item >[];
	error: unknown;
	hasLoaded: boolean;
} ): RecordList< Item > {
	const [ search, update ] = useParams();
	const { view, updateView, isModified, resetToDefault } = useView( {
		kind: 'postType',
		name,
		slug: 'default',
		defaultView,
		defaultLayouts,
		queryParams: search,
		onChangeQueryParams: update,
	} );
	const { data, paginationInfo } = useMemo(
		() =>
			filterSortAndPaginate(
				withoutTrash( records ?? [], view ),
				withoutHiddenFilters( view, fields ),
				fields
			),
		[ records, view, fields ]
	);

	return {
		data,
		paginationInfo,
		fields,
		view,
		updateView,
		resetToDefault: isModified ? resetToDefault : undefined,
		error,
		isLoading: ! error && ! hasLoaded,
	};
}

/**
 * Gets the companies as their list shows them.
 *
 * @return The list.
 */
export function useCompanyList(): RecordList< Company > {
	const statuses = useStatuses();
	const { records, error } = useRecords< Company >( COMPANY, COMPANIES_QUERY );
	const fields = useMemo( () => getCompanyFields( statuses ), [ statuses ] );

	return useList( {
		name: COMPANY,
		view: COMPANY_VIEW,
		layouts: COMPANY_LAYOUTS,
		records,
		fields,
		error,
		hasLoaded: Boolean( records && statuses.hasResolved ),
	} );
}

/**
 * Gets the sponsorships as the list of events shows them: each with what it shows of its company, so the list
 * doesn't wait for the companies.
 *
 * @return The list.
 */
export function useSponsorshipList(): RecordList< Sponsorship > {
	const statuses = useStatuses();
	const { records, error } = useRecords< Sponsorship >( SPONSORSHIP, SPONSORSHIPS_QUERY );
	const today = getToday();
	const fields = useMemo(
		() => getSponsorshipFields( statuses, records ?? [], today ),
		[ statuses, records, today ]
	);

	return useList( {
		name: SPONSORSHIP,
		view: SPONSORSHIP_VIEW,
		layouts: SPONSORSHIP_LAYOUTS,
		records,
		fields,
		error,
		hasLoaded: Boolean( records && statuses.hasResolved ),
	} );
}

/**
 * Words an error for the vetter.
 *
 * A value the server refuses comes as "Invalid parameter(s)" and the field's name, with what's wrong with it
 * beside that: so that's what is shown.
 *
 * @param error What a request threw.
 * @return The server's message, or a general one.
 */
export function getErrorMessage( error: unknown ): string {
	const { message, data } = ( error instanceof Object ? error : {} ) as {
		message?: unknown;
		data?: { params?: Record< string, unknown > };
	};
	const reasons = Object.values( data?.params ?? {} ).filter(
		( reason ): reason is string => typeof reason === 'string' && reason !== ''
	);

	if ( reasons.length ) {
		return reasons.join( ' ' );
	}

	return typeof message === 'string' && message
		? message
		: __( 'Something went wrong. Try again.', 'wordcamporg' );
}

/**
 * Runs one change: takes its name and the work, which resolves to the message to show, and resolves to whether the
 * work succeeded.
 */
export type Run = ( name: string, work: () => Promise< string | undefined > ) => Promise< boolean >;

/**
 * Runs the changes a vetter makes: one at a time, with what came of each shown as a notice.
 *
 * A change to a company can change its sponsorships and the other way around, which core-data can't know, so every
 * record is read again afterwards.
 *
 * @return The name of the change that's running, if any, and the function that runs one.
 */
export function useTask(): { running: string | undefined; run: Run } {
	const { invalidateResolutionForStoreSelector } = useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );
	const [ running, setRunning ] = useState< string >();
	const run = useCallback< Run >(
		async ( name, work ) => {
			setRunning( name );

			try {
				const message = await work();

				if ( message ) {
					createSuccessNotice( message, { type: 'snackbar' } );
				}

				return true;
			} catch ( error ) {
				createErrorNotice( getErrorMessage( error ), { type: 'snackbar' } );

				return false;
			} finally {
				invalidateResolutionForStoreSelector( 'getEntityRecords' );
				invalidateResolutionForStoreSelector( 'getEntityRecord' );
				setRunning( undefined );
			}
		},
		[ createErrorNotice, createSuccessNotice, invalidateResolutionForStoreSelector ]
	);

	return { running, run };
}
