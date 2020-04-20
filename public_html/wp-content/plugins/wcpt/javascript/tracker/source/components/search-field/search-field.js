/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { Component, createRef } from '@wordpress/element';

class SearchField extends Component {
	constructor( props ) {
		super( props );
		this.searchQueryInput = createRef();
		this.handleSearchEvent = this.handleSearchEvent.bind( this );
	}

	/**
	 * Event handler that is called when the user types into the Search field
	 */
	handleSearchEvent() {
		this.props.handleSearchEvent( this.searchQueryInput.current.value );
	}

	render() {
		return (
			<form>
				<p>
					<input
						type="text"
						placeholder="Search..."
						value={ this.props.searchQuery }
						ref={ this.searchQueryInput }
						onChange={ this.handleSearchEvent }
					/>
				</p>
			</form>
		);
	}
}

SearchField.propTypes = {
	searchQuery: PropTypes.string,
	handleSearchEvent: PropTypes.func.isRequired,
};

SearchField.defaultProps = {
	searchQuery: '',
};

export default SearchField;
