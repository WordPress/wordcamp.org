/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { RadioControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import usePostMeta from '../components/hooks/use-post-meta';
import UsernameControl from '../components/username-control';

export default function OrganizerInfoPanel() {
	const [ username, setUsername ] = usePostMeta( '_wcpt_user_name', '' );
	const [ firstTime, setFirstTime ] = usePostMeta( '_wcb_organizer_first_time', false );

	return (
		<PluginDocumentSettingPanel
			name="wordcamp/organizer-info"
			className="wc-panel-organizer-info"
			title={ __( 'Organizer Info', 'wordcamporg' ) }
		>
			<UsernameControl
				label={ __( 'WordPress.org Username', 'wordcamporg' ) }
				value={ username }
				onChange={ setUsername }
			/>
			<RadioControl
				label={ __( 'Is this their first time being an organizer at a WordPress event?', 'wordcamporg' ) }
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
