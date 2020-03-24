/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { Component, createRef } from '@wordpress/element';

export default class extends Component {
	static propTypes = {
		searchQuery: PropTypes.string,
		handleSearchEvent: PropTypes.func.isRequired,
	};

	static defaultProps = {
		searchQuery: '',
	};

	constructor( props ) {
		super( props );
		this.searchQueryInput = createRef();
	}

	/**
	 * Event handler that is called when the user types into the Search field
	 */
	handleSearchEvent = () => {
		this.props.handleSearchEvent( this.searchQueryInput.current.value );
	};

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
