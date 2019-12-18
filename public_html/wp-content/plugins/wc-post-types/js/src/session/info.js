/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { getDate, gmdate } from '@wordpress/date';
import { BaseControl, SelectControl, TextControl } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { withDispatch, withSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import SessionDate from './date';
import SessionDuration from './duration';

function SessionSettings( {
	duration,
	slides,
	start,
	type,
	video,
	onChangeDuration,
	onChangeSlides,
	onChangeStartTime,
	onChangeType,
	onChangeVideo,
} ) {
	return (
		<PluginDocumentSettingPanel
			name="wordcamp/session-info"
			className="wc-panel-session-info"
			title={ __( 'Session Info', 'wordcamporg' ) }
		>
			<BaseControl>
				<BaseControl.VisualLabel>
					{ __( 'Day & Time', 'wordcamporg' ) }
				</BaseControl.VisualLabel>
				<SessionDate
					date={ start }
					onChange={ onChangeStartTime }
				/>
			</BaseControl>

			<SessionDuration value={ duration } onChange={ onChangeDuration } />

			<SelectControl
				label={ __( 'Session Type', 'wordcamporg' ) }
				value={ type }
				options={ [
					{ label: __( 'Regular Session', 'wordcamporg' ), value: 'session' },
					{ label: __( 'Break, Lunch, etc.', 'wordcamporg' ), value: 'custom' },
				] }
				onChange={ onChangeType }
			/>

			<TextControl
				label={ __( 'Link to slides', 'wordcamporg' ) }
				value={ slides }
				onChange={ onChangeSlides }
				placeholder="https://…"
			/>

			<TextControl
				label={ __( 'Link to video on WordPress.tv', 'wordcamporg' ) }
				value={ video }
				onChange={ onChangeVideo }
				placeholder="https://…"
			/>
		</PluginDocumentSettingPanel>
	);
}

export default compose( [
	withSelect( ( select ) => {
		const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		const start = getDate( meta._wcpt_session_time * 1000 );

		return {
			start: start,
			duration: meta._wcpt_session_duration || 0,
			type: meta._wcpt_session_type || '',
			slides: meta._wcpt_session_slides || '',
			video: meta._wcpt_session_video || '',
		};
	} ),
	withDispatch( ( dispatch ) => {
		function onChange( key, value ) {
			dispatch( 'core/editor' ).editPost( {
				meta: {
					[ key ]: value,
				},
			} );
		}

		return {
			onChangeStartTime( dateValue ) {
				const value = gmdate( 'U', dateValue );
				onChange( '_wcpt_session_time', value );
			},
			onChangeDuration( value ) {
				onChange( '_wcpt_session_duration', value );
			},
			onChangeType( value ) {
				onChange( '_wcpt_session_type', value );
			},
			onChangeSlides( value ) {
				onChange( '_wcpt_session_slides', value );
			},
			onChangeVideo( value ) {
				onChange( '_wcpt_session_video', value );
			},
		};
	} ),
] )( SessionSettings );
