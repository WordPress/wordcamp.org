/**
 * External dependencies
 */
const l10n = WordCampBlocks.l10n || {};

/**
 * WordPress dependencies
 */
const { setLocaleData } = wp.i18n;

setLocaleData( l10n, 'wordcamporg' );

/**
 * Internal dependencies
 */
import speakers from './speakers';
