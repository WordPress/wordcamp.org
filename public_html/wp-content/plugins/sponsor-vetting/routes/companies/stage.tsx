import { Page } from '@wordpress/admin-ui';
import { DataViews } from '@wordpress/dataviews';
import type { View } from '@wordpress/dataviews';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Link } from '@wordpress/route';
import { Button, Notice, SwitchControl } from '@wordpress/ui';
import type { AnchorHTMLAttributes, ComponentType, ReactNode } from 'react';
import { useRecordActions } from './actions';
import { AddCompany } from './add-company';
import { COMPANY, SPONSORSHIP } from './entities';
import { getErrorMessage, useCompanyList, useOpenRecord, useSponsorshipList } from './hooks';
import './style.scss';
import type { Company, Sponsorship } from './types';
import {
	COMPANY_LAYOUTS,
	GROUP_BY_EVENT,
	GROUP_BY_STATUS,
	SPONSORSHIP_LAYOUTS,
	needsCompanyOnly,
	toggleNeedsCompany,
} from './view';

/* The two lists, by the addresses the page header knows them by. The router gives each its real address. */
const COMPANIES = '#companies';
const EVENTS = '#events';

type AnchorProps = AnchorHTMLAttributes< HTMLAnchorElement >;

/* The route tree is assembled at runtime, so the router's link can't check where it leads. */
const RouterLink = Link as unknown as ComponentType<
	AnchorProps & { to: string; search: Record< string, string >; activeOptions: { exact: boolean } }
>;

/**
 * A link of the page header that changes the list without loading the page, and closes whatever is open.
 *
 * @param props      Props.
 * @param props.href Which list it leads to.
 * @return The link.
 */
function TabLink( { href, ...props }: AnchorProps ) {
	// Both lists are the same route, so the router would mark both links as the current page.
	return (
		<RouterLink
			{ ...props }
			to="/"
			search={ href === EVENTS ? { tab: 'events' } : {} }
			activeOptions={ { exact: true } }
		/>
	);
}

/**
 * Says that a list couldn't be loaded.
 *
 * @param props       Props.
 * @param props.title What couldn't be.
 * @param props.error What loading it threw.
 * @return The notice.
 */
function LoadError( { title, error }: { title: string; error: unknown } ) {
	return (
		<Notice.Root intent="error" className="sponsor-vetting__notice">
			<Notice.Title>{ title }</Notice.Title>
			<Notice.Description>{ getErrorMessage( error ) }</Notice.Description>
		</Notice.Root>
	);
}

/**
 * A switch beside a list's search. DataViews groups and filters rows, but has no control this plain for either.
 *
 * @param props          Props.
 * @param props.label    What it turns on.
 * @param props.checked  Whether it's on.
 * @param props.onChange Turns it on or off.
 * @return The switch.
 */
