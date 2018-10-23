/**
 * External dependencies
 */
const data = WordCampBlocks.speakers || {};

/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import edit from './edit.jsx';

const supports = {
	className: false,
};

const schema = {
	'show_all_posts': {
		type: 'boolean',
		default: true,
	},
	'posts_per_page': {
		type: 'integer',
		minimum: 1,
		maximum: 100,
		default: 10,
	},
	'sort': {
		type: 'string',
		enum: data.options.sort, //todo
		default: 'title_asc',
	},
	'speaker_link': {
		type: 'boolean',
		default: false,
	},
	'show_avatars': {
		type: 'boolean',
		default: true,
	},
	'avatar_size': {
		type: 'integer',
		minimum: 64,
		maximum: 512,
		default: 100,
	},
};

export const name = 'wordcamp/speakers';

export const settings = {
	title: __( 'Speakers', 'wordcamporg' ),
	description: __( 'Add a list of speakers.', 'wordcamporg' ),
	icon: 'megaphone',
	category: 'wordcamp',
	supports: supports,
	attributes: schema,
	edit,
	save: function() {
		return null;
	},
};
