/**
 * WordPress dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @return {WPElement} Element to render.
 */
export default function Edit() {
	return (
		<div { ...useBlockProps() }>
			<div>
				WordPress is also available in <a href="http://wordpress.org">language</a> (if applicable).
			</div>
		</div>
	);
}
