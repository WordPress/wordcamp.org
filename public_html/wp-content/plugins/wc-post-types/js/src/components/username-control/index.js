/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

export default function UsernameControl( { label, value, onChange } ) {
	const [ notice, setNotice ] = useState( false );

	function checkUsername() {
		setNotice( false );
		if ( ! value ) {
			return;
		}
		const path = `/wc-post-types/v1/validation?username=${ value }`;
		apiFetch( { path } ).catch( ( error ) => {
			if ( 'rest_invalid_param' === error.code ) {
				setNotice( __( 'Invalid username', 'wordcamporg' ) );
			}
		} );
	}

	return (
		<>
			<TextControl
				label={ label }
				value={ value }
				onChange={ onChange }
				onBlur={ checkUsername }
			/>
			{ notice && (
				<Notice isDismissible={ false } status="error">
					{ notice }
				</Notice>
			) }
		</>
	);
}
