/**
 * The route's stage: the lists of events and of companies.
 */

import { Page } from '@wordpress/admin-ui';
import { DataViews } from '@wordpress/dataviews';
import type { View } from '@wordpress/dataviews';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { useRecordActions } from './actions';
import { AddCompany } from './add-company';
import { COMPANY, SPONSORSHIP } from './entities';
import { getErrorMessage, useCompanyList, useOpenRecord, useSponsorshipList } from './hooks';
import type { RecordList } from './hooks';
import { LayoutMenu } from './layout-menu';
import { ListHeader, ListTabs } from './list-header';
import './style.scss';
import type { Company, Sponsorship, Trashable } from './types';
import { COMPANY_LAYOUTS, COMPANY_LAYOUT_CHOICES, SPONSORSHIP_LAYOUTS, asNarrowList } from './view';
import type { Layouts } from './view';

/**
 * Says that a list couldn't be loaded, under the tabs, so the other list can still be reached.
 *
 * @param props       Props.
 * @param props.title What couldn't be.
 * @param props.error What loading it threw.
 * @return The notice.
 */
function LoadError( { title, error }: { title: string; error: unknown } ) {
	return (
		<>
			<div className="sponsor-vetting__list-header">
				<ListTabs />
			</div>
			<Notice.Root intent="error" className="sponsor-vetting__notice">
				<Notice.Title>{ title }</Notice.Title>
				<Notice.Description>{ getErrorMessage( error ) }</Notice.Description>
			</Notice.Root>
		</>
	);
}

/**
 * Works out which rows a list marks as selected, and what selecting rows does.
 *
 * The list layout opens a record by selecting its row. The table has checkboxes, which pick the rows its actions
 * apply to.
 *
 * @param view   The list's view.
 * @param openId The open record, if any.
 * @param open   Opens a record or, without an ID, closes it.
 * @return The selection, and the function that changes it.
 */
function useSelection( view: View, openId: number | undefined, open: ( id?: number ) => void ) {
	const [ picked, setPicked ] = useState< string[] >( [] );

	if ( view.type === 'list' ) {
		return {
			selection: openId ? [ String( openId ) ] : [],
			onChangeSelection: ( [ id ]: string[] ) => open( id ? Number( id ) : undefined ),
		};
	}

	return { selection: picked, onChangeSelection: setPicked };
}

/**
 * Fits a list beside an open record: as a narrow list, whatever layout the vetter chose, which comes back when the
 * record closes.
 *
 * @param view       The vetter's view.
 * @param updateView Changes it.
 * @param fields     What a row of the narrow list shows.
 * @param isNarrow   Whether a record is open.
 * @return The view to show, and the function that changes it.
 */
function fitView( view: View, updateView: ( view: View ) => void, fields: string[], isNarrow: boolean ) {
	if ( ! isNarrow ) {
		return { view, onChangeView: updateView };
	}

	return {
		view: asNarrowList( view, fields ),
		// The narrow list is only searched, filtered and paged, so the layout stays the vetter's.
		onChangeView: ( { search, filters, page }: View ) => updateView( { ...view, search, filters, page } ),
	};
}

/**
 * Works out what a list hands its DataViews. Clicking a record opens it beside the list.
 *
 * @param name    The list's post type.
 * @param list    The list.
 * @param layouts What each of its layouts shows.
 * @param openId  The record of the list that's open, if any.
 * @param onOpen  Opens a record or, without an ID, closes it.
 * @return The props, and whether the list is the narrow one beside an open record.
 */
