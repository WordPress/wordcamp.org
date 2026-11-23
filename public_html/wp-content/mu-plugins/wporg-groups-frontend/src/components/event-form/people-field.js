/**
 * A token field for picking people from the group's members, behind the
 * event form's Hosts and Speakers fields.
 *
 * The value is a list of `{ id, name, slug }`; the token field only deals in
 * strings, so each person is shown by name, with their profile slug added
 * when two members share one.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h, useEffect, useRef, useState } from '@wordpress/element';
import { BaseControl, FormTokenField } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies.
 */
import { NS } from './constants';
import { labelPeople, memberToHost } from './hosts';

const SEARCH_DELAY_MS = 250;

/*
 * The Hosts and Speakers fields mount together and both want the first page
 * of members, so they share one in-flight request. It's dropped once settled,
 * so the next time the form opens it asks again.
 */
let initialMembersRequest = null;

function requestMembers( search ) {
	if ( search ) {
		return apiFetch( { path: `/${ NS }/members?per_page=20&search=${ encodeURIComponent( search ) }` } );
	}

	if ( ! initialMembersRequest ) {
		initialMembersRequest = apiFetch( { path: `/${ NS }/members?per_page=100` } ).finally( () => {
			initialMembersRequest = null;
		} );
	}

	return initialMembersRequest;
}

/**
 * @param {Object}   props
 * @param {Array}    props.value       The people picked.
 * @param {Function} props.onChange    Receives the new list.
 * @param {string}   props.classPrefix BEM block prefix of the host surface.
 * @param {string}   props.label       Field label.
 * @param {string}   props.help        Help text under the field.
 * @param {number}   props.maxLength   The most people the field takes.
 * @param {Object}   props.messages    `FormTokenField` messages.
 */
export default function PeopleField( { value, onChange, classPrefix, label, help, maxLength, messages } ) {
	const picked = value || [];
	const [ members, setMembers ] = useState( [] );
	const searchTimer = useRef( null );

	const fetchMembers = ( search ) => {
		requestMembers( search )
			.then( ( res ) => {
				const found = Array.isArray( res ) ? res.map( memberToHost ) : [];
				// Keep what earlier searches found, so a token picked from
				// one search still resolves after the next.
				setMembers( ( prev ) => [ ...prev, ...found ] );
			} )
			.catch( () => {} );
	};

	useEffect( () => {
		fetchMembers( '' );
		return () => clearTimeout( searchTimer.current );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const labels = labelPeople( [ ...picked, ...members ] );
	const labelFor = ( person ) => {
		for ( const [ label, candidate ] of labels ) {
			if ( candidate.id === person.id ) {
				return label;
			}
		}
		return person.name;
	};

	const pickedIds = new Set( picked.map( ( person ) => person.id ) );

	return h(
		'div',
		{ className: `${ classPrefix }__field` },
		h(
			BaseControl,
			{
				help,
				__nextHasNoMarginBottom: true,
			},
			h( FormTokenField, {
				label,
				value: picked.map( labelFor ),
				suggestions: [ ...labels.keys() ].filter( ( key ) => ! pickedIds.has( labels.get( key ).id ) ),
				maxLength,
				// Anything typed that isn't a member's label is dropped.
				onChange: ( tokens ) => onChange(
					tokens
						.map( ( token ) => labels.get( typeof token === 'string' ? token : token.value ) )
						.filter( Boolean )
				),
				onInputChange: ( input ) => {
					clearTimeout( searchTimer.current );
					if ( input.trim() ) {
						searchTimer.current = setTimeout( () => fetchMembers( input.trim() ), SEARCH_DELAY_MS );
					}
				},
				messages,
				__experimentalExpandOnFocus: true,
				__experimentalShowHowTo: false,
				__nextHasNoMarginBottom: true,
			} )
		)
	);
}
