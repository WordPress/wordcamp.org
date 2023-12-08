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
			<InnerBlocks
				template={ [
					[ 'wporg/site-breadcrumbs' ],
					[
						'core/navigation',
						{
							openSubmenusOnClick: true,
							overlayMenu: 'never',
							overlayBackgroundColor: 'blueberry-1',
							overlayTextColor: 'white',
							layout: { type: 'flex', justifyContent: 'right' },
						},
					],
				] }
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => {
		return <InnerBlocks.Content />;
	},
} );
