/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';

const BLOCK_TYPE = 'core/navigation';
const menus = window.wporgLocalNavigationMenus || [];

/**
 * Add the `menuSlug` attribute to the core/navigation block type.
 *
 * @param {Object} settings The settings as defined in block.json.
 * @param {string} name     Current block type
 *
 * @return {Object}
 */
const addNavigationMenuSlugAttr = ( settings, name ) => {
	if ( name === BLOCK_TYPE ) {
		return { ...settings, attributes: { ...settings.attributes, menuSlug: { type: 'string' } } };
	}
	return settings;
};
addFilter( 'blocks.registerBlockType', 'wporg/navigation-menu-slug', addNavigationMenuSlugAttr );

/**
 * Inject a control for the `menuSlug` attribute in the editor.
 *
 * This is done by wrapping the existing edit function and adding
 * a new `InspectorControls` panel.
 *
 * @param {Function} BlockEdit
 *
 * @return {Function}
 */
const withNavigationMenuSlug = ( BlockEdit ) => ( props ) => {
	const { name, attributes, setAttributes } = props;

	if ( name !== BLOCK_TYPE ) {
		return <BlockEdit { ...props } />;
	}

	const options = Object.keys( menus ).map( ( value ) => {
		// Create label by converting hyphenated value to title case with ` — ` separator
		const label = value
			.replace( /-/g, ' — ' )
			.replace( /\w\S*/g, ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ).toLowerCase() );

		return { label, value };
	} );

	return (
		<>
			<InspectorControls group="list">
				<PanelBody className={ attributes.menuSlug ? 'wporg-nav-hide-next-panel' : '' }>
					<SelectControl
						label={ __( 'Dynamic Menu', 'wporg' ) }
						value={ attributes.menuSlug }
						options={ [ { label: __( 'Custom menu', 'wporg' ), value: '' }, ...options ] }
						onChange={ ( newValue ) => setAttributes( { menuSlug: newValue } ) }
						__nextHasNoMarginBottom
					/>
					{ attributes.menuSlug ? (
						<p>
							{ __(
								'This menu will display a hard-coded navigation, relative to the current site.',
								'wporg'
							) }
						</p>
					) : (
						<p>
							{ __(
								'This menu will display the content below. Note that on locale sites (for example, es.wordpress.org) this menu will probably not exist.',
								'wporg'
							) }
						</p>
					) }
				</PanelBody>
			</InspectorControls>
			<BlockEdit { ...props } />
		</>
	);
};
addFilter( 'editor.BlockEdit', 'wporg/navigation-menu-slug', withNavigationMenuSlug );
