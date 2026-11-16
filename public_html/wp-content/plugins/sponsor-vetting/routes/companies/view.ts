import type { View } from '@wordpress/dataviews';
import { TRASH } from './entities';

/* What each layout shows beside the company's name and website. A list row has no column headers, so it keeps to
   the fields that explain themselves. */
export const COMPANY_LAYOUTS = {
	table: { fields: [ 'status', 'follow_up', 'next_event', 'sponsorships', 'last_vetted' ] },
	list: { fields: [ 'status', 'follow_up', 'next_event' ] },
};

/* Lists the companies under a heading per status, in the order of the pipeline. */
export const GROUP_BY_STATUS: NonNullable< View[ 'groupBy' ] > = {
	field: 'status',
	direction: 'asc',
	showLabel: false,
};

/* A choice of the Layout menu. DataViews' own menu has one choice per layout, and the companies have two
   tables. */
export type LayoutChoice = {
	id: 'table' | 'grouped' | 'list';
	type: 'table' | 'list';
	fields: string[];
	groupBy?: NonNullable< View[ 'groupBy' ] >;
};

/* The layouts of the list of companies, in the order of the menu. */
export const COMPANY_LAYOUT_CHOICES: LayoutChoice[] = [
	{ id: 'table', type: 'table', ...COMPANY_LAYOUTS.table },
	{ id: 'grouped', type: 'table', ...COMPANY_LAYOUTS.table, groupBy: GROUP_BY_STATUS },
	{ id: 'list', type: 'list', ...COMPANY_LAYOUTS.list },
];

/* How the list of companies looks until a vetter changes it. Their changes are kept with their preferences. */
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

/* Lists the sponsorships under a heading per event: the events still to come first, soonest first. */
export const GROUP_BY_EVENT: NonNullable< View[ 'groupBy' ] > = {
	field: 'event',
	direction: 'asc',
	showLabel: false,
};

/* What each layout shows beside the sponsorship's name. The event heads the group. */
export const SPONSORSHIP_LAYOUTS = {
	table: { fields: [ 'company', 'status', 'approved' ] },
	list: { fields: [ 'company', 'status' ] },
};

/* How the list of events looks until a vetter changes it: by event, with what's still pending first. */
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

/* The filter that leaves only the sponsorships waiting for a company. */
export const NEEDS_COMPANY = { field: 'linked', operator: 'is', value: 'no' } as const;

/**
 * Leaves the trashed records out of a list, unless the view asks for them by filtering for the Trash.
 *
 * @param records    The records.
 * @param view       The view.
 * @param trashLabel The label of the Trash, which is what the status filter holds.
 * @return The records the view is about.
 */
export function withoutTrash< Item extends { status: string } >(
	records: Item[],
	view: View,
	trashLabel: string
): Item[] {
	const wantsTrash = view.filters?.some(
		( { field, operator, value } ) =>
			field === 'status' && operator === 'isAny' && [ value ].flat().includes( trashLabel )
	);

	return wantsTrash ? records : records.filter( ( { status } ) => status !== TRASH );
}

/**
 * Tells whether a view lists only the sponsorships waiting for a company.
 *
 * @param view The view.
 * @return Whether it does.
 */
export function needsCompanyOnly( view: View ): boolean {
	return Boolean(
		view.filters?.some(
			( { field, operator, value } ) =>
				field === NEEDS_COMPANY.field &&
				operator === NEEDS_COMPANY.operator &&
				value === NEEDS_COMPANY.value
		)
	);
}

/**
 * Turns the filter for sponsorships waiting for a company on or off, keeping the view's other filters.
 *
 * @param view The view.
 * @param isOn Whether to list only those.
 * @return The changed view, on its first page.
 */
export function toggleNeedsCompany( view: View, isOn: boolean ): View {
	const others = ( view.filters ?? [] ).filter( ( { field } ) => field !== NEEDS_COMPANY.field );

	return { ...view, page: 1, filters: isOn ? [ ...others, { ...NEEDS_COMPANY } ] : others };
}
