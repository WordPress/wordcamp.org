/**
 * Group Settings — Design tab.
 *
 * How this group writes its event dates and times, and a link to the Full Site
 * Editor for everything else.
 *
 * @package WordCamp\Groups\Frontend
 */

import { createElement as h, useState, useEffect } from '@wordpress/element';
import { Button, RadioControl, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * The "leave it alone" choice, offered first in both lists.
 *
 * An empty value means the templates and GatherPress keep their own formats,
 * which is what every group gets until someone chooses otherwise. Offering it
 * as a real option is what makes the choice reversible.
 */
const THEME_DEFAULT = '';

/**
 * Turn the REST choices into RadioControl options.
 *
 * The example is the label: the point of this setting is that an organizer
 * recognizes "Tuesday, September 29" rather than reading `l, F j`.
 *
 * @param {Array}  choices     Choices from the REST payload.
 * @param {string} defaultText Label for the leave-it-alone option.
 * @return {Array} RadioControl options.
 */
function toOptions( choices, defaultText ) {
	return [ { label: defaultText, value: THEME_DEFAULT } ].concat(
		( choices || [] ).map( ( choice ) => ( {
			label: choice.example,
			value: choice.format,
		} ) )
	);
}

export default function DesignTab() {
	const editorUrl = window.wporgGroupsEventModal?.siteEditorUrl || '/wp-admin/site-editor.php';

	const [ loading, setLoading ] = useState( true );
	const [ loadFailed, setLoadFailed ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( '' );
	const [ noticeType, setNoticeType ] = useState( 'success' );
	const [ dateChoices, setDateChoices ] = useState( [] );
	const [ timeChoices, setTimeChoices ] = useState( [] );
	const [ form, setForm ] = useState( {
		dateFormat: THEME_DEFAULT,
		timeFormat: THEME_DEFAULT,
	} );

	useEffect( () => {
		apiFetch( { path: '/wporg-groups/v1/group-info' } )
			.then( ( data ) => {
				setDateChoices( data.dateChoices || [] );
				setTimeChoices( data.timeChoices || [] );
				setForm( {
					dateFormat: data.dateFormat || THEME_DEFAULT,
					timeFormat: data.timeFormat || THEME_DEFAULT,
				} );
				setLoading( false );
			} )
			.catch( ( err ) => {
				// Prevent a failed read from being saved back as "no choice".
				setLoadFailed( true );
				setNoticeType( 'error' );
				setNotice(
					err.message || __( 'Could not load group settings.', 'wporg-groups-frontend' )
				);
				setLoading( false );
			} );
	}, [] );

	const handleSave = async () => {
		setSaving( true );
		setNotice( '' );
		try {
			await apiFetch( {
				path: '/wporg-groups/v1/group-info',
				method: 'POST',
				data: {
					date_format: form.dateFormat,
					time_format: form.timeFormat,
				},
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

		h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Date and time format', 'wporg-groups-frontend' )
		),
		h( 'p', {},
			__(
				'How dates and times are written on your event pages, in the events list, and in the emails your members receive.',
				'wporg-groups-frontend'
			)
		),

		h( RadioControl, {
			label: __( 'Date format', 'wporg-groups-frontend' ),
			selected: form.dateFormat,
			options: toOptions(
				dateChoices,
				__( 'Use this site’s design default', 'wporg-groups-frontend' )
			),
			onChange: ( value ) => setForm( { ...form, dateFormat: value } ),
		} ),

		h( RadioControl, {
			label: __( 'Time format', 'wporg-groups-frontend' ),
			selected: form.timeFormat,
			options: toOptions(
				timeChoices,
				__( 'Use this site’s design default', 'wporg-groups-frontend' )
			),
			onChange: ( value ) => setForm( { ...form, timeFormat: value } ),
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
					disabled: saving || loadFailed,
				},
				__( 'Save', 'wporg-groups-frontend' )
			)
		),

		h( 'h3', { className: 'wporg-settings-tab__section-title' },
			__( 'Everything else', 'wporg-groups-frontend' )
		),
		h( 'p', {},
			__( 'Use the WordPress Site Editor to customise your group site — change colors, fonts, the hero image, page layouts, and more.', 'wporg-groups-frontend' )
		),
		h(
			Button,
			{
				variant: 'secondary',
				href: editorUrl,
				target: '_self',
			},
			__( 'Open Site Editor', 'wporg-groups-frontend' )
		)
	);
}
