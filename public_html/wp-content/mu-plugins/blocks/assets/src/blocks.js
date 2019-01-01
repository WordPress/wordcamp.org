/**
 * WordPress dependencies
 */
const { registerBlockType } = wp.blocks;

// need to run linter across all files

/**
 * Internal dependencies
 */
import * as speakers from './speakers/';

[
	speakers,
].forEach( ( { name, settings } ) => {
	registerBlockType( name, settings );
} );
