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
	TextareaControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export default function AboutTab() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( '' );
	const [ form, setForm ] = useState( {
		blogname: '',
		blogdescription: '',
	} );

	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( data ) => {
				setForm( {
					blogname: data.title || '',
					blogdescription: data.description || '',
				} );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	const handleSave = async () => {
		setSaving( true );
		setNotice( '' );
		try {
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					title: form.blogname,
					description: form.blogdescription,
				},
			} );
			setNotice( __( 'Settings saved.', 'wporg-groups-frontend' ) );
		} catch ( err ) {
			setNotice( err.message || __( 'Could not save settings.', 'wporg-groups-frontend' ) );
		} finally {
			setSaving( false );
		}
	};

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
					status: 'success',
					isDismissible: true,
					onDismiss: () => setNotice( '' ),
				},
				notice
			),
		h( TextControl, {
			label: __( 'Group name', 'wporg-groups-frontend' ),
			value: form.blogname,
			onChange: ( v ) => setForm( { ...form, blogname: v } ),
			__nextHasNoMarginBottom: true,
		} ),
		h( TextareaControl, {
			label: __( 'Description', 'wporg-groups-frontend' ),
			value: form.blogdescription,
			onChange: ( v ) => setForm( { ...form, blogdescription: v } ),
			rows: 3,
			help: __( 'A short description of your group shown on the homepage.', 'wporg-groups-frontend' ),
			__nextHasNoMarginBottom: true,
		} ),
		h(
			'div',
			{ className: 'wporg-settings-tab__actions' },
			h(
				Button,
				{
					variant: 'primary',
					onClick: handleSave,
					isBusy: saving,
					disabled: saving,
				},
				__( 'Save', 'wporg-groups-frontend' )
			)
		)
	);
}
