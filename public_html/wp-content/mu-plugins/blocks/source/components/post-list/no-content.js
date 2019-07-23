/**
 * WordPress dependencies
 */
import { __ }      from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * Component for indicating why there is no content.
 *
 * @param {Object} props {
 *     @type {boolean} loading
 * }
 *
 * @return {Element}
 */
export default function BlockNoContent( { loading } ) {
	return (
		<div className="wordcamp-block__posts has-no-content">
			{ loading ?
				<Spinner /> :
				__( 'No content found.', 'wordcamporg' )
			}
		</div>
	);
}
