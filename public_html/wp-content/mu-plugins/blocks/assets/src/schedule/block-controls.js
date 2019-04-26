/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

/**
 * Internal dependencies
 */
import { PlaceholderNoContent } from '../shared/block-controls';
import ScheduleBlockContent     from './block-content';
import { LABEL }                from './index';

function ScheduleBlockControls( props ) {
	const { icon, attributes, categories, sessions, tracks } = props;
	const hasPosts = Array.isArray( categories) && Array.isArray( sessions ) && Array.isArray( tracks );
	let output;

	if ( hasPosts ) {
		output = (
			<ScheduleBlockContent
				attributes={ attributes }
				categories={ categories }
				sessions={ sessions }
				tracks={ tracks }
			/>
		) ;
	} else {
		// todo test this when loading data - should see spinner, can use devtools to slow network down
		// test when 0 sessions published - should see "no content" message

		output = (
			<PlaceholderNoContent
				icon={ icon }
				label={ LABEL }
				loading={ ! hasPosts }
			/>
		);
	}

	// todo supposed to have placeholdelr to select days/tracks like other blocks, or no b/c most times just showing all days/tracks and can modify in sidebar? ask mel
		// if so then copy mode etc from sessionsblockcontrols

	return output;
}

export default ScheduleBlockControls;
