/**
 * Featured image picker shared by the event modal and the group-settings
 * Events tab.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Featured image picker — opens the standard `wp.media` library frame
 * so the organizer can either upload a new image or pick an existing
 * one from the site's media library. The selected attachment id is
 * lifted up to the parent via `onChange` and the thumbnail URL is
 * displayed inline as a preview.
 */
export default function FeaturedImagePicker( { imageId, imageUrl, onChange, classPrefix } ) {
	const openMediaFrame = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Select a featured image', 'wporg-groups-frontend' ),
			button: { text: __( 'Use this image', 'wporg-groups-frontend' ) },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			const url = attachment.sizes && attachment.sizes.medium
				? attachment.sizes.medium.url
				: attachment.url;
			onChange( attachment.id, url );
		} );
		frame.open();
	};

	const handleRemove = () => {
		onChange( 0, '' );
	};

	return h(
		'div',
		{ className: `${ classPrefix }__field` },
		h(
			'label',
			{ className: 'components-base-control__label' },
			__( 'Featured image', 'wporg-groups-frontend' )
		),
		h(
			'div',
			{ className: `${ classPrefix }__featured` },
			imageId
				? h(
					'div',
					{ className: `${ classPrefix }__featured-preview` },
					h( 'img', { src: imageUrl, alt: '' } ),
					h(
						'div',
						{ className: `${ classPrefix }__featured-actions` },
						h(
							Button,
							{ variant: 'secondary', onClick: openMediaFrame },
							__( 'Replace', 'wporg-groups-frontend' )
						),
						h(
							Button,
							{ variant: 'tertiary', isDestructive: true, onClick: handleRemove },
							__( 'Remove', 'wporg-groups-frontend' )
						)
					)
				)
				: h(
					Button,
					{ variant: 'secondary', onClick: openMediaFrame },
					__( 'Choose featured image', 'wporg-groups-frontend' )
				)
		)
	);
}
