/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import * as crowdcast from './crowdcast';
import * as streamtext from './streamtext';

[ crowdcast, streamtext ].forEach( ( block ) => {
	const { name, settings } = block;
	registerBlockType( name, settings );
} );
