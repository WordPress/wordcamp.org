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
import TableHeaderCell from './header-cell';

export default class extends Component {
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

		for ( const column in this.props.columns ) {
			if ( this.props.columns.hasOwnProperty( column ) ) {
				columns.push(
					<TableHeaderCell
						key={ column }
						fieldName={ this.props.columns[ column ] }
						fieldSlug={ column }
						isSortedColumn={ column === this.props.sortField }
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
