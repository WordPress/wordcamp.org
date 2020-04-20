/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { withInstanceId } from '@wordpress/compose';

function SessionDuration( { instanceId, onChange, value } ) {
	const hours = Math.floor( value / 3600 );
	const minutes = Math.floor( ( value / 60 ) % 60 );

	function updateDuration( newHours, newMins ) {
		const duration = ( newHours * 3600 ) + ( newMins * 60 ); // prettier-ignore
		onChange( duration );
	}

	return (
		<fieldset className="components-base-control wordcamp-panel-session-info__duration">
			<legend className="components-base-control__label">{ __( 'Session Length', 'wordcamporg' ) }</legend>

			<div className="wordcamp-panel-session-info__duration-wrapper">
				<input
					type="number"
					id={ `session-duration-hrs-${ instanceId }` }
					value={ hours }
					onChange={ ( event ) => updateDuration( event.target.value, minutes ) }
					max="23"
					min="0"
				/>
				<label htmlFor={ `session-duration-hrs-${ instanceId }` }>{ __( 'hours', 'wordcamporg' ) }</label>

				<input
					type="number"
					id={ `session-duration-mins-${ instanceId }` }
					value={ minutes }
					onChange={ ( event ) => updateDuration( hours, event.target.value ) }
					max="59"
					min="0"
					step="5"
				/>
				<label htmlFor={ `session-duration-mins-${ instanceId }` }>
					{ __( 'minutes', 'wordcamporg' ) }
				</label>
			</div>
		</fieldset>
	);
}

export default withInstanceId( SessionDuration );
