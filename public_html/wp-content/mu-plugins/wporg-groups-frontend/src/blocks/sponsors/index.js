/**
 * Block registration + styles.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: ( { attributes, setAttributes } ) => {
		const blockProps = useBlockProps();

		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Settings', 'wporg-groups-frontend' ) }>
						<RangeControl
							label={ __( 'Sponsors shown', 'wporg-groups-frontend' ) }
							help={ __(
								'The rest are revealed by a "Show all" button. Set to 0 to always show every sponsor.',
								'wporg-groups-frontend'
							) }
							value={ attributes.limit }
							onChange={ ( limit ) => setAttributes( { limit } ) }
							min={ 0 }
							max={ 20 }
						/>
						<RangeControl
							label={ __( 'Heading level', 'wporg-groups-frontend' ) }
							value={ attributes.level }
							onChange={ ( level ) => setAttributes( { level } ) }
							min={ 1 }
							max={ 6 }
						/>
					</PanelBody>
				</InspectorControls>

				<ServerSideRender block={ metadata.name } attributes={ attributes } />
			</div>
		);
	},
	save: () => null,
} );
