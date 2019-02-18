/**
 * WordPress dependencies
 */
const { registerBlockType } = wp.blocks;

/**
 * Internal dependencies
 */
import * as sessions from './sessions/';
import * as speakers from './speakers/';

[
	sessions,
	speakers,
].forEach( ( { name, settings } ) => {
	registerBlockType( name, settings );
} );
