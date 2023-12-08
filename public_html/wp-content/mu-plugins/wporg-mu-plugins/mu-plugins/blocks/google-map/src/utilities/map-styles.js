/**
 * Define the styles for the Google Map.
 *
 * These were generated from https://mapstyle.withgoogle.com/ with the settings noted below.
 */

// Theme Silver, Roads 2, Landmarks 2, Labels 3, Water color #c7d1ff.
const wp20 = [
	{
		elementType: 'geometry',
		stylers: [
			{
				color: '#f5f5f5',
			},
		],
	},
	{
		elementType: 'labels.icon',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#616161',
			},
		],
	},
	{
		elementType: 'labels.text.stroke',
		stylers: [
			{
				color: '#f5f5f5',
			},
		],
	},
	{
		featureType: 'administrative.land_parcel',
		elementType: 'geometry',
		stylers: [
			{
				color: '#c7d1ff',
			},
		],
	},
	{
		featureType: 'administrative.land_parcel',
		elementType: 'labels',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'administrative.land_parcel',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#bdbdbd',
			},
		],
	},
	{
		featureType: 'poi',
		elementType: 'geometry',
		stylers: [
			{
				color: '#eeeeee',
			},
		],
	},
	{
		featureType: 'poi',
		elementType: 'labels.text',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'poi',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#757575',
			},
		],
	},
	{
		featureType: 'poi.business',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'poi.park',
		elementType: 'geometry',
		stylers: [
			{
				color: '#e5e5e5',
			},
		],
	},
	{
		featureType: 'poi.park',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#9e9e9e',
			},
		],
	},
	{
		featureType: 'road',
		elementType: 'geometry',
		stylers: [
			{
				color: '#ffffff',
			},
		],
	},
	{
		featureType: 'road',
		elementType: 'labels.icon',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.arterial',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.arterial',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#757575',
			},
		],
	},
	{
		featureType: 'road.highway',
		elementType: 'geometry',
		stylers: [
			{
				color: '#dadada',
			},
		],
	},
	{
		featureType: 'road.highway',
		elementType: 'labels',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.highway',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#616161',
			},
		],
	},
	{
		featureType: 'road.local',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.local',
		elementType: 'labels',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.local',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#9e9e9e',
			},
		],
	},
	{
		featureType: 'transit',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'transit.line',
		elementType: 'geometry',
		stylers: [
			{
				color: '#e5e5e5',
			},
		],
	},
	{
		featureType: 'transit.station',
		elementType: 'geometry',
		stylers: [
			{
				color: '#eeeeee',
			},
		],
	},
	{
		featureType: 'water',
		elementType: 'geometry',
		stylers: [
			{
				color: '#C9D1FB',
			},
		],
	},
	{
		featureType: 'water',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#9e9e9e',
			},
		],
	},
];

// Theme Silver, Roads 2, Landmarks 2, Labels 3, Land color #e7e7e7, Water color #ffffff.
const sotw2023 = [
	{
		elementType: 'geometry',
		stylers: [
			{
				color: '#e7e7e7',
			},
		],
	},
	{
		elementType: 'labels.icon',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#616161',
			},
		],
	},
	{
		elementType: 'labels.text.stroke',
		stylers: [
			{
				color: '#f5f5f5',
			},
		],
	},
	{
		featureType: 'administrative.land_parcel',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#bdbdbd',
			},
		],
	},
	{
		featureType: 'poi',
		elementType: 'geometry',
		stylers: [
			{
				color: '#eeeeee',
			},
		],
	},
	{
		featureType: 'poi',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#757575',
			},
		],
	},
	{
		featureType: 'poi.business',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'poi.park',
		elementType: 'geometry',
		stylers: [
			{
				color: '#e5e5e5',
			},
		],
	},
	{
		featureType: 'poi.park',
		elementType: 'labels.text',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'poi.park',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#9e9e9e',
			},
		],
	},
	{
		featureType: 'road',
		elementType: 'geometry',
		stylers: [
			{
				color: '#ffffff',
			},
		],
	},
	{
		featureType: 'road.arterial',
		elementType: 'labels',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.arterial',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#757575',
			},
		],
	},
	{
		featureType: 'road.highway',
		elementType: 'geometry',
		stylers: [
			{
				color: '#dadada',
			},
		],
	},
	{
		featureType: 'road.highway',
		elementType: 'labels',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.highway',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#616161',
			},
		],
	},
	{
		featureType: 'road.local',
		stylers: [
			{
				visibility: 'off',
			},
		],
	},
	{
		featureType: 'road.local',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#9e9e9e',
			},
		],
	},
	{
		featureType: 'transit.line',
		elementType: 'geometry',
		stylers: [
			{
				color: '#e5e5e5',
			},
		],
	},
	{
		featureType: 'transit.station',
		elementType: 'geometry',
		stylers: [
			{
				color: '#eeeeee',
			},
		],
	},
	{
		featureType: 'water',
		elementType: 'geometry',
		stylers: [
			{
				color: '#ffffff',
			},
		],
	},
	{
		featureType: 'water',
		elementType: 'labels.text.fill',
		stylers: [
			{
				color: '#9e9e9e',
			},
		],
	},
];

/**
 * Determine which style variation this block is using.
 *
 * This shouldn't be needed if https://github.com/WordPress/gutenberg/issues/56278 is resolved.
 *
 * @param { string } className
 *
 * @return { string } The slug of the block style.
 */
export function getBlockStyle( className ) {
	let blockStyle = 'wp20';

	if ( 'undefined' !== typeof className && className.indexOf( 'is-style-sotw-2023' ) !== -1 ) {
		blockStyle = 'sotw2023';
	}

	return blockStyle;
}

export const mapStyles = { wp20, sotw2023 };
