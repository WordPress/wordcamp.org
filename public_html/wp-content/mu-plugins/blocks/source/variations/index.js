/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import './sessions-list';

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
