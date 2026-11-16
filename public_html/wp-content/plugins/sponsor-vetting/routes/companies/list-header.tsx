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
				<Tabs.Tab value="companies">{ __( 'Companies', 'wordcamporg' ) }</Tabs.Tab>
				<Tabs.Tab value="events">{ __( 'Events', 'wordcamporg' ) }</Tabs.Tab>
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
 * @param props.layoutMenu  The list's Layout menu.
 * @param props.children    Controls of the list's own, after DataViews'.
 * @return The bar.
 */
export function ListHeader( {
	searchLabel,
	layoutMenu,
	children,
}: {
	searchLabel: string;
	layoutMenu: ReactNode;
	children?: ReactNode;
} ) {
	return (
		<>
			<Stack direction="row" justify="space-between" gap="lg" className="sponsor-vetting__list-header">
				<ListTabs />
				<Stack direction="row" align="center" gap="xs">
					<DataViews.Search label={ searchLabel } />
					{ layoutMenu }
					<DataViews.ViewConfig />
					{ children }
				</Stack>
			</Stack>
			<DataViews.FiltersToggled className="dataviews-filters__container" />
		</>
	);
}
