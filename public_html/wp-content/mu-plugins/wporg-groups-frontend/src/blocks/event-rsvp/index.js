/**
 * Block registration + styles.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: () => null,
	save: () => null,
} );
