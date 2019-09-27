/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Placeholder, TextControl } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { HeadingToolbar } from '../../components';

export const title = __( 'Live Schedule', 'wordcamporg' );
export const icon = 'excerpt-view';

registerBlockType( 'wordcamp/live-schedule', {
	title: title,
	description: __( 'Display a live schedule interface.', 'wordcamporg' ),
	icon: icon,
	category: 'wordcamp',
	supports: {
		align: [ 'wide', 'full' ],
	},
	edit: ( { attributes, setAttributes } ) => (
		<Fragment>
			<Placeholder icon={ icon } label={ title } />
			<InspectorControls>
				<PanelBody title={ __( 'Headings', 'wordcamporg' ) }>
					<TextControl
						label={ __( 'Current session header:', 'wordcamporg' ) }
						value={ attributes.now }
						onChange={ ( value ) => setAttributes( { now: value } ) }
					/>
					<TextControl
						label={ __( 'Next session header:', 'wordcamporg' ) }
						value={ attributes.next }
						onChange={ ( value ) => setAttributes( { next: value } ) }
					/>
					<p>{ __( 'Level' ) }</p>
					<HeadingToolbar
						isCollapsed={ false }
						minLevel={ 1 }
						maxLevel={ 7 }
						selectedLevel={ attributes.level }
						onChange={ ( newLevel ) => setAttributes( { level: newLevel } ) }
					/>
				</PanelBody>
			</InspectorControls>
		</Fragment>
	),
	save: () => <div id="day-of-event" />,
} );
