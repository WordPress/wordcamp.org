/* global wporgGoogleMap */

/**
 * WordPress dependencies
 */
import { registerBlockStyle, registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';
import Main from './components/main';
import { getBlockStyle } from './utilities/map-styles';

function Edit( { attributes } ) {
	const { id, className } = attributes;

	return (
		<div { ...useBlockProps() }>
			<Main blockStyle={ getBlockStyle( className ) } { ...wporgGoogleMap[ id ] } />
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	supports: {
		align: [ 'wide', 'full' ],
	},
} );

registerBlockStyle( metadata.name, {
	name: 'wp20',
	label: 'WP20',
	isDefault: true,
} );

registerBlockStyle( metadata.name, {
	name: 'sotw-2023',
	label: 'State of the Word 2023',
} );
