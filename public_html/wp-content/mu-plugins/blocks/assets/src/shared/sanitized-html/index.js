/**
 * External dependencies
 */
import DOMPurify from 'dompurify';

/**
 * WordPress dependencies
 */
const { pure } = wp.compose;
const { RawHTML } = wp.element;

/**
 * Component used to sanitize an arbitrary string of HTML, similar to wp_kses in PHP.
 *
 * Note: this should only be used in cases when it's not possible to compose the HTML within JSX templates,
 * such as when pre-rendered HTML is fetched asynchronously from an API.
 */
function SanitizedHTML( { children } ) {
	const sanitized = DOMPurify.sanitize( children ).trim();

	return (
		<RawHTML>
			{ sanitized }
		</RawHTML>
	);
}

export default pure( SanitizedHTML );
