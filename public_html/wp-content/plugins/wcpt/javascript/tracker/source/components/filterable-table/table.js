/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * Internal dependencies
 */
import TableHeader from './header';
import TableRow from './row';

function Table( { columns = {}, rows = [], customRender, handleSortEvent, sortField, sortOrder = 'asc' } ) {
	return (
		<table className="filterable-table fixed striped">
			<TableHeader
				columns={ columns }
				sortField={ sortField }
				sortOrder={ sortOrder }
				handleSortEvent={ handleSortEvent }
			/>

			<tbody>
				{ rows.map( ( row, index ) => {
					return (
						<TableRow columns={ columns } row={ row } key={ index } customRender={ customRender } />
					);
				} ) }
			</tbody>
		</table>
	);
}

Table.propTypes = {
	columns: PropTypes.object,
	rows: PropTypes.array,
	sortField: PropTypes.string.isRequired,
	sortOrder: PropTypes.oneOf( [ 'asc', 'desc' ] ),
	handleSortEvent: PropTypes.func.isRequired,
	customRender: PropTypes.object,
};

export default Table;
