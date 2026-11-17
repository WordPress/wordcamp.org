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
import { LayoutMenu } from './layout-menu';
import { ListHeader, ListTabs } from './list-header';
import './style.scss';
import type { Company, Sponsorship } from './types';
import { COMPANY_LAYOUTS, COMPANY_LAYOUT_CHOICES, SPONSORSHIP_LAYOUTS, asNarrowList } from './view';

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
 * The list of companies. Clicking one opens it beside the list.
 *
 * @return The list.
 */
function Companies() {
	const { data, paginationInfo, fields, view, updateView, resetToDefault, error, isLoading } = useCompanyList();
	const { companyId, sponsorshipId, open } = useOpenRecord();
	const openCompany = ( company?: number ) => open( { company } );
	const actions = useRecordActions< Company >( COMPANY );
	const isNarrow = Boolean( companyId || sponsorshipId );
	const fitted = fitView( view, updateView, COMPANY_LAYOUTS.list.fields, isNarrow );
	const selection = useSelection( fitted.view, companyId, openCompany );

	if ( error ) {
		return <LoadError title={ __( 'The companies couldn’t be loaded.', 'wordcamporg' ) } error={ error } />;
	}

	return (
		<DataViews< Company >
			data={ data }
			fields={ fields }
			actions={ actions }
			{ ...fitted }
			onReset={ resetToDefault ?? false }
			isLoading={ isLoading }
			paginationInfo={ paginationInfo }
			defaultLayouts={ COMPANY_LAYOUTS }
			getItemId={ ( item ) => String( item.id ) }
			onClickItem={ ( item ) => openCompany( item.id ) }
			{ ...selection }
		>
			<ListHeader
				searchLabel={ __( 'Search companies', 'wordcamporg' ) }
				layoutMenu={
					! isNarrow && (
						<LayoutMenu view={ view } onChangeView={ updateView } choices={ COMPANY_LAYOUT_CHOICES } />
					)
				}
			/>
			<DataViews.Layout />
			<DataViews.Footer />
		</DataViews>
	);
}

/**
 * The list of events: every sponsorship, as its sponsor under the event it's for. Clicking one opens its company
 * beside the list.
 *
 * @return The list.
 */
function Events() {
	const { data, paginationInfo, fields, view, updateView, resetToDefault, error, isLoading } =
		useSponsorshipList();
	const { companyId, sponsorshipId, open } = useOpenRecord();
	const openSponsorship = ( sponsorship?: number ) => open( { sponsorship } );
	const actions = useRecordActions< Sponsorship >( SPONSORSHIP );
	const isNarrow = Boolean( companyId || sponsorshipId );
	const fitted = fitView( view, updateView, SPONSORSHIP_LAYOUTS.list.fields, isNarrow );
	const selection = useSelection( fitted.view, sponsorshipId, openSponsorship );

	if ( error ) {
		return <LoadError title={ __( 'The sponsorships couldn’t be loaded.', 'wordcamporg' ) } error={ error } />;
	}

	return (
		<DataViews< Sponsorship >
			data={ data }
			fields={ fields }
			actions={ actions }
			{ ...fitted }
			onReset={ resetToDefault ?? false }
			isLoading={ isLoading }
			paginationInfo={ paginationInfo }
			defaultLayouts={ SPONSORSHIP_LAYOUTS }
			getItemId={ ( item ) => String( item.id ) }
			onClickItem={ ( item ) => openSponsorship( item.id ) }
			{ ...selection }
		>
			<ListHeader
				searchLabel={ __( 'Search events and sponsors', 'wordcamporg' ) }
				layoutMenu={ ! isNarrow && <DataViews.LayoutSwitcher /> }
			/>
			<DataViews.Layout />
			<DataViews.Footer />
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
