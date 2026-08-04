import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: () => null,
	save: () => null,
} );
