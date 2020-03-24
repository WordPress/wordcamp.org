import PropTypes from 'prop-types';
import React from 'react';
import SearchField from '../search-field/search-field';
import Table from './table';
import TableStore from '../../stores/table-store';

require( './style.scss' );

export default class extends React.Component {
	static propTypes = {
		initialSortField: PropTypes.string.isRequired,
		columns: PropTypes.object,
		customRender: PropTypes.object,
	};

	static defaultProps = {
		columns: {},
	};

	state = {
		searchQuery: '',
		sortField: this.props.initialSortField,
		sortOrder: 'asc',
	};

	/**
	 * Event handler that updates state in response to input to the Search field
	 *
	 * @param {string} searchQuery
	 */
	handleSearchEvent = ( searchQuery ) => {
		this.setState( {
			searchQuery,
		} );
	};

	/**
	 * Event handler that updates state when the user interacts with sorting fields
	 *
	 * @param {string} newSortField
	 */
	handleSortEvent = ( newSortField ) => {
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
	};

	render() {
		const tableRows = TableStore.getFilteredRows( {
			searchQuery: this.state.searchQuery,
			sortOrder: this.state.sortOrder,
			sortField: this.state.sortField,
		} );

		return (
			<div>
				<SearchField
					searchQuery={ this.state.searchQuery }
					handleSearchEvent={ this.handleSearchEvent }
				/>

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
