/**
 * Help text for the featured image picker, kept apart from the picker so it
 * can be tested without loading the component library.
 *
 * @package WordCamp\Groups\Frontend
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The picker's guidance on what to upload (#2257).
 *
 * Every place an event's image shows (cards, the event page, the flyer)
 * crops it to 16:9, so that's the shape to aim for. The size limit is the
 * site's own, and a PNG exported from a design tool is often several times
 * the size of the same image as a JPG.
 *
 * @param {string} maxUploadSize Formatted upload limit, e.g. "1 MB", or empty.
 * @return {string} Help text.
 */
export function getFeaturedImageHelp( maxUploadSize ) {
	const shape = __(
		'Shown in 16:9, so a taller or wider image is cropped. 1200 × 675 pixels works well.',
		'wordcamporg'
	);

	if ( ! maxUploadSize ) {
		return shape;
	}

	return (
		shape +
		' ' +
		sprintf(
			/* translators: %s: maximum upload size, e.g. "1 MB". */
			__( 'Maximum file size: %s. If yours is larger, save it as a JPG.', 'wordcamporg' ),
			maxUploadSize
		)
	);
}
