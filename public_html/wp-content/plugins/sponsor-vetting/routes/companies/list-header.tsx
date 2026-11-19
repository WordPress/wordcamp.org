/**
 * The head of a list: its tabs, its search and its Layout menu.
 */

import { DataViews } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { Stack, Tabs } from '@wordpress/ui';
import type { ReactNode } from 'react';
import { useOpenRecord } from './hooks';

/**
 * The tabs that switch between the two lists.
 *
 * @return The tabs.
 */
export function ListTabs() {
	const { tab, openTab } = useOpenRecord();

	return (
		<Tabs.Root value={ tab } onValueChange={ openTab }>
			<Tabs.List variant="minimal">
				<Tabs.Tab value="events">{ __( 'Events', 'wordcamporg' ) }</Tabs.Tab>
				<Tabs.Tab value="companies">{ __( 'Companies', 'wordcamporg' ) }</Tabs.Tab>
			</Tabs.List>
		</Tabs.Root>
	);
}

/**
 * The bar above a list, inside its DataViews: the tabs, then DataViews' own search and view controls, and under
 * them its filters.
 *
 * DataViews' button that shows and hides the filters is left out: both lists pin a filter, which keeps the filters
 * shown and that button disabled.
 *
 * @param props             Props.
 * @param props.searchLabel What the search field is for.
 * @param props.layoutMenu  The list's Layout menu. Without one the list is the narrow one beside an open record,
 *                          which has no room for the view controls and puts the search on a line of its own.
 * @return The bar.
 */
export function ListHeader( { searchLabel, layoutMenu }: { searchLabel: string; layoutMenu?: ReactNode } ) {
	return (
		<>
			<Stack direction="row" justify="space-between" gap="lg" className="sponsor-vetting__list-header">
				<ListTabs />
				{ layoutMenu && (
					<Stack direction="row" align="center" gap="xs">
						<DataViews.Search label={ searchLabel } />
						{ layoutMenu }
						<DataViews.ViewConfig />
					</Stack>
				) }
			</Stack>
			{ ! layoutMenu && (
				<div className="sponsor-vetting__list-search">
					<DataViews.Search label={ searchLabel } />
				</div>
			) }
			<DataViews.FiltersToggled className="dataviews-filters__container" />
		</>
	);
}