function ViewSwitch( {
	label,
	checked,
	onChange,
}: {
	label: string;
	checked: boolean;
	onChange: ( isOn: boolean ) => void;
} ) {
	return (
		<div className="sponsor-vetting__view-switch">
			<SwitchControl label={ label } checked={ checked } onCheckedChange={ onChange } />
		</div>
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
 * The list of companies. Clicking one opens it in the panel beside the list.
 *
 * @return The list.
 */
function Companies() {
	const { data, paginationInfo, fields, view, updateView, resetToDefault, error, isLoading } = useCompanyList();
	const { companyId, open } = useOpenRecord();
	const openCompany = ( company?: number ) => open( { company } );
	const actions = useRecordActions< Company >( COMPANY );
	const selection = useSelection( view, companyId, openCompany );

	if ( error ) {
		return <LoadError title={ __( 'The companies couldn’t be loaded.', 'wordcamporg' ) } error={ error } />;
	}

	return (
		<DataViews< Company >
			data={ data }
			fields={ fields }
			actions={ actions }
			view={ view }
			onChangeView={ updateView }
			onReset={ resetToDefault ?? false }
			isLoading={ isLoading }
			paginationInfo={ paginationInfo }
			defaultLayouts={ COMPANY_LAYOUTS }
			getItemId={ ( item ) => String( item.id ) }
			searchLabel={ __( 'Search companies', 'wordcamporg' ) }
			header={
				<ViewSwitch
					label={ __( 'Group by status', 'wordcamporg' ) }
					checked={ view.groupBy?.field === GROUP_BY_STATUS.field }
					onChange={ ( isOn ) =>
						updateView( { ...view, page: 1, groupBy: isOn ? GROUP_BY_STATUS : undefined } )
					}
				/>
			}
			onClickItem={ ( item ) => openCompany( item.id ) }
			{ ...selection }
		/>
	);
}

/**
 * The list of events: every sponsorship, under the event it's for. Clicking one opens it in the panel beside the
 * list.
 *
 * @return The list.
 */
function Events() {
	const { data, paginationInfo, fields, view, updateView, resetToDefault, unlinked, error, isLoading } =
		useSponsorshipList();
	const { sponsorshipId, open } = useOpenRecord();
	const openSponsorship = ( sponsorship?: number ) => open( { sponsorship } );
	const actions = useRecordActions< Sponsorship >( SPONSORSHIP );
	const selection = useSelection( view, sponsorshipId, openSponsorship );

	if ( error ) {
		return <LoadError title={ __( 'The sponsorships couldn’t be loaded.', 'wordcamporg' ) } error={ error } />;
	}

	return (
		<DataViews< Sponsorship >
			data={ data }
			fields={ fields }
			actions={ actions }
			view={ view }
			onChangeView={ updateView }
			onReset={ resetToDefault ?? false }
			isLoading={ isLoading }
			paginationInfo={ paginationInfo }
			defaultLayouts={ SPONSORSHIP_LAYOUTS }
			getItemId={ ( item ) => String( item.id ) }
			searchLabel={ __( 'Search sponsorships', 'wordcamporg' ) }
			header={
				<>
					<ViewSwitch
						/* translators: %d: Number of sponsorships. */
						label={ sprintf( __( 'Needs a company (%d)', 'wordcamporg' ), unlinked ) }
						checked={ needsCompanyOnly( view ) }
						onChange={ ( isOn ) => updateView( toggleNeedsCompany( view, isOn ) ) }
					/>
					<ViewSwitch
						label={ __( 'Group by event', 'wordcamporg' ) }
						checked={ view.groupBy?.field === GROUP_BY_EVENT.field }
						onChange={ ( isOn ) =>
							updateView( { ...view, page: 1, groupBy: isOn ? GROUP_BY_EVENT : undefined } )
						}
					/>
				</>
			}
			onClickItem={ ( item ) => openSponsorship( item.id ) }
			{ ...selection }
		/>
	);
}

/**
 * The screen: the companies or, on its other tab, their sponsorships by event.
 *
 * @return The stage.
 */
function Stage() {
	const { tab } = useOpenRecord();
	const [ isAdding, setIsAdding ] = useState( false );
	const lists: Record< typeof tab, ReactNode > = { companies: <Companies />, events: <Events /> };

	return (
		<Page
			title={ __( 'Sponsor Vetting', 'wordcamporg' ) }
			subTitle={ __( 'Vet each company once, then approve it for the events it sponsors.', 'wordcamporg' ) }
			actions={
				<Button size="compact" onClick={ () => setIsAdding( true ) }>
					{ __( 'Add company', 'wordcamporg' ) }
				</Button>
			}
			navigation={ {
				items: [
					{ label: __( 'Companies', 'wordcamporg' ), href: COMPANIES },
					{ label: __( 'Events', 'wordcamporg' ), href: EVENTS },
				],
				currentHref: tab === 'events' ? EVENTS : COMPANIES,
				ariaLabel: __( 'Lists', 'wordcamporg' ),
			} }
			components={ { link: TabLink } }
			hasPadding={ false }
		>
			{ lists[ tab ] }
			{ isAdding && <AddCompany onClose={ () => setIsAdding( false ) } /> }
		</Page>
	);
}

export const stage = Stage;
