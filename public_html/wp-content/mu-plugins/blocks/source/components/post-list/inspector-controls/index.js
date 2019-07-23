/**
 * WordPress dependencies
 */
import { PanelBody }           from '@wordpress/components';
import { Component, Fragment } from '@wordpress/element';
import { __ }                  from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import GridColumnsControl from './grid-columns-control';

/**
 * Component to add an Inspector panel
 *
 * Should be used with rest of the components in this folder. Will use and set attributes `layout` and
 * `grid_columns`.
 */
export class GridInspectorPanel extends Component {
	/**
	 * Render the control.
	 *
	 * @return {Element}
	 */
	render() {
		const { attributes, setAttributes, blockData } = this.props;
		const { layout, grid_columns } = attributes;
		const { schema } = blockData;

		return (
			<Fragment>
				{ 'grid' === layout &&
					<PanelBody
						title={ __( 'Grid Layout', 'wordcamporg' ) }
						initialOpen={ true }
					>
						<GridColumnsControl
							grid_columns={ grid_columns }
							schema={ schema.grid_columns || {} }
							setAttributes={ setAttributes }
						/>
					</PanelBody>
				}
			</Fragment>
		);
	}
}
