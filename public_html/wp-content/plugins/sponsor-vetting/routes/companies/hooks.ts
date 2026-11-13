import { store as coreStore, useEntityRecords } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { useCallback, useMemo } from '@wordpress/element';
import { useNavigate, useSearch } from '@wordpress/route';
import { useView } from '@wordpress/views';
import { COMPANIES_QUERY, COMPANY } from './entities';
import { getCompanyFields } from './fields';
import type { Company, Status } from './types';
import { DEFAULT_LAYOUTS, DEFAULT_VIEW } from './view';

/*
 * The route tree is assembled at runtime, so the router can't infer this route's search params. These describe the
 * params the screen keeps there, and the one call it makes.
 */
type Search = { company?: unknown; page?: number; search?: string };
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
 * Reads the company the address names, and opens or closes one by changing the address.
 *
 * @return The open company's ID, if any, and a function that opens a company or, without one, closes the panel.
 */
export function useOpenCompany(): [ number | undefined, ( id?: number ) => void ] {
	const [ search, update ] = useParams();
	const id = Number( search.company );
	const open = useCallback( ( company?: number ) => update( { company } ), [ update ] );

	return [ Number.isInteger( id ) && id > 0 ? id : undefined, open ];
}

/**
 * Gets the labels of the post statuses, which core lists with their names.
 *
 * @return The labels, keyed by status, and whether they've loaded.
 */
export function useStatusLabels(): { labels: Record< string, string >; hasResolved: boolean } {
	const { records, hasResolved } = useEntityRecords< Status >( 'root', 'status' );
	const labels = useMemo(
		() => Object.fromEntries( ( records ?? [] ).map( ( { slug, name } ) => [ slug, name ] ) ),
		[ records ]
	);

	return { labels, hasResolved };
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
		defaultView: DEFAULT_VIEW,
		defaultLayouts: DEFAULT_LAYOUTS,
		queryParams: search,
		onChangeQueryParams: update,
	} );
	const { labels, hasResolved: hasLabels } = useStatusLabels();
	const { records, hasResolved } = useEntityRecords< Company >( 'postType', COMPANY, COMPANIES_QUERY );
	const error = useSelect(
		( select ) =>
			select( coreStore ).getResolutionError( 'getEntityRecords', [
				'postType',
				COMPANY,
				COMPANIES_QUERY,
			] ) as Error | undefined,
		[]
	);
	const fields = useMemo( () => getCompanyFields( labels ), [ labels ] );
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( records ?? [], view, fields ),
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
		isLoading: ! error && ! ( hasResolved && hasLabels ),
	};
}
