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

/**
 * The picker's warning when the group's upload space is nearly or completely
 * used (#2361), or '' when there's room.
 *
 * The media library only says the space is used up once it is, and new
 * uploads fail from then on, so organizers hear about it first here.
 *
 * @param {Object} uploadSpace         From the modal config.
 * @param {string} uploadSpace.status  `ok`, `near`, or `full`.
 * @param {string} uploadSpace.used    Formatted space used, e.g. "950 MB".
 * @param {string} uploadSpace.allowed Formatted space allowed, e.g. "1 GB".
 * @return {string} Warning with an `<a>` around where to ask for more, for `createInterpolateElement()`.
 */
export function getUploadSpaceNotice( uploadSpace ) {
	const { status, used, allowed } = uploadSpace || {};

	if ( 'full' === status ) {
		return sprintf(
			/* translators: %s: upload space, e.g. "1 GB". */
			__(
				'This group has used all %s of its upload space, so new images can’t be uploaded. You can still choose one that is already in the media library. For more space, ask in <a>#meetups</a> on Make WordPress Slack.',
				'wordcamporg'
			),
			allowed
		);
	}

	if ( 'near' === status ) {
		return sprintf(
			/* translators: 1: upload space used, e.g. "950 MB". 2: upload space, e.g. "1 GB". */
			__(
				'This group has used %1$s of its %2$s upload space. For more space, ask in <a>#meetups</a> on Make WordPress Slack.',
				'wordcamporg'
			),
			used,
			allowed
		);
	}

	return '';
}
