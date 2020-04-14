/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { compose } from '@wordpress/compose';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { FormTokenField } from '@wordpress/components';
import { withDispatch, withSelect } from '@wordpress/data';

const SessionSpeakers = ( { onChange, speakers, selected } ) => {
	// `suggestions` expects an array of strings, not objects.
	const speakerNames = speakers.map( ( { title: { rendered = '' } } ) => rendered );
	const formatSpeakerForList = ( { title: { rendered = '' }, id } ) => ( { value: rendered, id: id } );

	return (
		<PluginDocumentSettingPanel
			name="wordcamp/session-speakers"
			className="wordcamp-panel-session-speakers"
			title={ __( 'Speakers', 'wordcamporg' ) }
		>
			<FormTokenField
				label={ __( 'Select speakers', 'wordcamporg' ) }
				help={ false }
				value={ speakers.filter( ( { id } ) => selected.includes( id ) ).map( formatSpeakerForList ) }
				suggestions={ speakerNames }
				onChange={ ( value ) => {
					// Find the selected name in the full list, format into value,id pair.
					const newValue = value.map( ( val ) => {
						if ( val.id ) {
							return val;
						}
						const speaker = speakers.find( ( { title } ) => title.rendered === val );
						if ( ! speaker ) {
							return false;
						}
						return formatSpeakerForList( speaker );
					} );
					onChange( newValue.filter( Boolean ) );
				} }
			/>
		</PluginDocumentSettingPanel>
	);
};

export default compose( [
	withSelect( ( select ) => {
		const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		const speakers = select( 'core' ).getEntityRecords( 'postType', 'wcb_speaker', {
			status: 'any',
			per_page: -1,
			_embed: true,
		} );

		return {
			selected: meta._wcpt_speaker_id,
			speakers: speakers || [],
		};
	} ),
	withDispatch( ( dispatch ) => ( {
		onChange( value ) {
			dispatch( 'core/editor' ).editPost( {
				meta: {
					_wcpt_speaker_id: value.map( ( { id } ) => id ),
				},
			} );
		},
	} ) ),
] )( SessionSpeakers );
