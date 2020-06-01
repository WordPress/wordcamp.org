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

export default function OrganizerInfoPanel() {
	const [ username, setUsername ] = usePostMeta( '_wcpt_user_name', '' );

	return (
		<PluginDocumentSettingPanel
			name="wordcamp/organizer-info"
			className="wc-panel-organizer-info"
			title={ __( 'Organizer Info', 'wordcamporg' ) }
		>
			<TextControl
				label={ __( 'WordPress.org Username', 'wordcamporg' ) }
				value={ username }
				onChange={ setUsername }
			/>
		</PluginDocumentSettingPanel>
	);
}
