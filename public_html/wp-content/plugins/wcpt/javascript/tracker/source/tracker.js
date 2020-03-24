/**
 * WordPress dependencies
 */
import { render } from '@wordpress/element';

/**
 * Internal dependencies
 */
import FilterableTable from './components/filterable-table';

require( './style.scss' );

/**
 * Custom render function for lastUpdatedColumn. Will display X time ago instead of unix timestamp. Use
 * `humanizedTime` field sent from server.
 *
 * @param row
 */
const renderHumanizedTime = ( row ) => {
	return row.humanizedTime;
};

render(
	<FilterableTable
		initialSortField={ wpcApplicationTracker.initialSortField }
		columns={ wpcApplicationTracker.displayColumns }
		customRender={ {
			lastUpdate: renderHumanizedTime,
		} }
	/>,
	document.getElementById( 'wpc-application-tracker' )
);
