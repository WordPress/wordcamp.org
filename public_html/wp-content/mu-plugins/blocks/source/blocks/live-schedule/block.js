/**
 * WordPress dependencies
 */
import { _x } from '@wordpress/i18n';
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { getDataFromAPI } from './data';
import LiveSchedule from './components/schedule';

/**
 * Display the live schedule.
 *
 * @returns {Component}
 */
class Block extends Component {
	constructor( props ) {
		super( props );
		this.state = {
			sessions: [],
			isFetching: true,
		};
		this.fetchApi = this.fetchApi.bind( this );
		this.apiInterval = setInterval( this.fetchApi, 5 * 60 * 1000 ); // 5 minutes in ms.

		this.renderInterval = setInterval( () => {
			// `forceUpdate` is a React internal that triggers a render cycle.
			this.forceUpdate();
		}, 60 * 1000 ); // 1 minutes in ms.
	}

	componentDidMount() {
		this.fetchApi();
	}

	componentWillUnmount() {
		clearInterval( this.apiInterval );
		clearInterval( this.renderInterval );
	}

	fetchApi() {
		getDataFromAPI().then( ( sessions ) => this.setState( { sessions: sessions, isFetching: false } ) );
	}

	render() {
		const { config, attributes } = this.props;
		const { sessions, isFetching } = this.state;

		let classes = 'wordcamp-live-schedule';
		if ( isFetching ) {
			classes += ' is-fetching';
		}

		return (
			<div className={ classes }>
				<LiveSchedule
					config={ config }
					sessions={ sessions }
					isFetching={ isFetching }
					attributes={ attributes }
				/>
				<p className="wp-block-button aligncenter">
					<a
						className="wordcamp-live-schedule__schedule-link wp-block-button__link"
						href={ config.scheduleUrl }
					>
						{ _x( 'View Full Schedule', 'text', 'wordcamporg' ) }
					</a>
				</p>
			</div>
		);
	}
}

export default Block;
