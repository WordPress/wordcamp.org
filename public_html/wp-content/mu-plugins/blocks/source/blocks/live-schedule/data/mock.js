/**
 * WordPress dependencies
 */
import { _x } from '@wordpress/i18n';

const makeTrack = ( name ) => ( {
	slug: name.toLowerCase(),
	name: name,
} );

const makeSession = ( name, time ) => ( {
	terms: {},
	link: '#',
	title: { rendered: name },
	meta: {
		_wcpt_session_type: 'session',
	},
	session_date_time: { time },
	_embedded: {
		speakers: [
			{
				id: 1,
				title: { rendered: _x( 'Speaker Name', 'A fake speaker name', 'wordcamporg' ) },
				link: '#',
			}, {
				id: 2,
				title: { rendered: _x( 'Speaker Name', 'A fake speaker name', 'wordcamporg' ) },
				link: '#',
			},
		],
	},
} );

export default [
	{
		track: makeTrack( _x( 'Location', 'A fake track name', 'wordcamporg' ) ),
		now: makeSession( _x( 'Session Title', 'A fake session name', 'wordcamporg' ), '10:00 AM' ),
		next: makeSession( _x( 'Session Title', 'A fake session name', 'wordcamporg' ), '11:00 AM' ),
	},
	{
		track: makeTrack( _x( 'Location', 'A fake track name', 'wordcamporg' ) ),
		now: makeSession( _x( 'Workshop Title', 'A fake session name', 'wordcamporg' ), '10:00 AM' ),
		next: makeSession( _x( 'Session Title', 'A fake session name', 'wordcamporg' ), '1:00 PM' ),
	},
	{
		track: makeTrack( _x( 'Location', 'A fake track name', 'wordcamporg' ) ),
		now: makeSession( _x( 'Flash Talk Title', 'A fake session name', 'wordcamporg' ), '10:15 AM' ),
		next: makeSession( _x( 'Flash Talk Title', 'A fake session name', 'wordcamporg' ), '10:30 AM' ),
	},
];
