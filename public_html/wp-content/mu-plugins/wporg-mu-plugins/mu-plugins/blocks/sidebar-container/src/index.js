/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';

function Edit() {
	return (
		<div { ...useBlockProps() }>
			<InnerBlocks />
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => {
		return <InnerBlocks.Content />;
	},
} );
