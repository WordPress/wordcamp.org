import { store as coreStore, useEntityRecords } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { useNavigate, useSearch } from '@wordpress/route';
import { useView } from '@wordpress/views';
import { getToday } from './dates';
import { COMPANIES_QUERY, COMPANY, SPONSORSHIP, SPONSORSHIPS_QUERY, TRASH } from './entities';
import { getCompanyFields, getSponsorshipFields } from './fields';
import type { Company, Sponsorship, Status } from './types';
import { COMPANY_LAYOUTS, COMPANY_VIEW, SPONSORSHIP_LAYOUTS, SPONSORSHIP_VIEW, withoutTrash } from './view';

/*
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
 * The panel shows the sponsorship, if the address names one, and otherwise the company. Naming both opens a
 * sponsorship over its company, which closing the sponsorship goes back to.
 *
 * @return The list shown, the open company and sponsorship, if any, and a function that opens the records it's
 *         given and closes the rest.
 */
export function useOpenRecord() {
	const [ search, update ] = useParams();
	const open = useCallback(
		( { company, sponsorship }: { company?: number; sponsorship?: number } = {} ) =>
			update( { company, sponsorship } ),
		[ update ]
	);

	return {
		tab: search.tab === 'events' ? ( 'events' as const ) : ( 'companies' as const ),
		companyId: toId( search.company ),
		sponsorshipId: toId( search.sponsorship ),
		open,
	};
}

/**
 * Gets the labels of the post statuses, which core lists with their names.
 *
 * @return The labels, keyed by status, and whether they've loaded.
 */
export function useStatusLabels(): { labels: Record< string, string >; hasResolved: boolean } {
	const { records, hasResolved } = useEntityRecords< Status >( 'root', 'status' );
	const labels = useMemo(
		() => ( {
			[ TRASH ]: __( 'Trash', 'wordcamporg' ),
			...Object.fromEntries( ( records ?? [] ).map( ( { slug, name } ) => [ slug, name ] ) ),
		} ),
		[ records ]
	);

	return { labels, hasResolved };
}

/**
 * Gets every record of a post type, through core-data.
 *
 * @param name  The post type.
 * @param query The query. One object per list, since core-data caches by query.
 * @return The records, once they've loaded, and the error if they couldn't be.
 */
function useRecords< Item >( name: string, query: Record< string, unknown > ) {
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
 * Gets the companies as the list shows them: searched, filtered, sorted and paged by the vetter's view.
 *
 * Every company is loaded once, through core-data, and the view is applied here: the list sorts by next event and
 * groups by status, which the endpoint can't do. The view is the vetter's own, kept with their preferences, with
 * its page and search in the address.
 *
 * @return The page of companies, its pagination and fields, the view and how to change it, and how loading went.
 */
export function useCompanyList() {
	const [ search, update ] = useParams();
	const { view, updateView, isModified, resetToDefault } = useView( {
		kind: 'postType',
		name: COMPANY,
		slug: 'default',
		defaultView: COMPANY_VIEW,
		defaultLayouts: COMPANY_LAYOUTS,
		queryParams: search,
		onChangeQueryParams: update,
	} );
	const { labels, hasResolved: hasLabels } = useStatusLabels();
	const { records, error } = useRecords< Company >( COMPANY, COMPANIES_QUERY );
	const fields = useMemo( () => getCompanyFields( labels ), [ labels ] );
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( withoutTrash( records ?? [], view, labels[ TRASH ] ), view, fields ),
		[ records, view, fields, labels ]
	);

	return {
		data,
		paginationInfo,
		fields,
		view,
		updateView,
		resetToDefault: isModified ? resetToDefault : undefined,
		error,
		isLoading: ! error && ! ( records && hasLabels ),
	};
}

/**
 * Gets the sponsorships as the list of events shows them, the way useCompanyList() gets the companies.
 *
 * Each is shown with its company, which comes from the companies the screen has already loaded.
 *
 * @return The page of sponsorships, its pagination and fields, the view and how to change it, how many
 *         sponsorships wait for a company, and how loading went.
 */
export function useSponsorshipList() {
	const [ search, update ] = useParams();
	const { view, updateView, isModified, resetToDefault } = useView( {
		kind: 'postType',
		name: SPONSORSHIP,
		slug: 'default',
		defaultView: SPONSORSHIP_VIEW,
		defaultLayouts: SPONSORSHIP_LAYOUTS,
		queryParams: search,
		onChangeQueryParams: update,
	} );
	const { labels, hasResolved: hasLabels } = useStatusLabels();
	const { records, error } = useRecords< Sponsorship >( SPONSORSHIP, SPONSORSHIPS_QUERY );
	const { records: companies, error: companiesError } = useRecords< Company >( COMPANY, COMPANIES_QUERY );
	const fields = useMemo(
		() => getSponsorshipFields( labels, companies ?? [], records ?? [], getToday() ),
		[ labels, companies, records ]
	);
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( withoutTrash( records ?? [], view, labels[ TRASH ] ), view, fields ),
		[ records, view, fields, labels ]
	);
	const unlinked = useMemo(
		() => ( records ?? [] ).filter( ( { company, status } ) => ! company && status !== TRASH ).length,
		[ records ]
	);

	return {
		data,
		paginationInfo,
		fields,
		view,
		updateView,
		resetToDefault: isModified ? resetToDefault : undefined,
		unlinked,
		error: error ?? companiesError,
		isLoading: ! ( error ?? companiesError ) && ! ( records && companies && hasLabels ),
	};
}

/**
 * Words an error for the vetter.
 *
 * @param error What a request threw.
 * @return The server's message, or a general one.
 */
export function getErrorMessage( error: unknown ): string {
	return error instanceof Object && 'message' in error && typeof error.message === 'string' && error.message
		? error.message
		: __( 'Something went wrong. Try again.', 'wordcamporg' );
}

/**
 * Runs the changes a vetter makes: one at a time, with what came of each shown as a notice.
 *
 * A change to a company can change its sponsorships and the other way around, which core-data can't know, so every
 * record is read again afterwards.
 *
 * @return The name of the change that's running, if any, and a function that runs one. It takes the change's name
 *         and the work, which resolves to the message to show, and resolves to whether the work succeeded.
 */
export function useTask() {
	const { invalidateResolutionForStoreSelector } = useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );
	const [ running, setRunning ] = useState< string >();
	const run = useCallback(
		async ( name: string, work: () => Promise< string | undefined > ): Promise< boolean > => {
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
