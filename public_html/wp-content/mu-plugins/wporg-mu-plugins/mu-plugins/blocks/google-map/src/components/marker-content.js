/*
 * Internal dependencies
 */
import { getEventDateTime } from '../utilities/date-time';
import { formatLocation } from '../utilities/content';

/**
 * Render the content for a map marker.
 *
 * @param {Object} props
 *
 * @return {JSX.Element}
 */
export default function MarkerContent( props ) {
	const { type } = props;

	switch ( type ) {
		case 'wordcamp':
			return <WordCampMarker { ...props } />;

		case 'meetup':
			return <MeetupMarker { ...props } />;

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
 *
 * @return {JSX.Element}
 */
function WordCampMarker( { id, title, url, timestamp, location } ) {
	return (
		<div id={ 'wporg-map-marker__id-' + id } className="wporg-map-marker">
			<h3 className="wporg-map-marker__title">{ title }</h3>

			<p className="wporg-map-marker__url">
				<a href={ url }>{ title }</a>
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
 *
 * @return {JSX.Element}
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
