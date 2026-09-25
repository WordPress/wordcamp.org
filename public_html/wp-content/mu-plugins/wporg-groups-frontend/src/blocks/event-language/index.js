import { registerBlockType } from '@wordpress/blocks';
import ServerSideRender from '@wordpress/server-side-render';
import { useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: ( { context } ) => {
		const blockProps = useBlockProps();
		return wp.element.createElement(
			'div',
			blockProps,
			wp.element.createElement( ServerSideRender, {
				block: metadata.name,
				attributes: {},
				urlQueryArgs: { post_id: context.postId },
			} )
		);
	},
	save: () => null,
} );
