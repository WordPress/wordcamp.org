/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createHigherOrderComponent } from '@wordpress/compose';
import { Fragment } from '@wordpress/element';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';

const withLiveReloadOption = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( props.name !== 'core/latest-posts' ) {
			return <BlockEdit { ...props } />;
		}

		const {
			liveUpdateEnabled,
			order,
			orderBy,
		} = props.attributes;

		const orderDateDesc = 'desc' === order && 'date' === orderBy;
		const orderWarning = __( 'Live update only works with "Order by: Newest to Oldest".', 'wordcamporg' );

		return (
			<Fragment>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody
						title={ __( 'Live Updates', 'wordcamporg' ) }
						initialOpen={ true }
					>
						<p>{ __( "This feature helps your attendees keep up-to-date with your WordCamp's latest news. When active, new posts will be loaded as they're published without your attendees needing to refresh the page.", 'wordcamporg' ) }</p>
						{ ! orderDateDesc && (
							<p>{ orderWarning }</p>
						) }
						<ToggleControl
							label={ __( 'Live update posts', 'wordcamporg' ) }
							checked={ liveUpdateEnabled }
							onChange={ ( value ) => props.setAttributes( { liveUpdateEnabled: value } ) }
						/>
					</PanelBody>
				</InspectorControls>
			</Fragment>
		);
	};
}, 'withLiveReloadOption' );

if ( !! window.WordCampBlocks[ 'latest-posts' ] ) {
	wp.hooks.addFilter( 'editor.BlockEdit', 'wordcamp/add-live-option-latest-posts', withLiveReloadOption );
}
