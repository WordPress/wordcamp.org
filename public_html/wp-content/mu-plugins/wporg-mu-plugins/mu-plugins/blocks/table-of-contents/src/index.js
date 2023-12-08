/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';

function Edit() {
	return <div { ...useBlockProps() }>Table of contents</div>;
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
