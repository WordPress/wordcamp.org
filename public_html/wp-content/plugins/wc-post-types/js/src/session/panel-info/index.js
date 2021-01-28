/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { getDate, gmdate } from '@wordpress/date';
import { SelectControl, TextControl } from '@wordpress/components';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';

/**
 * Internal dependencies
 */
import DateControl from '../../components/date-control';
import SessionDuration from './duration';
import usePostMeta from '../../components/hooks/use-post-meta';

export default function SessionSettings() {
	const [ time, setStartTime ] = usePostMeta( '_wcpt_session_time', WCPT_Session_Defaults.time );
	const [ duration, setDuration ] = usePostMeta( '_wcpt_session_duration', WCPT_Session_Defaults.duration );
	const [ slides, setSlides ] = usePostMeta( '_wcpt_session_slides', '' );
	const [ type, setType ] = usePostMeta( '_wcpt_session_type', '' );
	const [ video, setVideo ] = usePostMeta( '_wcpt_session_video', '' );

	const start = getDate( time * 1000 );

	return (
		<PluginDocumentSettingPanel
			name="wordcamp/session-info"
			className="wordcamp-panel-session-info"
			title={ __( 'Session Info', 'wordcamporg' ) }
		>
			<DateControl
				label={ __( 'Day & Time', 'wordcamporg' ) }
				date={ start }
				onChange={ ( dateValue ) => {
					const value = gmdate( 'U', dateValue );
					setStartTime( value );
				} }
			/>

			<SessionDuration value={ duration } onChange={ setDuration } />

			<SelectControl
				label={ __( 'Session Type', 'wordcamporg' ) }
				value={ type }
				options={ [
					{ label: __( 'Regular Session', 'wordcamporg' ), value: 'session' },
					{ label: __( 'Break, Lunch, etc.', 'wordcamporg' ), value: 'custom' },
				] }
				onChange={ setType }
			/>

			<TextControl
				label={ __( 'Link to slides', 'wordcamporg' ) }
				value={ slides }
				onChange={ setSlides }
				placeholder="https://…"
			/>

			<TextControl
				label={ __( 'Link to video on WordPress.tv', 'wordcamporg' ) }
				value={ video }
				onChange={ setVideo }
				placeholder="https://…"
			/>
		</PluginDocumentSettingPanel>
	);
}
