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
		const help = liveUpdateEnabled ?
			__( 'The block will automatically reload every minute to fetch new posts.', 'wordcamporg' ) :
			__( 'The block will not update content until the page is reloaded.', 'wordcamporg' );

		const orderDateDesc = 'desc' === order && 'date' === orderBy;
		const orderWarning = __( 'Live update only works with "Order by: Newest to Oldest".', 'wordcamporg' );

		return (
			<Fragment>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody
						title="Front-end Display"
						initialOpen={ true }
					>
						{ ! orderDateDesc && (
							<p>{ orderWarning }</p>
						) }
						<ToggleControl
							label={ liveUpdateEnabled ?
								__( 'Live update on', 'wordcamporg' ) :
								__( 'Live update off', 'wordcamporg' )
							}
							help={ help }
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
