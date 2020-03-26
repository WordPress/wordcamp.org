/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import EditPlaceholder from './components/edit-placeholder.js';

export const name = 'wordcamp/streamtext-embed';

export const settings = {
	title: __( 'StreamText', 'wordcamporg' ),
	icon: 'text',
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
			className="wc-block__streamtext-embed"
			icon="text"
			label={ __( 'StreamText Event', 'wordcamporg' ) }
			instructions={ __( 'Enter the event name to embed the captions on your site.', 'wordcamporg' ) }
			value={ attributes.channel }
			onChange={ ( newValue ) => setAttributes( { channel: newValue } ) }
		/>
	),
	// Save the block to the streamtext shortcode.
	save: ( props ) => {
		const { channel = '', align } = props.attributes;

		const classes = [];
		if ( align ) {
			classes.push( `align${ align }` );
		}

		return (
			<div className={ classes.join( ' ' ) }>{ channel ? '[streamtext event="' + channel + '"]' : '' }</div>
		);
	},
};
