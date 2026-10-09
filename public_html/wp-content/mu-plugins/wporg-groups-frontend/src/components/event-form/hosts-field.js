/**
 * The people hosting an event, shared by the event modal and the
 * group-settings Events tab.
 *
 * Hosts are picked from the group's members. The value is a list of
 * `{ id, name, slug }`; the token field only deals in strings, so each host
 * is shown by name, with their profile slug added when two members share one.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { createElement as h, useEffect, useRef, useState } from '@wordpress/element';
import { BaseControl, FormTokenField } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { NS } from './constants';
import { MAX_HOSTS, labelPeople, memberToHost } from './hosts';

const SEARCH_DELAY_MS = 250;

export default function HostsField( { value, onChange, classPrefix } ) {
	const hosts = value || [];
	const [ members, setMembers ] = useState( [] );
	const searchTimer = useRef( null );

	const fetchMembers = ( search ) => {
		const query = search
			? `?per_page=20&search=${ encodeURIComponent( search ) }`
			: '?per_page=100';

		apiFetch( { path: `/${ NS }/members${ query }` } )
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

	const labels = labelPeople( [ ...hosts, ...members ] );
	const labelFor = ( person ) => {
		for ( const [ label, candidate ] of labels ) {
			if ( candidate.id === person.id ) {
				return label;
			}
		}
		return person.name;
	};

	const hostIds = new Set( hosts.map( ( host ) => host.id ) );

	return h(
		'div',
		{ className: `${ classPrefix }__field` },
		h(
			BaseControl,
			{
				help: __( 'Shown as “Hosted by” on the event page. Pick from the group’s members.', 'wordcamporg' ),
				__nextHasNoMarginBottom: true,
			},
			h( FormTokenField, {
				label: __( 'Hosts', 'wordcamporg' ),
				value: hosts.map( labelFor ),
				suggestions: [ ...labels.keys() ].filter( ( label ) => ! hostIds.has( labels.get( label ).id ) ),
				maxLength: MAX_HOSTS,
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
				messages: {
					added: __( 'Host added.', 'wordcamporg' ),
					removed: __( 'Host removed.', 'wordcamporg' ),
					remove: __( 'Remove host', 'wordcamporg' ),
					/* translators: %d: the most hosts an event can have. */
					__experimentalInvalid: sprintf( __( 'Up to %d hosts.', 'wordcamporg' ), MAX_HOSTS ),
				},
				__experimentalExpandOnFocus: true,
				__experimentalShowHowTo: false,
				__nextHasNoMarginBottom: true,
			} )
		)
	);
}
