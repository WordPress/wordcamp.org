// License: GPLv2+

var el = wp.element.createElement,
	registerBlockType = wp.blocks.registerBlockType,
	ServerSideRender = wp.components.ServerSideRender,
	InspectorControls = wp.editor.InspectorControls,
	PanelBody = wp.components.PanelBody,
	PanelRow = wp.components.PanelRow,
	CheckboxControl = wp.components.CheckboxControl,
	RangeControl = wp.components.RangeControl,
	SelectControl = wp.components.SelectControl,
	TextControl = wp.components.TextControl,
	data = WordCampBlocksSpeakers;

registerBlockType( 'wordcamp/speakers', {
	title: data.l10n['block_label'],
	description: data.l10n['block_description'],
	icon: 'megaphone',
	category: 'wordcamp',

	edit: function( props ) {
		var speakerControls = [];

		speakerControls.push(
			el( PanelRow, {}, [
				el( CheckboxControl, {
					label: data.l10n['show_all_posts_label'],
					checked: props.attributes['show_all_posts'],
					onChange: ( value ) => { props.setAttributes( { 'show_all_posts': value } ); },
				} ),
			] )
		);

		if ( ! props.attributes['show_all_posts'] ) {
			speakerControls.push(
				//el( PanelRow, {}, [
					el( RangeControl, {
						label: data.l10n['posts_per_page_label'],
						value: props.attributes['posts_per_page'],
						min: data.schema['posts_per_page'].minimum,
						max: data.schema['posts_per_page'].maximum,
						initialPosition: data.schema['posts_per_page'].default,
						allowReset: true,
						onChange: ( value ) => { props.setAttributes( { 'posts_per_page': value } ); },
					} ),
				//] )
			);
		}

		speakerControls.push(
			//el( PanelRow, {}, [
				el( SelectControl, {
					label: data.l10n['track_label'],
					help: data.l10n['track_help'],
					value: props.attributes['track'],
					options: data.options['track'],
					multiple: true,
					onChange: ( value ) => { props.setAttributes( { 'track': value } ); },
				} ),
			//] )
		);

		speakerControls.push(
			//el( PanelRow, {}, [
				el( SelectControl, {
					label: data.l10n['groups_label'],
					help: data.l10n['groups_help'],
					value: props.attributes['groups'],
					options: data.options['groups'],
					multiple: true,
					onChange: ( value ) => { props.setAttributes( { 'groups': value } ); },
				} ),
			//] )
		);

		speakerControls.push(
			//el( PanelRow, {}, [
				el( SelectControl, {
					label: data.l10n['sort_label'],
					value: props.attributes['sort'],
					options: data.options['sort'],
					onChange: ( value ) => { props.setAttributes( { 'sort': value } ); },
				} ),
			//] )
		);

		speakerControls.push(
			el( PanelRow, {}, [
				el( CheckboxControl, {
					label: data.l10n['speaker_link_label'],
					help: data.l10n['speaker_link_help'],
					checked: props.attributes['speaker_link'],
					onChange: ( value ) => { props.setAttributes( { 'speaker_link': value } ); },
				} ),
			] )
		);

		speakerControls.push(
			el( PanelRow, {}, [
				el( CheckboxControl, {
					label: data.l10n['show_avatars_label'],
					checked: props.attributes['show_avatars'],
					onChange: ( value ) => { props.setAttributes( { 'show_avatars': value } ); },
				} ),
			] )
		);

		if ( props.attributes['show_avatars'] ) {
			speakerControls.push(
				//el( PanelRow, {}, [
					el( RangeControl, {
						label: data.l10n['avatar_size_label'],
						help: data.l10n['avatar_size_help'],
						value: props.attributes['avatar_size'],
						min: data.schema['avatar_size'].minimum,
						max: data.schema['avatar_size'].maximum,
						initialPosition: data.schema['avatar_size'].default,
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
						title: data.l10n['speaker_posts_panel_title'],
					},
					speakerControls
				),
			] ),
		];
	},

	save: function() {
		return null;
	},
} );
