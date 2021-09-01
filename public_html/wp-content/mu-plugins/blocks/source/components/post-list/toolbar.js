/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { Toolbar } from '@wordpress/components';
import { BlockControls } from '@wordpress/block-editor';

/**
 * Component for a toolbar UI to the top of a post list block to change the layout.
 *
 * @param  root0
 * @param  root0.layout
 * @param  root0.options
 * @param  root0.setAttributes
 * @return {Element}
 */
function LayoutToolbar( { layout, options, setAttributes } ) {
	const controls = options.map( ( option ) => {
		const icon = `${ option.value }-view`;
		const isActive = layout === option.value;

		return {
			icon: icon,
			title: option.label,
			isActive: isActive,
			onClick: () => {
				setAttributes( { layout: option.value } );
			},
		};
	} );

	return (
		<BlockControls>
			<Toolbar controls={ controls } />
		</BlockControls>
	);
}

LayoutToolbar.propTypes = {
	layout: PropTypes.string.isRequired,
	options: PropTypes.arrayOf(
		PropTypes.shape( {
			label: PropTypes.string,
			value: PropTypes.string,
		} )
	).isRequired,
	setAttributes: PropTypes.func.isRequired,
};

export default LayoutToolbar;
