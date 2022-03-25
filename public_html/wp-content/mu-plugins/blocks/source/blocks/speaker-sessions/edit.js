/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { getSessionDetails, sortSessionByTime } from '../sessions/utils';

export default function( { attributes, setAttributes, context: { postId } } ) {
	const { hasSessionDetails, isLink } = attributes;
	const blockProps = useBlockProps();
	const sessions = useSelect( ( select ) => {
		const { getEntityRecords } = select( coreStore );
		const _sessions =
			getEntityRecords( 'postType', 'wcb_session', {
				wc_meta_key: '_wcpt_speaker_id',
				wc_meta_value: postId,
				_embed: true,
			} ) || [];

		_sessions.sort( sortSessionByTime );

		return _sessions;
	}, [] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'wordcamporg' ) }>
					<ToggleControl
						label={ __( 'Show session details', 'wordcamporg' ) }
						help={ __( 'Show the date, time, and track (if set).', 'wordcamporg' ) }
						onChange={ () => setAttributes( { hasSessionDetails: ! hasSessionDetails } ) }
						checked={ hasSessionDetails }
					/>
					<ToggleControl
						label={ __( 'Link to session', 'wordcamporg' ) }
						onChange={ () => setAttributes( { isLink: ! isLink } ) }
						checked={ isLink }
					/>
				</PanelBody>
			</InspectorControls>
			<ul { ...blockProps }>
				{ sessions.map( ( session ) => (
					<li key={ session.id }>
						<p>
							{ isLink ? (
								<a href={ session.link }>{ session.title.rendered }</a>
							) : (
								session.title.rendered
							) }
						</p>
						{ hasSessionDetails && (
							<p className="wordcamp-speaker-sessions__session-info">{ getSessionDetails( session, true ) }</p>
						) }
					</li>
				) ) }
			</ul>
		</>
	);
}
