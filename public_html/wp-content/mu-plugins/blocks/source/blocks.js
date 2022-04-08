/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType, registerBlockVariation } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import './_z-index.scss'; // Have z-index values, similar to https://github.com/WordPress/gutenberg/blob/master/assets/stylesheets/_z-index.scss
import './edit.scss'; // Common styles for WordCamp Blocks.
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

/*
 * Register the custom taxonomies as variations on Post Terms.
 * See https://github.com/WordPress/gutenberg/blob/41325b983feabcc46615cd6b1a8a5efe64c5a9f0/packages/block-library/src/post-terms/variations.js.
 */
registerBlockVariation( 'core/post-terms', {
	name: 'wcb_track',
	title: __( 'Session Tracks', 'wordcamporg' ),
	description: __( "Display a session's tracks.", 'wordcamporg' ),
	category: 'wordcamp',
	attributes: { term: 'wcb_track' },
	isActive: ( blockAttributes ) => blockAttributes.term === 'wcb_track',
} );

registerBlockVariation( 'core/post-terms', {
	name: 'wcb_session_category',
	title: __( 'Session Categories', 'wordcamporg' ),
	description: __( "Display a session's categories.", 'wordcamporg' ),
	category: 'wordcamp',
	attributes: { term: 'wcb_session_category' },
	isActive: ( blockAttributes ) => blockAttributes.term === 'wcb_session_category',
} );

registerBlockVariation( 'core/post-terms', {
	name: 'wcb_organizer_team',
	title: __( 'Organizer Teams', 'wordcamporg' ),
	description: __( "Display an organizer's teams.", 'wordcamporg' ),
	category: 'wordcamp',
	attributes: { term: 'wcb_organizer_team' },
	isActive: ( blockAttributes ) => blockAttributes.term === 'wcb_organizer_team',
} );
