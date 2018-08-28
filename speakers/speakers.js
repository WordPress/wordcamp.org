// License: GPLv2+

var el = wp.element.createElement,
	registerBlockType = wp.blocks.registerBlockType,
	ServerSideRender = wp.components.ServerSideRender,
	InspectorControls = wp.editor.InspectorControls,
	PanelBody = wp.components.PanelBody,
	PanelRow = wp.components.PanelRow,
	CheckboxControl = wp.components.CheckboxControl,
	RangeControl = wp.components.RangeControl,
	TextControl = wp.components.TextControl,
	data = WordCampBlocksSpeakers;

registerBlockType( 'wordcamp/speakers', {
	title: data.l10n['block_label'],
	description: data.l10n['block_description'],
	icon: 'megaphone',
	category: 'wordcamp',

	edit: function( props ) {
		var showAvatars, avatarSize;

		showAvatars = el( PanelRow, {}, [
			el( CheckboxControl, {
				label: data.l10n['show_avatars_label'],
				checked: props.attributes['show_avatars'],
				onChange: ( value ) => { props.setAttributes( { 'show_avatars': value } ); },
			} ),
		] );

		avatarSize = el( PanelRow, {}, [
			el( RangeControl, {
				label: data.l10n['avatar_size_label'],
				value: props.attributes['avatar_size'],
				min: data.schema['avatar_size'].minimum,
				max: data.schema['avatar_size'].maximum,
				onChange: ( value ) => { props.setAttributes( { 'avatar_size': value } ); },
			} ),
		] );

		return [
			el( ServerSideRender, {
				block: 'wordcamp/speakers',
				attributes: props.attributes,
			} ),
			el( InspectorControls, {}, [
				el( PanelBody,
					{
						title: data.l10n['avatars_panel_title'],
					},
					[
						showAvatars,
						avatarSize,
					]
				),
			] ),
		];
	},

	save: function() {
		return null;
	},
} );
