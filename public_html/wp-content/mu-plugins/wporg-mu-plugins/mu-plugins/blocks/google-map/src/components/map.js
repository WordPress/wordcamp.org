/**
 * External dependencies
 */
import GoogleMapReact from 'google-map-react';

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { mapStyles } from '../utilities/map-styles';
import {
	assignMarkerReferences,
	clusterMarkers,
	panToCenter,
	setVisibleMarkers,
} from '../utilities/google-maps-api';

/**
 * Render a Google Map with info windows for the given markers.
 *
 * @see https://github.com/google-map-react/google-map-react#use-google-maps-api
 *
 * @param {Object} props
 * @param {string} props.apiKey
 * @param {Array}  props.markers
 * @param {Object} props.icon
 *
 * @return {JSX.Element}
 */
export default function Map( { apiKey, markers, icon } ) {
	const [ loaded, setLoaded ] = useState( false );
	const [ clusterer, setClusterer ] = useState( null );
	const [ googleMap, setGoogleMap ] = useState( null );
	const [ googleMapsApi, setGoogleMapsApi ] = useState( null );

	const options = {
		zoomControl: true,
		mapTypeControl: false,
		streetViewControl: false,
		styles: mapStyles,
	};

	/**
	 * Add markers to the map and cluster them.
	 *
	 * Callback for `onGoogleApiLoaded`.
	 */
	const mapLoaded = useCallback( ( { map, maps } ) => {
		if ( ! map || ! maps ) {
			throw 'Google Maps API is not loaded.';
		}

		setGoogleMap( map );
		setGoogleMapsApi( maps );

		markers = assignMarkerReferences( map, maps, markers, icon );

		setClusterer(
			clusterMarkers(
				map,
				maps,
				markers.map( ( marker ) => marker.markerRef ),
				icon
			)
		);

		setLoaded( true );
	}, [] );

	/**
	 * Update the map whenever the supplied markers change.
	 */
	useEffect( () => {
		if ( ! clusterer ) {
			return;
		}

		const markerObjects = markers.map( ( marker ) => marker.markerRef );

		setVisibleMarkers( clusterer, markerObjects, googleMap );
		panToCenter( markerObjects, googleMap, googleMapsApi );
	}, [ clusterer, markers ] );

	return (
		<div className="wporg-google-map__container">
			{ ! loaded && <Spinner /> }

			<GoogleMapReact
				defaultZoom={ 1 }
				defaultCenter={ {
					lat: 30.0,
					lng: 10.0,
				} }
				bootstrapURLKeys={ { key: apiKey } }
				yesIWantToUseGoogleMapApiInternals
				onGoogleApiLoaded={ mapLoaded }
				options={ options }
			/>
		</div>
	);
}
