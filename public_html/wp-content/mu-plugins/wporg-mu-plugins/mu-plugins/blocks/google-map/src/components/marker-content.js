/*
 * Internal dependencies
 */
import { getEventDateTime } from '../utilities/date-time';
import { formatLocation } from '../utilities/content';

/**
 * Render the content for a map marker.
 *
 * @param {Object} props
 */
export default function MarkerContent( props ) {
	const { type } = props;

	switch ( type ) {
		case 'wordcamp':
			return <WordCampMarker { ...props } />;

		case 'meetup':
			return <MeetupMarker { ...props } />;

		case 'combined':
			return <CombinedMarker { ...props } />;

		default:
			throw 'Component not defined for marker type: ' + type;
	}
}

/**
 * Render the content for a WordCamp event.
 *
 * @param {Object} props
 * @param {string} props.id
 * @param {string} props.title
 * @param {string} props.url
 * @param {string} props.timestamp
 * @param {string} props.location
 */
function WordCampMarker( { id, title, url, timestamp, location } ) {
	return (
		<div id={ 'wporg-map-marker__id-' + id } className="wporg-map-marker">
			<h3 className="wporg-map-marker__title">{ title }</h3>

			<p className="wporg-map-marker__url">
				<a href={ url }>Open event site</a>
			</p>

			<p className="wporg-map-marker__location">{ formatLocation( location ) }</p>

			<p className="wporg-map-marker__date-time">{ getEventDateTime( timestamp ) }</p>
		</div>
	);
}

/**
 * Render the content for a meetup event.
 *
 * @param {Object} props
 * @param {string} props.id
 * @param {string} props.title
 * @param {string} props.url
 * @param {string} props.meetup
 * @param {string} props.timestamp
 * @param {string} props.location
 */
function MeetupMarker( { id, title, url, meetup, timestamp, location } ) {
	return (
		<div id={ 'wporg-map-marker__id-' + id } className="wporg-map-marker">
			<h3 className="wporg-map-marker__title">{ meetup }</h3>

			<p className="wporg-map-marker__url">
				<a href={ url }>{ title }</a>
			</p>

			<p className="wporg-map-marker__location">{ formatLocation( location ) }</p>

			<p className="wporg-map-marker__date-time">{ getEventDateTime( timestamp ) }</p>
		</div>
	);
}

/**
 * Render a marker that combines multiple events.
 *
 * This currently assumes that all of the events are from the same meetup group, but could be made more flexible
 * in the future if needed.
 *
 * @param {Object} props
 * @param {Array}  props.events
 */
function CombinedMarker( { events } ) {
	const combinedId = events.map( ( { id } ) => id ).join( '-' );
	let combinedTitle;

	if ( 'online' !== events[ 0 ].location.toLowerCase() ) {
		combinedTitle = events[ 0 ].location;
	} else {
		combinedTitle = events[ 0 ].meetup && events[ 0 ].meetup.length ? events[ 0 ].meetup : events[ 0 ].title;
	}

	return (
		<div id={ 'wporg-map-marker__id-' + combinedId } className="wporg-map-marker">
			<h3 className="wporg-map-marker__title">{ combinedTitle }</h3>
			<ul>
				{ events.map( ( { id, url, title, location, timestamp } ) => {
					return (
						<li key={ id }>
							<p className="wporg-map-marker__url">
								<a href={ url }>{ title }</a>
							</p>

							<p className="wporg-map-marker__location">{ formatLocation( location ) }</p>

							<p className="wporg-map-marker__date-time">{ getEventDateTime( timestamp ) }</p>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}
