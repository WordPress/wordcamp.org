import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';
import type { View } from '@wordpress/dataviews';
import { DEFAULT_VIEW } from './fields';
import type { Company, CompanyDetails } from './types';

type State = {
	companies: Company[] | null;
	details: Record< number, CompanyDetails >;
	view: View;
};

type Action =
	| { type: 'RECEIVE_COMPANIES'; companies: Company[] }
	| { type: 'RECEIVE_COMPANY'; company: CompanyDetails }
	| { type: 'SET_VIEW'; view: View };

type Thunk = ( args: { dispatch: typeof actions } ) => Promise< void >;

const DEFAULT_STATE: State = {
	companies: null,
	details: {},
	view: DEFAULT_VIEW,
};

const actions = {
	receiveCompanies: ( companies: Company[] ): Action => ( { type: 'RECEIVE_COMPANIES', companies } ),
	receiveCompany: ( company: CompanyDetails ): Action => ( { type: 'RECEIVE_COMPANY', company } ),
	setView: ( view: View ): Action => ( { type: 'SET_VIEW', view } ),
};

/* A resolver that throws marks its selector as failed, which the screens read with getResolutionError(). */
const resolvers = {
	getCompanies:
		(): Thunk =>
		async ( { dispatch } ) => {
			dispatch.receiveCompanies( await apiFetch< Company[] >( { path: '/sponsor-vetting/v1/companies' } ) );
		},
	getCompany:
		( id: number ): Thunk =>
		async ( { dispatch } ) => {
			dispatch.receiveCompany(
				await apiFetch< CompanyDetails >( { path: `/sponsor-vetting/v1/companies/${ id }` } )
			);
		},
};

export const store = createReduxStore( 'wordcamp/sponsor-vetting', {
	reducer( state: State = DEFAULT_STATE, action: Action ): State {
		switch ( action.type ) {
			case 'RECEIVE_COMPANIES':
				return { ...state, companies: action.companies };
			case 'RECEIVE_COMPANY':
				return { ...state, details: { ...state.details, [ action.company.id ]: action.company } };
			case 'SET_VIEW':
				return { ...state, view: action.view };
			default:
				return state;
		}
	},
	actions,
	selectors: {
		getCompanies: ( state: State ): Company[] | null => state.companies,
		getCompany: ( state: State, id: number ): CompanyDetails | undefined => state.details[ id ],
		getView: ( state: State ): View => state.view,
	},
	resolvers,
} );

register( store );
