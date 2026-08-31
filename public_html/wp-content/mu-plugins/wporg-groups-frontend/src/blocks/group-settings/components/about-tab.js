/**
 * Group Settings — About tab.
 *
 * Group description, location, social links.
 *
 * @package WordCamp\Groups\Frontend
 */

import {
	createElement as h,
	useState,
	useEffect,
} from '@wordpress/element';
import {
	TextControl,
	RadioControl,
	SelectControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export default function AboutTab() {
	const [ loading, setLoading ] = useState( true );
	const [ loadFailed, setLoadFailed ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( '' );
	const [ noticeType, setNoticeType ] = useState( 'success' );
	const [ countries, setCountries ] = useState( [] );
	const [ form, setForm ] = useState( {
		blogname: '',
		blogdescription: '',
		locationType: '',
		city: '',
		countryCode: '',
	} );
	// A stored code the country list no longer recognizes has no option to
	// select in the dropdown, so the field starts blank — which would make
	// the location look incomplete even though the user hasn't touched it.
	// Tracking whether they've actually edited the location lets save leave
	// it out of the request entirely when they haven't, so the backend's own
	// re-validation (which would reject that same stale code right back)
	// never runs, and unrelated fields like the group name can still be
	// saved. Only an explicit edit — including "Clear location" — should
	// submit a location change.
	const [ locationDirty, setLocationDirty ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/wporg-groups/v1/group-info' } )
			.then( ( data ) => {
				const location = data.location || {};
				const countryOptions = data.countries || [];
				const storedCountry = location.countryCode || '';
				const isKnownCountry = countryOptions.some( ( country ) => country.code === storedCountry );

				setForm( {
					blogname: data.title || '',
					blogdescription: data.description || '',
					locationType: location.type || '',
					city: location.city || '',
					countryCode: isKnownCountry ? storedCountry : '',
				} );
				setCountries( countryOptions );
				setLoading( false );
			} )
			.catch( ( err ) => {
				// Prevent a failed read from being saved back as blank values.
				setLoadFailed( true );
				setNoticeType( 'error' );
				setNotice(
					err.message ||
						__( 'Could not load group settings.', 'wporg-groups-frontend' )
				);
				setLoading( false );
			} );
	}, [] );

	const handleSave = async () => {
		setSaving( true );
		setNotice( '' );
		try {
			const data = {
				title: form.blogname,
				description: form.blogdescription,
			};

			if ( locationDirty ) {
				if ( 'online' === form.locationType ) {
					data.location = { type: 'online' };
				} else if ( 'physical' === form.locationType ) {
					data.location = {
						type: 'physical',
						city: form.city,
						countryCode: form.countryCode,
					};
				} else {
					data.location = null;
				}
			}

			await apiFetch( {
				path: '/wporg-groups/v1/group-info',
				method: 'POST',
				data,
			} );
			setNoticeType( 'success' );
			setNotice( __( 'Settings saved.', 'wporg-groups-frontend' ) );
		} catch ( err ) {
			setNoticeType( 'error' );
			setNotice( err.message || __( 'Could not save settings.', 'wporg-groups-frontend' ) );
		} finally {
			setSaving( false );
		}
	};

	const physicalLocationIsIncomplete =
		locationDirty &&
		'physical' === form.locationType &&
		( '' === form.city.trim() || '' === form.countryCode );

	if ( loading ) {
		return h( 'div', { className: 'wporg-settings-tab__loading' }, h( Spinner ) );
	}

	return h(
		'div',
		{ className: 'wporg-settings-tab' },
		notice &&
			h(
				Notice,
				{
					status: noticeType,
					isDismissible: true,
					onDismiss: () => setNotice( '' ),
				},
				notice
			),
		h( TextControl, {
			label: __( 'Group name', 'wporg-groups-frontend' ),
			value: form.blogname,
			onChange: ( v ) => setForm( { ...form, blogname: v } ),
			disabled: loadFailed,
			__nextHasNoMarginBottom: true,
		} ),
		// blogdescription is sanitized as a single line.
		h( TextControl, {
			label: __( 'Description', 'wporg-groups-frontend' ),
			value: form.blogdescription,
			onChange: ( v ) => setForm( { ...form, blogdescription: v } ),
			disabled: loadFailed,
			help: __( 'A short tagline for your group, used in the browser title and search results.', 'wporg-groups-frontend' ),
			__nextHasNoMarginBottom: true,
		} ),
		h(
			'div',
			{ className: 'wporg-settings-tab__location' },
			h( 'h3', { className: 'wporg-settings-tab__section-title' }, __( 'Location', 'wporg-groups-frontend' ) ),
			h( RadioControl, {
				label: __( 'Where is this group based?', 'wporg-groups-frontend' ),
				help: __( 'This appears in the group header. Individual event venues and online access details are managed with each event.', 'wporg-groups-frontend' ),
				selected: form.locationType,
				disabled: loadFailed,
				options: [
					{ label: __( 'In person', 'wporg-groups-frontend' ), value: 'physical' },
					{ label: __( 'Online', 'wporg-groups-frontend' ), value: 'online' },
				],
				onChange: ( v ) => {
					setLocationDirty( true );
					setForm( { ...form, locationType: v } );
				},
			} ),
			'physical' === form.locationType &&
				h(
					'div',
					{ className: 'wporg-settings-tab__location-fields' },
					h( TextControl, {
						label: __( 'City', 'wporg-groups-frontend' ),
						value: form.city,
						onChange: ( v ) => {
							setLocationDirty( true );
							setForm( { ...form, city: v } );
						},
						disabled: loadFailed,
						required: true,
						__nextHasNoMarginBottom: true,
					} ),
					h( SelectControl, {
						label: __( 'Country', 'wporg-groups-frontend' ),
						value: form.countryCode,
						options: [
							{ label: __( 'Select a country', 'wporg-groups-frontend' ), value: '' },
						].concat(
							countries.map( ( country ) => ( {
								label: country.name,
								value: country.code,
							} ) )
						),
						onChange: ( v ) => {
							setLocationDirty( true );
							setForm( { ...form, countryCode: v } );
						},
						disabled: loadFailed,
						required: true,
						__nextHasNoMarginBottom: true,
					} )
				),
			'' === form.locationType &&
				h(
					'p',
					{ className: 'wporg-settings-tab__empty' },
					__( 'No location specified.', 'wporg-groups-frontend' )
				),
			'' !== form.locationType &&
				h(
					Button,
					{
						variant: 'link',
						isDestructive: true,
						disabled: loadFailed,
						onClick: () => {
							setLocationDirty( true );
							setForm( {
								...form,
								locationType: '',
								city: '',
								countryCode: '',
							} );
						},
					},
					__( 'Clear location', 'wporg-groups-frontend' )
				)
		),
		h(
			'div',
			{ className: 'wporg-settings-tab__actions' },
			h(
				Button,
				{
					variant: 'primary',
					onClick: handleSave,
					isBusy: saving,
					disabled: saving || loadFailed || '' === form.blogname.trim() || physicalLocationIsIncomplete,
				},
				__( 'Save', 'wporg-groups-frontend' )
			)
		)
	);
}
