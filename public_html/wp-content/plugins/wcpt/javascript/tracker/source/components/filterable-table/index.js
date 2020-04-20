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
import SearchField from '../search-field/search-field';
import Table from './table';
import TableStore from '../../stores/table-store';

require( './style.scss' );

class FilterableTable extends Component {
	constructor( props ) {
		super( props );
		this.state = {
			searchQuery: '',
			sortField: this.props.initialSortField,
			sortOrder: 'asc',
		};
		this.handleSearchEvent = this.handleSearchEvent.bind( this );
		this.handleSortEvent = this.handleSortEvent.bind( this );
	}

	/**
	 * Event handler that updates state in response to input to the Search field
	 *
	 * @param {string} searchQuery
	 */
	handleSearchEvent( searchQuery ) {
		this.setState( {
			searchQuery,
		} );
	}

	/**
	 * Event handler that updates state when the user interacts with sorting fields
	 *
	 * @param {string} newSortField
	 */
	handleSortEvent( newSortField ) {
		const previousSortField = this.state.sortField;
		let newSortOrder = this.state.sortOrder;

		if ( previousSortField === newSortField ) {
			newSortOrder = 'asc' === this.state.sortOrder ? 'desc' : 'asc';
		} else {
			newSortOrder = 'asc';
		}

		this.setState( {
			sortField: newSortField,
			sortOrder: newSortOrder,
		} );
	}

	render() {
		const tableRows = TableStore.getFilteredRows( {
			searchQuery: this.state.searchQuery,
			sortOrder: this.state.sortOrder,
			sortField: this.state.sortField,
		} );

		return (
			<div>
				<SearchField searchQuery={ this.state.searchQuery } handleSearchEvent={ this.handleSearchEvent } />

				<Table
					columns={ this.props.columns }
					rows={ tableRows }
					sortField={ this.state.sortField }
					sortOrder={ this.state.sortOrder }
					handleSortEvent={ this.handleSortEvent }
					customRender={ this.props.customRender }
				/>
			</div>
		);
	}
}

FilterableTable.propTypes = {
	initialSortField: PropTypes.string.isRequired,
	columns: PropTypes.object,
	customRender: PropTypes.object,
};

FilterableTable.defaultProps = {
	columns: {},
};

export default FilterableTable;
