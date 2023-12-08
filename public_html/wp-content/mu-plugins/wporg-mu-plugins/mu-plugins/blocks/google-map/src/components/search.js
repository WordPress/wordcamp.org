/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useCallback } from '@wordpress/element';

/**
 * Render a list of the map markers.
 *
 * @param {Object}   props
 * @param {string}   props.formAction
 * @param {string}   props.searchQuery
 * @param {Function} props.onQueryChange
 * @param {string}   props.iconURL
 */
export default function Search( { formAction, searchQuery, onQueryChange, iconURL } ) {
	// Live searches shouldn't submit to the server.
	const onFormSubmit = useCallback(
		( event ) => {
			if ( ! formAction ) {
				event.preventDefault();
			}
		},
		[ formAction ]
	);

	const searchIcon = (
		<img className="wporg-marker-search__icon" src={ iconURL } alt={ __( 'Search', 'wporg' ) } />
	);

	const formActionURL = formAction ? new URL( formAction ) : undefined;

	return (
		<form
			className="wporg-marker-search__container"
			action={ formAction ? formActionURL.href : undefined }
			onSubmit={ onFormSubmit }
		>
			<label htmlFor="wporg-marker-search__input">
				<span>{ __( 'Search events:', 'wporg' ) }</span>

				<input
					className="wporg-marker-search__input"
					type="text"
					name="search"
					value={ searchQuery }
					// translators: Change this to a recognizable city in your locale.
					placeholder={ _x( 'Springfield', 'Event query placeholder', 'wporg' ) }
					aria-label={ __( 'Search events:', 'wporg' ) }
					onChange={ onQueryChange }
				/>
			</label>

			{ formAction && (
				<Button type="submit" variant="tertiary">
					{ searchIcon }
				</Button>
			) }

			{ ! formAction && searchIcon }
		</form>
	);
}
