// todo Deprecate this
var el = wp.element.createElement;

/**
 * External dependencies
 */
const data = WordCampBlocks.speakers || {};

/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;
const { InspectorControls } = wp.editor;
const { ServerSideRender, PanelBody, PanelRow, CheckboxControl, RangeControl, SelectControl, TextControl } = wp.components;

const supports = {
	className: false,
};

/* todo We might be able to use this instead of the PHP version once this becomes fully native JS instead of SSR.
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
	'track': {
		type: 'array',
		items: {
			type: 'string',
			enum: data.options.track, //todo
		}
	},
	'groups': {
		type: 'array',
		items: {
			type: 'string',
			enum: data.options.groups, //todo
		}
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
*/

const schema = data.schema;

registerBlockType( 'wordcamp/speakers', {
	title: __( 'Speakers', 'wordcamporg' ),
	description: __( 'Add a list of speakers.', 'wordcamporg' ),
	icon: 'megaphone',
	category: 'wordcamp',
	attributes: schema,
	supports,

	edit: function( props ) {
		var whichControls = [],
			displayControls = [];

		if ( data.options['track'].length > 1 ) {
			whichControls.push(
				// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
				//el( PanelRow, {}, [
					el( SelectControl, {
						label: __( 'From which session tracks?', 'wordcamporg' ),
						value: props.attributes['track'],
						options: data.options['track'],
						multiple: true,
						onChange: ( value ) => { props.setAttributes( { 'track': value } ); },
					} ),
				//] )
			);
		}

		if ( data.options['groups'].length > 1 ) {
			whichControls.push(
				// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
				//el( PanelRow, {}, [
					el( SelectControl, {
						label: __( 'From which speaker groups?', 'wordcamporg' ),
						value: props.attributes['groups'],
						options: data.options['groups'],
						multiple: true,
						onChange: ( value ) => { props.setAttributes( { 'groups': value } ); },
					} ),
				//] )
			);
		}

		whichControls.push(
			el( PanelRow, {}, [
				el( CheckboxControl, {
					label: __( 'Show all', 'wordcamporg' ),
					checked: props.attributes['show_all_posts'],
					onChange: ( value ) => { props.setAttributes( { 'show_all_posts': value } ); },
				} ),
			] )
		);

		if ( ! props.attributes['show_all_posts'] ) {
			whichControls.push(
				// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
				//el( PanelRow, {}, [
					el( RangeControl, {
						label: __( 'Number to show', 'wordcamporg' ),
						value: props.attributes['posts_per_page'],
						min: schema['posts_per_page'].minimum,
						max: schema['posts_per_page'].maximum,
						initialPosition: schema['posts_per_page'].default,
						allowReset: true,
						onChange: ( value ) => { props.setAttributes( { 'posts_per_page': value } ); },
					} ),
				//] )
			);
		}

		whichControls.push(
			// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
			//el( PanelRow, {}, [
				el( SelectControl, {
					label: __( 'Sort by', 'wordcamporg' ),
					value: props.attributes['sort'],
					options: data.options['sort'],
					onChange: ( value ) => { props.setAttributes( { 'sort': value } ); },
				} ),
			//] )
		);

		displayControls.push(
			el( PanelRow, {}, [
				el( CheckboxControl, {
					label: __( 'Link titles to single posts', 'wordcamporg' ),
					help: __( 'These will not appear in the block preview.', 'wordcamporg' ),
					checked: props.attributes['speaker_link'],
					onChange: ( value ) => { props.setAttributes( { 'speaker_link': value } ); },
				} ),
			] )
		);

		displayControls.push(
			el( PanelRow, {}, [
				el( CheckboxControl, {
					label: __( 'Show avatars', 'wordcamporg' ),
					checked: props.attributes['show_avatars'],
					onChange: ( value ) => { props.setAttributes( { 'show_avatars': value } ); },
				} ),
			] )
		);

		if ( props.attributes['show_avatars'] ) {
			displayControls.push(
				// Some controls currently have broken layout within a PanelRow. See https://github.com/WordPress/gutenberg/pull/4564
				//el( PanelRow, {}, [
					el( RangeControl, {
						label: __( 'Avatar size (px)', 'wordcamporg' ),
						help: __( 'Height and width in pixels.', 'wordcamporg' ),
						value: props.attributes['avatar_size'],
						min: schema['avatar_size'].minimum,
						max: schema['avatar_size'].maximum,
						initialPosition: schema['avatar_size'].default,
						allowReset: true,
						onChange: ( value ) => { props.setAttributes( { 'avatar_size': value } ); },
					} ),
				//] )
			);
		}

		return [
			el( ServerSideRender, {
				block: 'wordcamp/speakers',
				attributes: props.attributes,
			} ),
			el( InspectorControls, {}, [
				el( PanelBody,
					{
						title: __( 'Which Speakers?', 'wordcamporg' ),
						initialOpen: true,
					},
					whichControls
				),
				el( PanelBody,
					{
						title: __( 'Speaker Display', 'wordcamporg' ),
						initialOpen: false,
					},
					displayControls
				),
			] ),
		];
	},

	save: function() {
		return null;
	},
} );
