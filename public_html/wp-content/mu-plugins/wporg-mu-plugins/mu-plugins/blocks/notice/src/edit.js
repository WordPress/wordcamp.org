/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { BlockControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { ToolbarDropdownMenu } from '@wordpress/components';

/**
 * Internal dependencies
 */
import Icon from './icon';

export const typeOptions = [
	{ label: __( 'Tip', 'wporg' ), value: 'tip' },
	{ label: __( 'Information', 'wporg' ), value: 'info' },
	{ label: __( 'Alert', 'wporg' ), value: 'alert' },
	{ label: __( 'Warning', 'wporg' ), value: 'warning' },
];

/**
 * Get the human-readable label from the current type value.
 *
 * @param {string} type The type slug.
 * @return {string} The translated human-friendly label.
 */
function getOptionLabel( type ) {
	const currentType = typeOptions.find( ( { value } ) => type === value );
	return currentType.label || '';
}

export function Edit( { attributes, setAttributes } ) {
	const { content, type } = attributes;
	const className = `is-${ type }-notice`;

	return (
		<>
			<BlockControls group="block">
				<ToolbarDropdownMenu
					text={ sprintf(
						/* translators: %s is the notice type label. */
						__( 'Type: %s', 'wporg' ),
						getOptionLabel( type )
					) }
					icon={ false }
					controls={ typeOptions.map( ( { label, value } ) => ( {
						title: label,
						icon: <Icon type={ value } />,
						isActive: value === type,
						onClick: () => setAttributes( { type: value } ),
					} ) ) }
				/>
			</BlockControls>
			<div { ...useBlockProps( { className } ) }>
				<div className="wp-block-wporg-notice__icon" />
				<RichText
					tagName="div"
					multiline="p"
					className="wp-block-wporg-notice__content"
					onChange={ ( newContent ) => setAttributes( { content: newContent } ) }
					value={ content }
				/>
			</div>
		</>
	);
}
