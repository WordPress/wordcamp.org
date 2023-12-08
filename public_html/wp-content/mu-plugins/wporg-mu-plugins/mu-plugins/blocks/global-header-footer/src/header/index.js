/**
 * WordPress dependencies
 */
import { Disabled } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';
import ServerSideRender from '@wordpress/server-side-render';
import { useBlockProps } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import metadata from './block.json';

const Edit = ( { attributes } ) => (
	<div { ...useBlockProps() }>
		<Disabled>
			<ServerSideRender block={ metadata.name } attributes={ attributes } skipBlockSupportAttributes />
		</Disabled>
	</div>
);

registerBlockType( metadata.name, {
	edit: Edit,
} );
