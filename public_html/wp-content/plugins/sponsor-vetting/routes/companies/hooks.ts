import { useDispatch, useSelect } from '@wordpress/data';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import { useCallback, useMemo } from '@wordpress/element';
import { useNavigate, useSearch } from '@wordpress/route';
import { fields } from './fields';
import { store } from './store';

/*
 * The route tree is assembled at runtime, so the router can't infer this route's search params. These describe the
 * one param the screen keeps there, and the one call it makes.
 */
type Search = { company?: unknown };
type Navigate = ( options: {
	search?: ( previous: Record< string, unknown > ) => Record< string, unknown >;
} ) => void;

/**
 * Reads the company the address names, and opens or closes one by changing the address.
 *
 * @return The open company's ID, if any, and a function that opens a company or, without one, closes the panel.
 */
export function useOpenCompany(): [ number | undefined, ( id?: number ) => void ] {
	const search = useSearch( { strict: false } ) as Search;
	const navigate = useNavigate() as Navigate;
	const id = Number( search.company );

	const open = useCallback(
		( company?: number ) => navigate( { search: ( previous ) => ( { ...previous, company } ) } ),
		[ navigate ]
	);

	return [ Number.isInteger( id ) && id > 0 ? id : undefined, open ];
}

/**
 * Gets the companies as the list shows them: searched, filtered, sorted and paged by the current view.
 *
 * The view lives in the store, so the panel can step through the same rows the list shows.
 *
 * @return The page of companies, its pagination, the view and its setter, and how loading went.
 */
export function useCompanyList() {
	const { companies, view, error } = useSelect(
		( select ) => ( {
			companies: select( store ).getCompanies(),
			view: select( store ).getView(),
			error: select( store ).getResolutionError( 'getCompanies', [] ) as Error | undefined,
		} ),
		[]
	);
	const { setView } = useDispatch( store );
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( companies ?? [], view, fields ),
		[ companies, view ]
	);

	return { data, paginationInfo, view, setView, error, isLoading: ! companies && ! error };
}
