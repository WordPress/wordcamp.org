/**
 * External dependencies
 */
import { get } from 'lodash';
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { PanelBody, RangeControl } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Component to add an Inspector panel
 *
 * Intended to be used with PostList component, it will use and set attributes `layout` and `grid_columns`.
 *
 * @param  root0
 * @param  root0.attributes
 * @param  root0.blockData
 * @param  root0.setAttributes
 * @return {Element}
 */
function GridInspectorPanel( { attributes, blockData, setAttributes } ) {
	const { grid_columns, layout } = attributes;
	const {
		default: defaultValue = 2,
		maximum = 4,
		minimum = 4,
	} = get( blockData, 'schema.grid_columns', {} );

	return (
		<Fragment>
			{ 'grid' === layout &&
				<PanelBody
					title={ __( 'Grid Layout', 'wordcamporg' ) }
					initialOpen={ true }
				>
					<RangeControl
						label={ __( 'Grid Columns', 'wordcamporg' ) }
						value={ Number( grid_columns ) }
						min={ minimum }
						max={ maximum }
						initialPosition={ defaultValue }
						onChange={ ( value ) => setAttributes( { grid_columns: value } ) }
					/>
				</PanelBody>
			}
		</Fragment>
	);
}

GridInspectorPanel.propTypes = {
	attributes: PropTypes.shape( {
		layout: PropTypes.string,
		grid_columns: PropTypes.number,
	} ).isRequired,
	blockData: PropTypes.shape( {
		schema: PropTypes.object,
	} ).isRequired,
	setAttributes: PropTypes.func.isRequired,
};

export default GridInspectorPanel;
