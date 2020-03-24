import PropTypes from 'prop-types';
import React from 'react';
import TableHeaderCell from './header-cell';

export default class extends React.Component {
	static propTypes = {
		columns: PropTypes.object,
		sortField: PropTypes.string.isRequired,
		sortOrder: PropTypes.oneOf( [ 'asc', 'desc' ] ),
		handleSortEvent: PropTypes.func.isRequired,
	};

	static defaultProps = {
		columns: {},
		sortOrder: 'asc',
	};

	render() {
		const columns = [];

		for ( const i in this.props.columns ) {
			if ( this.props.columns.hasOwnProperty( i ) ) {
				columns.push(
					<TableHeaderCell
						key={ i }
						fieldName={ this.props.columns[ i ] }
						fieldSlug={ i }
						isSortedColumn={ i === this.props.sortField }
						sortOrder={ this.props.sortOrder }
						handleSortEvent={ this.props.handleSortEvent }
					/>
				);
			}
		}

		return (
			<thead>
				<tr>{ columns }</tr>
			</thead>
		);
	}
}
