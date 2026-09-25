/**
 * WordPress dependencies
 */

import { Disabled } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit( { attributes, name } ) {
	return (
		<div { ...useBlockProps() }>
			<Disabled>
				<ServerSideRender block={ name } attributes={ attributes } />
			</Disabled>
		</div>
	);
}
