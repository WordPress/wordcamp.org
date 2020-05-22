/**
 * Internal dependencies
 */
import * as liveSchedule from './live-schedule';
import * as organizers from './organizers';
import * as schedule from './schedule';
import * as sessions from './sessions';
import * as speakers from './speakers';
import * as sponsors from './sponsors';

export const BLOCKS = [
	liveSchedule,
	organizers,
	schedule,
	sessions,
	speakers,
	sponsors,
];
