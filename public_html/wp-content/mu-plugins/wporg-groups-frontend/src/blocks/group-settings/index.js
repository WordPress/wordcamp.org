/**
 * Block registration + styles.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import './style.scss';

registerBlockType( metadata.name, {
	edit: () => null,
	save: () => null,
} );
