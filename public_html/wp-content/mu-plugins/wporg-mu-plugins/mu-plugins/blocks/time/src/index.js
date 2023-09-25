/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { RichTextToolbarButton } from '@wordpress/block-editor';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { getTextContent, registerFormatType, removeFormat, slice, toggleFormat } from '@wordpress/rich-text';

/**
 * External dependencies
 */
import { gmdate, strtotime } from 'locutus/php/datetime';

/**
 * Internal dependencies
 */
import metadata from './block.json';

const { name, icon, title } = metadata;

// Find all the time elements in the block content, and return their text content as an array.
const getTimesFromContent = ( content ) => {
	const tempElement = document.createElement( 'div' );
	tempElement.innerHTML = content;

	const timeElements = tempElement.querySelectorAll( '.wporg-time' );

	return timeElements.length ? Array.from( timeElements ).map( ( timeElement ) => timeElement.textContent ) : [];
};

const Edit = ( { isActive, onChange, value } ) => {
	const { date_gmt } = useSelect( ( select ) => select( 'core/editor' ).getCurrentPost() );
	const {
		attributes: { content },
	} = useSelect( ( select ) => select( 'core/block-editor' ).getSelectedBlock() );

	const timesRef = useRef( [] );

	const toggleWithoutEnhancing = useCallback( () => {
		onChange(
			toggleFormat( value, {
				type: name,
			} )
		);
	} );

	// If any of the times change, toggle the format off for that time.
	useEffect( () => {
		const nextTimes = getTimesFromContent( content );

		// If next and previous times are not the same length,
		// then a format has been added or removed and we can skip comparison.
		if ( nextTimes.length !== timesRef.current.length ) {
			timesRef.current = nextTimes;
			return;
		}

		let index = 0;
		while ( index < nextTimes.length ) {
			const nextTime = nextTimes[ index ];

			if ( timesRef.current[ index ] !== nextTime ) {
				// find the start and end point of nextTime in the text
				const start = value.text.indexOf( nextTime );
				const end = start + nextTime.length;

				onChange( removeFormat( value, name, start, end ) );

				// exit to rerun this effect with the new times
				break;
			}

			index++;
		}

		timesRef.current = nextTimes;
	}, [ content, timesRef ] );

	return (
		<RichTextToolbarButton
			icon={ icon }
			title={ title }
			onClick={ () => {
				const dateDescription = getTextContent( slice( value ) );

				if ( ! dateDescription || isActive ) {
					toggleWithoutEnhancing();

					return;
				}

				// Remove the word "at" from the string, if present.
				// Allows strings like "Monday, April 6 at 19:00 UTC" to work.
				const dateCleaned = dateDescription.replace( 'at ', '' );

				// strtotime understands "GMT" better than "UTC" for timezones.
				dateCleaned.replace( 'UTC', 'GMT' );

				// Try to parse the time, relative to the post time, if available
				// In the Site Editor the post time is not available, so we'll just use the current time.
				// TODO: https://github.com/WordPress/wporg-mu-plugins/issues/422
				const postTimestamp = !! date_gmt ? strtotime( date_gmt ) : undefined;
				const time = strtotime( dateCleaned, postTimestamp );

				// If that didn't work, give up.
				if ( false === time || -1 === time ) {
					toggleWithoutEnhancing();

					return;
				}

				const datetime = gmdate( 'c', time );
				const datetimeISO = gmdate( 'Ymd\\THi', time );

				onChange(
					toggleFormat( value, {
						type: name,
						attributes: {
							datetime: datetime,
							'data-iso': datetimeISO,
							style: 'text-decoration: underline; text-decoration-style: dotted',
						},
					} )
				);
			} }
			isActive={ isActive }
		/>
	);
};

registerFormatType( name, {
	title: title,
	tagName: 'time',
	className: 'wporg-time',
	edit: Edit,
} );
