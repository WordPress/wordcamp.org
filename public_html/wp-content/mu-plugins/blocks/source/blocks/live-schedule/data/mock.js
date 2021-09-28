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
		_wcpt_session_time: new Date( `Oct 1 2021 ${ time } EDT` ).getTime() / 1000,
	},
	session_date_time: { time },
	_embedded: {
		speakers: [
			{
				id: 1,
				title: { rendered: _x( 'Speaker Name', 'A fake speaker name', 'wordcamporg' ) },
				link: '#',
			},
			{
				id: 2,
				title: { rendered: _x( 'Speaker Name', 'A fake speaker name', 'wordcamporg' ) },
				link: '#',
			},
		],
	},
} );

export default [
	{
		track: makeTrack( _x( 'Location A', 'A fake track name', 'wordcamporg' ) ),
		now: makeSession( _x( 'Session Title', 'A fake session name', 'wordcamporg' ), '10:00' ),
		next: makeSession( _x( 'Session Title', 'A fake session name', 'wordcamporg' ), '11:00' ),
	},
	{
		track: makeTrack( _x( 'Location B', 'A fake track name', 'wordcamporg' ) ),
		now: makeSession( _x( 'Workshop Title', 'A fake session name', 'wordcamporg' ), '10:00' ),
		next: makeSession( _x( 'Session Title', 'A fake session name', 'wordcamporg' ), '13:00' ),
	},
	{
		track: makeTrack( _x( 'Location C', 'A fake track name', 'wordcamporg' ) ),
		now: makeSession( _x( 'Flash Talk Title', 'A fake session name', 'wordcamporg' ), '10:15' ),
		next: makeSession( _x( 'Flash Talk Title', 'A fake session name', 'wordcamporg' ), '10:30' ),
	},
];
