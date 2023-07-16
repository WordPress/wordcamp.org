/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Placeholder, TextControl } from '@wordpress/components';
import { BlockIcon } from '@wordpress/block-editor';

/**
 * Placeholder to gather the embed URL from the user before a preview is shown.
 *
 * `EditPlaceholder` can be used if no preview is desired. This is meant for situations where one is, to match
 * the UX of the Core embeds like YouTube.
 *
 * @param {Object}   props
 * @param {string}   props.classes
 * @param {string}   props.help
 * @param {Object}   props.icon         A `BlockIcon` or other value compatible with `<Icon />`.
 * @param {string}   props.instructions
 * @param {string}   props.label
 * @param {Function} props.embedHandler
 * @param {string}   props.placeholder
 * @return {Element}
 */
export default function( { classes, help, icon, instructions, label, embedHandler, placeholder } ) {
	let currentUrl = '';

	classes += ' is-preview-placeholder';

	return (
		<Placeholder
			className={ classes }
			icon={ <BlockIcon icon={ icon } showColors={ true } /> }
			label={ label }
			instructions={ instructions }
		>
			<TextControl
				hideLabelFromVision
				label={ label }
				help={ help }
				placeholder={ placeholder }
				onChange={ ( newUrl ) => currentUrl = newUrl }
			/>

			<Button
				isPrimary
				onClick={ () => embedHandler( currentUrl ) }
			>
				{ __( 'Embed', 'wordcamporg' ) }
			</Button>
		</Placeholder>
	);
}
