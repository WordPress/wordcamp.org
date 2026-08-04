/**
 * Block registration + styles.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import 'leaflet/dist/leaflet.css';
import './style.css';

registerBlockType( metadata.name, {
	edit: () => null,
	save: () => null,
} );
