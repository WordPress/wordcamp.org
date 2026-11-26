/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Disabled, PanelBody, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';

const STATS = [
	{
		value: 'events',
		label: __( 'WordPress events since 2006', 'wordcamporg' ),
	},
	{
		value: 'groups',
		label: __( 'Local groups and countries', 'wordcamporg' ),
	},
	{ value: 'members', label: __( 'Meetup members', 'wordcamporg' ) },
];

export default function Edit( { attributes, setAttributes, name } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Stat', 'wordcamporg' ) }>
					<SelectControl
						label={ __( 'Which figure to show', 'wordcamporg' ) }
						value={ attributes.stat }
						options={ STATS }
						onChange={ ( stat ) => setAttributes( { stat } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<Disabled>
					<ServerSideRender
						block={ name }
						attributes={ attributes }
					/>
				</Disabled>
			</div>
		</>
	);
}
