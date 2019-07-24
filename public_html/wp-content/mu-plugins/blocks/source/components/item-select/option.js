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
 * }
 *
 * @return {Element}
 */
export function Option( { avatar, icon, label, count } ) {
	let image;

	if ( avatar ) {
		image = (
			<AvatarImage
				className="wordcamp-item-select__option-avatar"
				name={ label }
				size={ 24 }
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

	const content = (
		<span className="wordcamp-item-select__option-label">
			{ label }
			{ 'undefined' !== typeof count &&
				<span className="wordcamp-item-select__option-label-count">
					{ count }
				</span>
			}
		</span>
	);

	return (
		<div className="wordcamp-item-select__option">
			{ image }
			{ content }
		</div>
	);
}
