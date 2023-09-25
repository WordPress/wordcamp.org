/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';

/**
 * Render a list of the map markers.
 *
 * @param {Object}   props
 * @param {string}   props.searchQuery
 * @param {Function} props.onQueryChange
 * @param {string}   props.iconURL
 *
 * @return {JSX.Element}
 */
export default function Search( { searchQuery, onQueryChange, iconURL } ) {
	return (
		<form className="wporg-marker-search__container">
			<label htmlFor="wporg-marker-search__input">
				<span>{ __( 'Search events:', 'wporg' ) }</span>

				<input
					className="wporg-marker-search__input"
					type="text"
					value={ searchQuery }
					// translators: Change this to a recognizable city in your locale.
					placeholder={ _x( 'Springfield', 'Event query placeholder', 'wporg' ) }
					aria-label={ __( 'Search events:', 'wporg' ) }
					onChange={ onQueryChange }
				/>
			</label>

			<img className="wporg-marker-search__icon" src={ iconURL } alt={ __( 'Search', 'wporg' ) } />
		</form>
	);
}
