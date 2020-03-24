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
import SortingIndicator from '../sorting-indicator/sorting-indicator';

export default class extends Component {
	static propTypes = {
		isSortedColumn: PropTypes.bool,
		sortOrder: PropTypes.oneOf( [ 'asc', 'desc' ] ),
		fieldSlug: PropTypes.string.isRequired,
		fieldName: PropTypes.string.isRequired,
		handleSortEvent: PropTypes.func.isRequired,
	};

	static defaultProps = {
		isSortedColumn: false,
		sortOrder: 'asc',
	};

	/**
	 * Get the CSS classes for the `th` element
	 *
	 * @return {string}
	 */
	getClassNames = () => {
		let sortClasses = '';

		if ( this.props.isSortedColumn ) {
			sortClasses = ' sorted ' + this.props.sortOrder;
		}

		return this.props.fieldSlug + sortClasses;
	};

	render() {
		const onClick = this.props.handleSortEvent.bind( null, this.props.fieldSlug );

		return (
			<th className={ this.getClassNames() }>
				<button onClick={ onClick } value={ this.props.fieldSlug }>
					{ this.props.fieldName }
				</button>

				{ this.props.isSortedColumn ? <SortingIndicator sortOrder={ this.props.sortOrder } /> : '' }
			</th>
		);
	}
}
