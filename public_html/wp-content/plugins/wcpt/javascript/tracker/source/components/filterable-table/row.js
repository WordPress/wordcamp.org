/**
 * External dependencies
 */
import PropTypes from 'prop-types';

function TableRow( { columns = {}, row = {}, customRender = {} } ) {
	const cells = [];

	/*
	 * Loop through the display columns instead of the row, because the row might have meta data that
	 * shouldn't be displayed, like URLs.
	 */
	for ( const columnName in columns ) {
		let cellContent = '';

		if ( ! columns.hasOwnProperty( columnName ) ) {
			continue;
		}

		if ( row[ columnName + 'Url' ] ) {
			cellContent = <a href={ row[ columnName + 'Url' ] }>{ row[ columnName ] }</a>;
		} else if ( customRender[ columnName ] ) {
			cellContent = customRender[ columnName ]( row, row[ columnName ] );
		} else {
			cellContent = row[ columnName ];
		}

		cells.push(
			<td className={ columnName } key={ columnName }>
				{ cellContent }
			</td>
		);
	}

	return <tr>{ cells }</tr>;
}

TableRow.propTypes = {
	columns: PropTypes.object,
	row: PropTypes.object,
	customRender: PropTypes.object,
};

export default TableRow;
