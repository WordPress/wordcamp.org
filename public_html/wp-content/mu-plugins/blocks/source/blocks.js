/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import './_z-index.scss';           // Have z-index values, similar to https://github.com/WordPress/gutenberg/blob/master/assets/stylesheets/_z-index.scss
import './styles.scss';               // Common styles for WordCamp Blocks.
import { BLOCKS } from './blocks/'; // Trailing slash required to differentiate the folder from the file.

BLOCKS.forEach( ( { NAME, SETTINGS } ) => {
	registerBlockType( NAME, SETTINGS );
} );
