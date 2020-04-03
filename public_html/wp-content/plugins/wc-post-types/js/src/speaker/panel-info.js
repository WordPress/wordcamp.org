/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import usePostMeta from '../components/hooks/use-post-meta';

export default function SpeakerInfoPanel() {
	const [ email, setEmail ] = usePostMeta( '_wcb_speaker_email', '' );
	const [ username, setUsername ] = usePostMeta( '_wcpt_user_name', '' );

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
			<TextControl
				label={ __( 'WordPress.org Username', 'wordcamporg' ) }
				value={ username }
				onChange={ setUsername }
			/>
		</PluginDocumentSettingPanel>
	);
}
