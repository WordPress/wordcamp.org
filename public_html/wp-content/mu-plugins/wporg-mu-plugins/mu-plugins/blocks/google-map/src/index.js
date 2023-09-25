/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { Placeholder } from '@wordpress/components';

/**
 * Internal dependencies
 */
import metadata from './block.json';

function Edit() {
	return (
		<Placeholder
			instructions={ __(
				'This is a placeholder for the editor. Data is supplied to this block via the pattern that includes it.',
				'wporg'
			) }
			label={ __( 'WordPress.org Google Map', 'wporg' ) }
		/>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
} );
