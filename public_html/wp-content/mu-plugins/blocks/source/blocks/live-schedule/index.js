/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './edit.scss';
import { HeadingToolbar } from '../../components';
import LiveSchedule from './components/schedule';
import mockSessions from './data/mock';

const title = __( 'Live Schedule', 'wordcamporg' );
const icon = 'excerpt-view';

export const NAME = 'wordcamp/live-schedule';

export const SETTINGS = {
	title: title,
	description: __( 'Display a live schedule interface.', 'wordcamporg' ),
	icon: icon,
	category: 'wordcamp',
	supports: {
		align: [ 'wide', 'full' ],
	},
	attributes: {
		now: {
			type: 'string',
			default: __( 'On Now', 'wordcamporg' ),
		},
		next: {
			type: 'string',
			default: __( 'Next Up', 'wordcamporg' ),
		},
		level: {
			type: 'number',
			default: 2,
		},
	},
	edit: ( { attributes, setAttributes } ) => (
		<Fragment>
			<LiveSchedule
				config={ window.WordCampBlocks[ 'live-schedule' ] }
				sessions={ mockSessions }
				isFetching={ false }
				attributes={ attributes }
			/>
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
					<p>{ __( 'Level', 'wordcamporg' ) }</p>
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
	save: ( { attributes } ) => (
		<div
			data-now={ attributes.now }
			data-next={ attributes.next }
			data-level={ attributes.level }
		/>
	),
};
