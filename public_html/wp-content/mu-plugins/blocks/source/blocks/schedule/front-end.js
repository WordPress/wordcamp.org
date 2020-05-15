/**
 * Internal dependencies
 */
import { getDerivedSessions } from './data';
import { ScheduleGridContext } from './edit';
import { ScheduleGrid } from './schedule-grid';
import renderFrontend from '../../utils/render-frontend';
import './front-end.scss';

// See `pass_global_data_to_front_end()` for details on front- vs back-end data sourcing.
const rawScheduleData = window.WordCampBlocks.schedule || {};

/*
 * todo
 *
 * this file is being loading in the editor, but should only load on the front end.
 * probably a similar problem mentioned in init(), so maybe conditionally register it if on the front end?
 * `wp_using_themes() && ! wcorg_is_rest_api_request()` ? - https://wordpress.stackexchange.com/a/360401/3898
 *
 * the front-end build file is really big
 * still seeing a bunch of dependencies being added to it, like classnames, lodash, memoize, @babel and @emotion,
 * etc.
 * also contains stuff from other custom blocks, like components/image/avatar.js, even though not used in this
 * block.
 * some of those may be expected, but others should definitely not be bundled.
 * maybe just need to explicitly add the @wordpress/* components that we're using to package.json `dependencies`?
 * then the above packages will be registered as externals automatically?
 * would it be better to just load the whole blocks file instead of creating a separate ones, since everything
 * exists in there too, and that would be cached for other pages?
 */

/**
 * Wrap ScheduleGrid with a Context provider.
 *
 * @param {Object} props
 * @param {Array}  props.chosenSessions
 * @param {Array}  props.allTracks
 * @param {Object} props.attributes
 * @param {Object} props.settings
 *
 * @return {Element}
 */
function ScheduleGridWithContext( props ) {
	const { chosenSessions, allTracks, attributes, settings } = props;

	/*
	 * `attributes.attributes` is an unparsed JSON string. It's an artifact from `renderFrontend()` expecting
	 * individual `data-{foo}` HTML attributes, instead of a single `data-attributes` one. For this block, though,
	 * that would take extra work to maintain without providing any benefit. Removing it prevents it from causing
	 * any confusion.
	 *
	 * @todo-front Maybe look at refactoring that function to avoid workarounds like this.
	 */
	delete attributes.attributes;

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
 *
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
