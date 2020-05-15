/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import './_z-index.scss'; // Have z-index values, similar to https://github.com/WordPress/gutenberg/blob/master/assets/stylesheets/_z-index.scss
import './styles.scss'; // Common styles for WordCamp Blocks.
import { BLOCKS } from './blocks/'; // Trailing slash required to differentiate the folder from the file.
// This attaches to the hook itself.
import './hooks/latest-posts';

/*
 * When a block isn't enabled on the current site, it won't be registered by it's `controller.php`.
 * See also `blocks.php`.
 */
const enabledBlocks = BLOCKS.filter( ( block ) =>
	window.WordCampBlocks.hasOwnProperty( block.NAME.replace( 'wordcamp/', '' ) )
);

enabledBlocks.forEach( ( { NAME, SETTINGS } ) => {
	registerBlockType( NAME, SETTINGS );
} );