function useListProps< Item extends Trashable >(
	name: string,
	list: RecordList< Item >,
	layouts: Layouts,
	openId: number | undefined,
	onOpen: ( id?: number ) => void
) {
	const { data, paginationInfo, fields, view, updateView, resetToDefault, isLoading } = list;
	const { companyId, sponsorshipId } = useOpenRecord();
	const actions = useRecordActions< Item >( name );
	const isNarrow = Boolean( companyId || sponsorshipId );
	const fitted = fitView( view, updateView, layouts.list.fields, isNarrow );
	const selection = useSelection( fitted.view, openId, onOpen );

	return {
		isNarrow,
		props: {
			data,
			fields,
			actions,
			...fitted,
			onReset: resetToDefault ?? ( false as const ),
			isLoading,
			paginationInfo,
			defaultLayouts: layouts,
			getItemId: ( item: Item ) => String( item.id ),
			onClickItem: ( item: Item ) => onOpen( item.id ),
			...selection,
		},
	};
}

/**
 * What's inside a list's DataViews: its head, its records and its footer.
 *
 * @param props             Props.
 * @param props.searchLabel What the search field is for.
 * @param props.layoutMenu  The list's Layout menu, which the narrow list has no room for.
 * @return The contents.
 */
function ListContents( { searchLabel, layoutMenu }: { searchLabel: string; layoutMenu: ReactNode } ) {
	return (
		<>
			<ListHeader searchLabel={ searchLabel } layoutMenu={ layoutMenu } />
			<DataViews.Layout />
			<DataViews.Footer />
		</>
	);
}

/**
 * The list of companies.
 *
 * @return The list.
 */
function Companies() {
	const list = useCompanyList();
	const { companyId, open } = useOpenRecord();
	const { props, isNarrow } = useListProps( COMPANY, list, COMPANY_LAYOUTS, companyId, ( company ) =>
		open( { company } )
	);

	if ( list.error ) {
		return (
			<LoadError title={ __( 'The companies couldn’t be loaded.', 'wordcamporg' ) } error={ list.error } />
		);
	}

	return (
		<DataViews< Company > { ...props }>
			<ListContents
				searchLabel={ __( 'Search companies', 'wordcamporg' ) }
				layoutMenu={
					! isNarrow && (
						<LayoutMenu
							view={ list.view }
							onChangeView={ list.updateView }
							choices={ COMPANY_LAYOUT_CHOICES }
						/>
					)
				}
			/>
		</DataViews>
	);
}

/**
 * The list of events: every sponsorship, as its sponsor under the event it's for. Clicking one opens its company.
 *
 * @return The list.
 */
function Events() {
	const list = useSponsorshipList();
	const { sponsorshipId, open } = useOpenRecord();
	const { props, isNarrow } = useListProps(
		SPONSORSHIP,
		list,
		SPONSORSHIP_LAYOUTS,
		sponsorshipId,
		( sponsorship ) => open( { sponsorship } )
	);

	if ( list.error ) {
		return (
			<LoadError
				title={ __( 'The sponsorships couldn’t be loaded.', 'wordcamporg' ) }
				error={ list.error }
			/>
		);
	}

	return (
		<DataViews< Sponsorship > { ...props }>
			<ListContents
				searchLabel={ __( 'Search events and sponsors', 'wordcamporg' ) }
				layoutMenu={ ! isNarrow && <DataViews.LayoutSwitcher /> }
			/>
		</DataViews>
	);
}

/**
 * The screen: the sponsors of each event or, on its other tab, the companies.
 *
 * @return The stage.
 */
function Stage() {
	const { tab } = useOpenRecord();
	const [ isAdding, setIsAdding ] = useState( false );
	const lists: Record< typeof tab, ReactNode > = { events: <Events />, companies: <Companies /> };

	return (
		<Page
			title={ __( 'Sponsor Vetting', 'wordcamporg' ) }
			actions={
				<Button size="compact" onClick={ () => setIsAdding( true ) }>
					{ __( 'Add company', 'wordcamporg' ) }
				</Button>
			}
			hasPadding={ false }
		>
			{ lists[ tab ] }
			{ isAdding && <AddCompany onClose={ () => setIsAdding( false ) } /> }
		</Page>
	);
}

/**
 * The route's stage, under the name its surface is looked up by.
 */
export const stage = Stage;
