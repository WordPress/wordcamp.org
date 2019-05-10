/**
 * WordPress dependencies
 */
const { registerBlockType } = wp.blocks;

/**
 * Internal dependencies
 */
import { BLOCKS } from './block';

BLOCKS.forEach( ( { NAME, SETTINGS } ) => {
	registerBlockType( NAME, SETTINGS );
} );
