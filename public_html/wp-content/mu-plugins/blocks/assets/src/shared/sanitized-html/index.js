/**
 * External dependencies
 */
import DOMPurify from 'dompurify';

/**
 * WordPress dependencies
 */
const { RawHTML } = wp.element;

function SanitizedHTML( { children } ) {
	const sanitized = DOMPurify.sanitize( children ).trim();

	return (
		<RawHTML>
			{ sanitized }
		</RawHTML>
	);
}

export default SanitizedHTML;
