/**
 * External dependencies
 */
const l10n = WordCampBlocks.l10n || {};

/**
 * WordPress dependencies
 */
const { setLocaleData } = wp.i18n;
const { registerBlockType } = wp.blocks;

setLocaleData( l10n, 'wordcamporg' );

/**
 * Internal dependencies
 */
import * as speakers from './speakers/';

[
	speakers,
	//
].forEach( ( { name, settings } ) => {
	registerBlockType( name, settings );
} );
