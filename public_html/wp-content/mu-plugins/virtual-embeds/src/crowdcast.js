/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import EditPlaceholder from './components/edit-placeholder.js';

export const name = 'wordcamp/crowdcast-embed';

export const settings = {
	title: __( 'CrowdCast', 'wordcamporg' ),
	icon: 'format-video',
	category: 'embed',
	supports: {
		align: [ 'wide', 'full' ],
	},
	attributes: {
		channel: {
			type: 'string',
			default: '',
		},
	},
	edit: ( { attributes, setAttributes } ) => (
		<EditPlaceholder
			className="wc-block__crowdcast-embed"
			icon="format-video"
			label={ __( 'CrowdCast Event', 'wordcamporg' ) }
			instructions={ __( 'Enter the channel name to embed a stream on your site.', 'wordcamporg' ) }
			value={ attributes.channel }
			onChange={ ( newValue ) => setAttributes( { channel: newValue } ) }
		/>
	),
	// Block is rendered dynamically to inject the iframe, no need to save anything.
	save: () => null,
};
