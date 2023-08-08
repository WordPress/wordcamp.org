/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { RadioControl, TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import usePostMeta from '../components/hooks/use-post-meta';
import UsernameControl from '../components/username-control';

export default function SpeakerInfoPanel() {
	const [ email, setEmail ] = usePostMeta( '_wcb_speaker_email', '' );
	const [ username, setUsername ] = usePostMeta( '_wcpt_user_name', '' );
	const [ firstTime, setFirstTime ] = usePostMeta( '_wcb_speaker_first_time', false );

	return (
		<PluginDocumentSettingPanel
			name="wordcamp/speaker-info"
			className="wc-panel-speaker-info"
			title={ __( 'Speaker Info', 'wordcamporg' ) }
		>
			<TextControl
				label={ __( 'Gravatar Email', 'wordcamporg' ) }
				type="email"
				value={ email }
				onChange={ setEmail }
			/>
			<UsernameControl
				label={ __( 'WordPress.org Username', 'wordcamporg' ) }
				value={ username }
				onChange={ setUsername }
			/>
			<RadioControl
				label={ __( 'Is this their first time being a speaker at a WordPress event?', 'wordcamporg' ) }
				selected={ firstTime }
				onChange={ ( value ) => setFirstTime( value ) }
				options={ [
					{ label: 'Yes', value: 'yes' },
					{ label: 'No', value: 'no' },
					{ label: 'Unsure', value: 'unsure' },
				] }
			/>
		</PluginDocumentSettingPanel>
	);
}
