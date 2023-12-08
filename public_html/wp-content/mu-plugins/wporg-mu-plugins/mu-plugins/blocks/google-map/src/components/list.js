/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getEventDateTime } from '../utilities/date-time';
import { formatLocation } from '../utilities/content';

/**
 * Render a list of the map markers.
 *
 * @param {Object} props
 * @param {Array}  props.markers
 * @param {number} props.displayLimit
 */
export default function List( { markers, displayLimit } ) {
	if ( markers.length === 0 ) {
		return <p className="wporg-marker-list__container">{ __( 'No events available', 'wporg' ) }</p>;
	}

	if ( displayLimit > 0 ) {
		markers = markers.slice( 0, displayLimit );
	}

	return (
		<ul className="wporg-marker-list__container">
			{ markers.map( ( marker ) => (
				<li
					key={ marker.id }
					id={ 'wporg-marker-list-item__id-' + marker.id }
					className="wporg-marker-list-item"
				>
					<h3 className="wporg-marker-list-item__title">
						<a href={ marker.url }>{ marker.title }</a>
					</h3>

					<p className="wporg-marker-list-item__location">{ formatLocation( marker.location ) }</p>
					<p className="wporg-marker-list-item__date-time">{ getEventDateTime( marker.timestamp ) }</p>
				</li>
			) ) }
		</ul>
	);
}
