/**
 * WordPress dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';

/**
 * A hook to get and set a post meta value.
 *
 * @param {string} key          Meta key.
 * @param {?*}     defaultValue A default value, if the key is not set.
 * @return {Object<*,Function>} A pair of values: the current meta value and a callback to update this meta value.
 */
export default function usePostMeta( key, defaultValue = '' ) {
	const metaValue = useSelect( ( select ) => {
		const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		if ( ! meta ) {
			return defaultValue;
		}
		return meta[ key ] || defaultValue;
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const setMetaValue = ( value ) => {
		editPost( {
			meta: {
				[ key ]: value,
			},
		} );
	};

	return [ metaValue, setMetaValue ];
}
