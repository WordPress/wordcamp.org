/**
 * Venue Editor — overlay component for creating/editing venues.
 *
 * Renders inside the event modal as a full-cover overlay. Uses the
 * standard wp/v2/gatherpress_venues REST API for CRUD and Photon
 * (photon.komoot.io) for geocoding.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
	useRef,
	useCallback,
	Fragment,
} from '@wordpress/element';
import {
	TextControl,
	TextareaControl,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const PHOTON_API = 'https://photon.komoot.io/api/';

export default function VenueEditor( { venueId, onSave, onCancel } ) {
	const [ loading, setLoading ] = useState( !! venueId );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const [ name, setName ] = useState( '' );
	const [ fullAddress, setFullAddress ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ website, setWebsite ] = useState( '' );
	const [ accessRequirements, setAccessRequirements ] = useState( '' );
	const [ latitude, setLatitude ] = useState( '' );
	const [ longitude, setLongitude ] = useState( '' );

	const [ suggestions, setSuggestions ] = useState( [] );
	const [ showSuggestions, setShowSuggestions ] = useState( false );
	const debounceRef = useRef( null );
	const mapRef = useRef( null );
	const mapInstanceRef = useRef( null );
	const markerRef = useRef( null );

	useEffect( () => {
		if ( ! venueId ) {
			return;
		}
		apiFetch( { path: `/wp/v2/gatherpress_venues/${ venueId }` } )
			.then( ( venue ) => {
				setName( venue.title.raw || venue.title.rendered || '' );
				setDescription( venue.content?.raw || '' );
				const info = venue.meta?.gatherpress_venue_information
					? JSON.parse( venue.meta.gatherpress_venue_information )
					: {};
				setFullAddress( info.fullAddress || '' );
				setWebsite( info.website || '' );
				setLatitude( info.latitude || '' );
				setLongitude( info.longitude || '' );
				setAccessRequirements( info.accessRequirements || '' );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Could not load venue data.', 'wporg-groups-frontend' ) );
				setLoading( false );
			} );
	}, [ venueId ] );

	// Geocode address via Photon.
	const geocodeAddress = useCallback( ( query ) => {
		if ( query.length < 3 ) {
			setSuggestions( [] );
			return;
		}
		clearTimeout( debounceRef.current );
		debounceRef.current = setTimeout( () => {
			fetch( `${ PHOTON_API }?q=${ encodeURIComponent( query ) }&limit=5` )
				.then( ( r ) => r.json() )
				.then( ( data ) => {
					if ( data.features ) {
						setSuggestions(
							data.features.map( ( f ) => {
								const p = f.properties;
								const parts = [
									p.name,
									p.street,
									p.city,
									p.state,
									p.country,
								].filter( Boolean );
								return {
									label: parts.join( ', ' ),
									lat: String( f.geometry.coordinates[ 1 ] ),
									lng: String( f.geometry.coordinates[ 0 ] ),
								};
							} )
						);
						setShowSuggestions( true );
					}
				} )
				.catch( () => {} );
		}, 400 );
	}, [] );

	const selectSuggestion = ( suggestion ) => {
		setFullAddress( suggestion.label );
		setLatitude( suggestion.lat );
		setLongitude( suggestion.lng );
		setSuggestions( [] );
		setShowSuggestions( false );
	};

	// Leaflet map.
	useEffect( () => {
		if ( ! latitude || ! longitude || ! mapRef.current ) {
			return;
		}

		let cancelled = false;

		import( 'leaflet' ).then( ( L ) => {
			if ( cancelled ) {
				return;
			}

			// Load Leaflet CSS if not already present.
			if ( ! document.querySelector( 'link[href*="leaflet.css"]' ) ) {
				const link = document.createElement( 'link' );
				link.rel = 'stylesheet';
				link.href =
					'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
				document.head.appendChild( link );
			}

			const lat = parseFloat( latitude );
			const lng = parseFloat( longitude );

			if ( mapInstanceRef.current ) {
				mapInstanceRef.current.setView( [ lat, lng ], 15 );
				if ( markerRef.current ) {
					markerRef.current.setLatLng( [ lat, lng ] );
				}
				return;
			}

			const map = L.map( mapRef.current ).setView( [ lat, lng ], 15 );
			L.tileLayer(
				'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
				{
					attribution: '&copy; OpenStreetMap contributors',
					maxZoom: 19,
				}
			).addTo( map );

			const marker = L.marker( [ lat, lng ], {
				draggable: true,
			} ).addTo( map );

			marker.on( 'dragend', () => {
				const pos = marker.getLatLng();
				setLatitude( String( pos.lat.toFixed( 6 ) ) );
				setLongitude( String( pos.lng.toFixed( 6 ) ) );
			} );

			mapInstanceRef.current = map;
			markerRef.current = marker;

			setTimeout( () => map.invalidateSize(), 100 );
		} );

		return () => {
			cancelled = true;
		};
	}, [ latitude, longitude ] );

	// Cleanup map on unmount.
	useEffect( () => {
		return () => {
			if ( mapInstanceRef.current ) {
				mapInstanceRef.current.remove();
				mapInstanceRef.current = null;
				markerRef.current = null;
			}
		};
	}, [] );

	const handleSave = async () => {
		if ( ! name.trim() ) {
			setError( __( 'Venue name is required.', 'wporg-groups-frontend' ) );
			return;
		}

		setSaving( true );
		setError( '' );

		const venueInfo = JSON.stringify( {
			fullAddress,
			phoneNumber: '',
			website,
			latitude,
			longitude,
			accessRequirements,
		} );

		const body = {
			title: name,
			content: description,
			status: 'publish',
			meta: {
				gatherpress_venue_information: venueInfo,
			},
		};

		try {
			const path = venueId
				? `/wp/v2/gatherpress_venues/${ venueId }`
				: '/wp/v2/gatherpress_venues';

			const result = await apiFetch( {
				path,
				method: 'POST',
				data: body,
			} );

			onSave( {
				id: result.id,
				name: result.title.raw || result.title.rendered || name,
			} );
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Could not save venue.', 'wporg-groups-frontend' )
			);
			setSaving( false );
		}
	};

	// Intercept Escape to close overlay, not the parent modal.
	useEffect( () => {
		const onEscape = ( ev ) => {
			if ( ev.key === 'Escape' ) {
				ev.stopPropagation();
				ev.preventDefault();
				onCancel();
			}
		};
		document.addEventListener( 'keydown', onEscape, true );
		return () =>
			document.removeEventListener( 'keydown', onEscape, true );
	}, [ onCancel ] );

	if ( loading ) {
		return h(
			'div',
			{ className: 'wporg-groups-venue-editor' },
			h(
				'div',
				{ className: 'wporg-groups-venue-editor__loading' },
				h( Spinner )
			)
		);
	}

	return h(
		'div',
		{ className: 'wporg-groups-venue-editor' },
		h(
			'div',
			{ className: 'wporg-groups-venue-editor__header' },
			h(
				'h2',
				{},
				venueId
					? __( 'Edit venue', 'wporg-groups-frontend' )
					: __( 'New venue', 'wporg-groups-frontend' )
			),
			h( Button, {
				icon: 'no-alt',
				label: __( 'Close', 'wporg-groups-frontend' ),
				onClick: onCancel,
				className: 'wporg-groups-venue-editor__close',
			} )
		),
		h(
			'div',
			{ className: 'wporg-groups-venue-editor__form' },
			error &&
				h(
					Notice,
					{
						status: 'error',
						isDismissible: true,
						onDismiss: () => setError( '' ),
					},
					error
				),
			h( TextControl, {
				label: __( 'Venue name', 'wporg-groups-frontend' ),
				value: name,
				onChange: setName,
				required: true,
				__nextHasNoMarginBottom: true,
			} ),
			h(
				'div',
				{
					className: 'wporg-groups-venue-editor__field',
					style: { position: 'relative' },
				},
				h( TextControl, {
					label: __( 'Address', 'wporg-groups-frontend' ),
					value: fullAddress,
					onChange: ( v ) => {
						setFullAddress( v );
						geocodeAddress( v );
					},
					onFocus: () => {
						if ( suggestions.length ) {
							setShowSuggestions( true );
						}
					},
					placeholder: __(
						'Start typing to search…',
						'wporg-groups-frontend'
					),
					__nextHasNoMarginBottom: true,
				} ),
				showSuggestions &&
					suggestions.length > 0 &&
					h(
						'ul',
						{
							className:
								'wporg-groups-venue-editor__suggestions',
						},
						suggestions.map( ( s, i ) =>
							h(
								'li',
								{
									key: i,
									onClick: () => selectSuggestion( s ),
									onKeyDown: ( ev ) => {
										if (
											ev.key === 'Enter' ||
											ev.key === ' '
										) {
											selectSuggestion( s );
										}
									},
									role: 'option',
									tabIndex: 0,
								},
								s.label
							)
						)
					)
			),
			latitude &&
				longitude &&
				h( 'div', {
					className: 'wporg-groups-venue-editor__map',
					ref: mapRef,
				} ),
			h( TextareaControl, {
				label: __( 'Description', 'wporg-groups-frontend' ),
				value: description,
				onChange: setDescription,
				rows: 3,
				__nextHasNoMarginBottom: true,
			} ),
			h( TextControl, {
				label: __( 'Website', 'wporg-groups-frontend' ),
				type: 'url',
				value: website,
				onChange: setWebsite,
				placeholder: 'https://',
				__nextHasNoMarginBottom: true,
			} ),
			h( TextareaControl, {
				label: __( 'Access requirements', 'wporg-groups-frontend' ),
				value: accessRequirements,
				onChange: setAccessRequirements,
				rows: 2,
				help: __(
					'Parking, public transit, wheelchair access, etc.',
					'wporg-groups-frontend'
				),
				__nextHasNoMarginBottom: true,
			} ),
			h(
				'div',
				{ className: 'wporg-groups-venue-editor__actions' },
				h(
					Button,
					{
						variant: 'tertiary',
						onClick: onCancel,
						disabled: saving,
					},
					__( 'Cancel', 'wporg-groups-frontend' )
				),
				h(
					Button,
					{
						variant: 'primary',
						onClick: handleSave,
						isBusy: saving,
						disabled: saving,
					},
					venueId
						? __( 'Save venue', 'wporg-groups-frontend' )
						: __( 'Create venue', 'wporg-groups-frontend' )
				)
			)
		)
	);
}
