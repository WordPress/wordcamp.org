/**
 * WordPress dependencies
 */
import { Dashicon } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { AvatarImage } from '../image';

/**
 * Component for a single option in an ItemSelect dropdown.
 *
 * Not all of the props need or should have a value. An option representing a speaker will have
 * an avatar prop, but not an icon or a count (of terms).
 *
 * @param {Object} props {
 *     @type {string} avatar
 *     @type {string} icon
 *     @type {string} label
 *     @type {number} count
 *     @type {string} context - `menu` or `value` for whether it's in the menu dropdown or the selected token.
 *     @type {Node}   details
 * }
 * @return {Element}
 */
export function Option( { avatar, context, count, details, icon, label } ) {
	let image;

	if ( 'value' === context ) {
		return (
			<div className="wordcamp-item-select__token">
				{ label }
			</div>
		);
	}

	if ( avatar ) {
		image = (
			<AvatarImage
				className="wordcamp-item-select__option-avatar"
				name={ label }
				size={ 50 }
				url={ avatar }
			/>
		);
	} else if ( icon ) {
		image = (
			<div className="wordcamp-item-select__option-icon-container">
				<Dashicon
					className="wordcamp-item-select__option-icon"
					icon={ icon }
					size={ 16 }
				/>
			</div>
		);
	}

	return (
		<div className="wordcamp-item-select__option">
			{ image }
			<span className="wordcamp-item-select__option-label">
				{ label }
				{ 'undefined' !== typeof count && (
					<span className="wordcamp-item-select__option-label-count">
						{ count }
					</span>
				) }
				{ 'undefined' !== typeof details && (
					<span className="wordcamp-item-select__option-label-details">
						{ details }
					</span>
				) }
			</span>
		</div>
	);
}
