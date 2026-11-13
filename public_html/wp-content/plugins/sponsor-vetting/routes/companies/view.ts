import type { View } from '@wordpress/dataviews';

/* What each layout shows beside the company's name and website. A list row has no column headers, so it keeps to
   the fields that explain themselves. */
export const DEFAULT_LAYOUTS = {
	table: { fields: [ 'status', 'follow_up', 'next_event', 'sponsorships', 'last_vetted' ] },
	list: { fields: [ 'status', 'follow_up', 'next_event' ] },
};

/* How the list looks until a vetter changes it. Their changes are kept with their user preferences. */
export const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 50,
	search: '',
	filters: [],
	titleField: 'title',
	descriptionField: 'domains',
	sort: { field: 'next_event', direction: 'asc' },
	...DEFAULT_LAYOUTS.table,
};

/* Lists the companies under a heading per status, in the order of the pipeline. */
export const GROUP_BY_STATUS: NonNullable< View[ 'groupBy' ] > = {
	field: 'status',
	direction: 'asc',
	showLabel: false,
};
