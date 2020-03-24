import PropTypes from 'prop-types';
import React from 'react';

export default class extends React.Component {
	static propTypes = {
		searchQuery: PropTypes.string,
		handleSearchEvent: PropTypes.func.isRequired,
	};

	static defaultProps = {
		searchQuery: '',
	};

	constructor( props ) {
		super( props );
		this.searchQueryInput = React.createRef();
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
