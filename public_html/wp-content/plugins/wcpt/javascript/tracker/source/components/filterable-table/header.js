/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * Internal dependencies
 */
import TableHeaderCell from './header-cell';

function TableHeader( { columns = {}, sortField, sortOrder = 'asc', handleSortEvent } ) {
	return (
		<thead>
			<tr>
				{ Object.entries( columns ).map( ( [ key, column ] ) => (
					<TableHeaderCell
						key={ key }
						fieldName={ column }
						fieldSlug={ key }
						isSortedColumn={ key === sortField }
						sortOrder={ sortOrder }
						handleSortEvent={ handleSortEvent }
					/>
				) ) }
			</tr>
		</thead>
	);
}

TableHeader.propTypes = {
	columns: PropTypes.object,
	sortField: PropTypes.string.isRequired,
	sortOrder: PropTypes.oneOf( [ 'asc', 'desc' ] ),
	handleSortEvent: PropTypes.func.isRequired,
};

export default TableHeader;
