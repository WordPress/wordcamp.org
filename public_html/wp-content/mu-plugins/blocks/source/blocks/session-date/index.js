/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';

export const NAME = metadata.name;

export const SETTINGS = {
	...metadata,
	icon: 'clock',
	edit: edit,
};
