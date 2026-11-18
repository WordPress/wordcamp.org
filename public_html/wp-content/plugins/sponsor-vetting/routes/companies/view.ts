/**
 * How the two lists look: their layouts, their grouping, and what they start with.
 */

import type { Field, View } from '@wordpress/dataviews';
import { TRASH } from './entities';

/**
 * What each layout of a list shows.
 */
export type Layouts = { table: { fields: string[] }; list: { fields: string[] } };

/**
 * What each layout shows beside the company's name and website. A list row has no column headers, so it keeps to
 * the fields that explain themselves.
 */
export const COMPANY_LAYOUTS: Layouts = {
	table: { fields: [ 'status', 'follow_up', 'next_event', 'sponsorships', 'last_vetted' ] },
	list: { fields: [ 'status', 'follow_up', 'next_event' ] },
};

/**
 * Lists the companies under a heading per status, in the order of the pipeline.
 */
export const GROUP_BY_STATUS: NonNullable< View[ 'groupBy' ] > = {
	field: 'status',
	direction: 'asc',
	showLabel: false,
};

/**
 * A choice of the Layout menu. DataViews' own menu has one choice per layout, and the companies have two tables.
 */
export type LayoutChoice = {
	id: 'table' | 'grouped' | 'list';
	type: 'table' | 'list';
	fields: string[];
	groupBy?: NonNullable< View[ 'groupBy' ] >;
};

/**
 * The layouts of the list of companies, in the order of the menu.
 */
export const COMPANY_LAYOUT_CHOICES: LayoutChoice[] = [
	{ id: 'table', type: 'table', ...COMPANY_LAYOUTS.table },
	{ id: 'grouped', type: 'table', ...COMPANY_LAYOUTS.table, groupBy: GROUP_BY_STATUS },
	{ id: 'list', type: 'list', ...COMPANY_LAYOUTS.list },
];

/**
 * How the list of companies looks until a vetter changes it. Their changes are kept with their preferences.
 */
export const COMPANY_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 50,
	search: '',
	filters: [],
	titleField: 'title',
	descriptionField: 'domains',
	sort: { field: 'next_event', direction: 'asc' },
	...COMPANY_LAYOUTS.table,
};

/**
 * Lists the sponsorships under a heading per event: the events still to come first, soonest first.
 */
export const GROUP_BY_EVENT: NonNullable< View[ 'groupBy' ] > = {
	field: 'event',
	direction: 'asc',
	showLabel: false,
};

/**
 * What each layout shows beside the sponsor's name. The event heads the group.
 */
export const SPONSORSHIP_LAYOUTS: Layouts = {
	table: { fields: [ 'status', 'follow_up', 'approved' ] },
	list: { fields: [ 'status', 'follow_up' ] },
};

/**
 * How the list of events looks until a vetter changes it: by event, with what's still pending first.
 */
export const SPONSORSHIP_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 50,
	search: '',
	filters: [],
	titleField: 'title',
	sort: { field: 'status', direction: 'asc' },
	groupBy: GROUP_BY_EVENT,
	...SPONSORSHIP_LAYOUTS.table,
};

/**
 * Tells which choice of the Layout menu a view shows.
 *
 * @param view    The view.
 * @param choices The menu's choices.
 * @return The choice with the view's layout and grouping, or the plain one of that layout.
 */
export function getLayoutChoice( view: View, choices: LayoutChoice[] ): LayoutChoice | undefined {
	const sameLayout = choices.filter( ( { type } ) => type === view.type );

	return sameLayout.find( ( { groupBy } ) => groupBy?.field === view.groupBy?.field ) ?? sameLayout[ 0 ];
}

/**
 * Changes a view to a choice of the Layout menu.
 *
 * Within a layout only the grouping changes, so the vetter's columns stay. Another layout starts from its own
 * fields and settings, as DataViews' own menu has it.
 *
 * @param view   The view.
 * @param choice The choice.
 * @return The changed view, on its first page.
 */
export function applyLayoutChoice( view: View, choice: LayoutChoice ): View {
	const { type, fields, groupBy } = choice;

	if ( type === view.type ) {
		return { ...view, page: 1, groupBy };
	}

	const next: Partial< View > = { ...view };
	delete next.layout;

	return { ...next, type, fields, groupBy, page: 1 } as View;
}

/**
 * Shows a view as a narrow list, which is how a list fits beside an open record.
 *
 * @param view   The view.
 * @param fields What a row of the list shows.
 * @return The view in the list layout. Its search, filters, sorting and grouping stay.
 */
export function asNarrowList( view: View, fields: string[] ): View {
	const next: Partial< View > = { ...view };
	delete next.layout;

	return { ...next, type: 'list', fields } as View;
}

/**
 * Leaves the trashed records out of a list, unless the view asks for them by filtering for the Trash.
 *
 * @param records The records.
 * @param view    The view.
 * @return The records the view is about.
 */
export function withoutTrash< Item extends { status: string } >( records: Item[], view: View ): Item[] {
	const wantsTrash = view.filters?.some(
		( { field, operator, value } ) =>
			field === 'status_filter' && operator === 'isAny' && [ value ].flat().includes( TRASH )
	);

	return wantsTrash ? records : records.filter( ( { status } ) => status !== TRASH );
}

/**
 * Leaves out of a view the filters its list has no control for, which a vetter could neither see nor clear: a view
 * that was kept can still hold one on a field that has stopped filtering.
 *
 * @param view   The view.
 * @param fields The list's fields.
 * @return The view with the filters its list offers.
 */
export function withoutHiddenFilters< Item >( view: View, fields: Field< Item >[] ): View {
	const offered = new Set( fields.filter( ( { filterBy } ) => filterBy !== false ).map( ( { id } ) => id ) );

	return { ...view, filters: view.filters?.filter( ( { field } ) => offered.has( field ) ) } as View;
}
