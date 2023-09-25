/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';

/**
 * Add a fake preview for the editor.
 */
function Edit() {
	return (
		<div { ...useBlockProps() }>
			<span>
				<a href="#">Home</a> { /* eslint-disable-line jsx-a11y/anchor-is-valid */ }
			</span>
			<span className="is-current-page">Current Page</span>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
