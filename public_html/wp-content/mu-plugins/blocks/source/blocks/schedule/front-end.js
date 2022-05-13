/**
 * Internal dependencies
 */
import { getDerivedSessions } from './data';
import { ScheduleGrid, ScheduleGridContext } from './schedule-grid';
import renderFrontend from '../../utils/render-frontend';
import './front-end.scss';

// See `pass_global_data_to_front_end()` for details on front- vs back-end data sourcing.
const rawScheduleData = window.WordCampBlocks.schedule || {};

/**
 * Wrap ScheduleGrid with a Context provider.
 *
 * @param {Object} props
 * @param {Array}  props.chosenSessions
 * @param {Array}  props.allTracks
 * @param {Object} props.attributes
 * @param {Object} props.settings
 * @return {Element}
 */
function ScheduleGridWithContext( props ) {
	const { chosenSessions, allTracks, attributes, settings } = props;

	const contextValues = {
		allTracks: allTracks,
		attributes: attributes,
		settings: settings,
		renderEnvironment: 'front-end',
	};

	return (
		<ScheduleGridContext.Provider
			value={ contextValues }
		>
			<ScheduleGrid sessions={ chosenSessions } />
		</ScheduleGridContext.Provider>
	);
}

/**
 * Gather the props that should be passed to ScheduleGrid.
 *
 * document that pulling [...pulling what? forgot what i was gonna write - todo]
 *
 * @param {Element} element
 * @return {Object}
 */
function getScheduleGrdProps( element ) {
	const { attributes: rawAttributes } = element.dataset;
	const { allCategories, allTracks, settings } = rawScheduleData;
	let parsedAttributes = {};
	let derivedSessions = [];

	if ( rawAttributes ) {
		parsedAttributes = JSON.parse( decodeURIComponent( rawAttributes ) );

		derivedSessions = getDerivedSessions(
			rawScheduleData.allSessions,
			allCategories,
			allTracks,
			parsedAttributes
		);
	}

	const props = {
		allTracks: allTracks,
		settings: settings,
		attributes: parsedAttributes,
		chosenSessions: derivedSessions.chosenSessions,
	};

	return props;
}

renderFrontend( '.wp-block-wordcamp-schedule', ScheduleGridWithContext, getScheduleGrdProps );
