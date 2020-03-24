/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import TableHeader from './header';
import TableRow from './row';

export default class extends Component {
	static propTypes = {
		columns: PropTypes.object,
		rows: PropTypes.array,
		sortField: PropTypes.string.isRequired,
		sortOrder: PropTypes.oneOf( [ 'asc', 'desc' ] ),
		handleSortEvent: PropTypes.func.isRequired,
		customRender: PropTypes.object,
	};

	static defaultProps = {
		columns: {},
		rows: [],
		sortOrder: 'asc',
	};

	render() {
		const rows = this.props.rows.map(
			function( row, index ) {
				return (
					<TableRow
						columns={ this.props.columns }
						row={ row }
						key={ index }
						customRender={ this.props.customRender }
					/>
				);
			}.bind( this )
		);

		return (
			<table className="filterable-table fixed striped">
				<TableHeader
					columns={ this.props.columns }
					sortField={ this.props.sortField }
					sortOrder={ this.props.sortOrder }
					handleSortEvent={ this.props.handleSortEvent }
				/>

				<tbody>{ rows }</tbody>
			</table>
		);
	}
}
