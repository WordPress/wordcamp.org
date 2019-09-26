/* eslint-disable require-jsdoc */
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
		this.update = this.update.bind( this );
		this.interval = setInterval( this.update, 60 * 1000 ); // 60 seconds in ms.
	}

	componentDidMount() {
		this.update();
	}

	componentWillUnmount() {
		clearInterval( this.interval );
	}

	update() {
		getDataFromAPI().then( ( sessions ) => this.setState( { sessions: sessions, isFetching: false } ) );
	}

	render() {
		const { config } = this.props;
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
