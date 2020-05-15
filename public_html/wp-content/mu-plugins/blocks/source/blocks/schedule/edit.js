/**
 * WordPress dependencies
 */
import { createContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useScheduleData } from './data';
import { ScheduleGrid } from './schedule-grid';
import InspectorControls from './inspector-controls';
import { NoContent } from '../../components/';
import './edit.scss';

export const ScheduleGridContext = createContext();

/**
 * Top-level component for the editing UI for the block.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes
 * @param {Function} props.setAttributes
 *
 * @return {Element}
 */
export function ScheduleEdit( { attributes, setAttributes } ) {
	const scheduleData = useScheduleData( attributes );
	const { allSessions, chosenSessions, allTracks, settings } = scheduleData;

	if ( scheduleData.loading ) {
		return <NoContent loading={ true } />;
	}

	const contextValues = {
		allTracks: allTracks,
		attributes: attributes,
		settings: settings,
		renderEnvironment: 'editor',
	};

	return (
		<>
			<ScheduleGridContext.Provider value={ contextValues }>
				<ScheduleGrid sessions={ chosenSessions } />
			</ScheduleGridContext.Provider>

			<InspectorControls
				/*
				 * This is intentionally using `allSessions` instead of `chosenSessions`, for the same reason that
				 * `allTracks` is used instead of the assigned tracks. See `ChooseSpecificTracks()`.
				 */
				allSessions={ allSessions }
				allTracks={ allTracks }
				attributes={ attributes }
				setAttributes={ setAttributes }
				settings={ settings }
			/>
		</>
	);
}
