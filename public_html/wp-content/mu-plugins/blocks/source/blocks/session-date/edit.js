/**
 * External dependencies
 */
import classnames from 'classnames';
import { uniq } from 'lodash';

/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import { AlignmentControl, BlockControls, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { dateI18n, __experimentalGetSettings as getDateSettings } from '@wordpress/date'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';

export default function Edit( { attributes, setAttributes, context: { postId, postType } } ) {
	const { format, showTimezone, textAlign } = attributes;
	const date = useSelect( ( select ) => {
		const { getEntityRecord } = select( coreStore );
		const session = getEntityRecord( 'postType', postType, postId );
		return session.meta._wcpt_session_time * 1000; // Convert from s to ms.
	}, [] );

	const { formats } = getDateSettings();
	const defaultFormat = formats.datetime;

	const formatOptions = uniq( [
		...Object.values( formats ),
		_x( 'D g:i A', 'short weekday name with time', 'wordcamporg' ),
		_x( 'l g:i A', 'long weekday name with time', 'wordcamporg' ),
		_x( 'M j, Y g:i A', 'medium date format with time', 'wordcamporg' ),
		_x( 'n/j/Y g:i A', 'short date format with time', 'wordcamporg' ),
	] ).map( ( formatOption ) => ( {
		value: formatOption,
		label: dateI18n( formatOption, date || new Date() ),
	} ) );

	const blockProps = useBlockProps( {
		className: classnames( {
			[ `has-text-align-${ textAlign }` ]: textAlign,
		} ),
	} );

	const displayFormat = ( format || defaultFormat ) + ( showTimezone ? ' T' : '' );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'wordcamporg' ) }>
					<SelectControl
						label={ __( 'Date Format', 'wordcamporg' ) }
						value={ format || defaultFormat }
						options={ formatOptions }
						onChange={ ( value ) => setAttributes( { format: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show Timezone', 'wordcamporg' ) }
						checked={ showTimezone }
						onChange={ () => setAttributes( { showTimezone: ! showTimezone } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<BlockControls group="block">
				<AlignmentControl
					value={ textAlign }
					onChange={ ( nextAlign ) => {
						setAttributes( { textAlign: nextAlign } );
					} }
				/>
			</BlockControls>
			<div { ...blockProps }>
				<time dateTime={ dateI18n( 'c', date ) }>{ dateI18n( displayFormat, date ) }</time>
			</div>
		</>
	);
}
