/**
 * Render an overlay on top of unselected embeds to improve UX.
 *
 * If this didn't exist, and the user clicked on the block, they'd click into the `iframe`, and Gutenberg wouldn't
 * register the block as being selected, so the sidebar controls/etc would be inaccessible. If the iframe contains
 * a video or other interactive element, then it would start playing, even if the user just wanted to select the
 * block.
 *
 * This is basically a simpler version of what `EmbedPreview.render()` does, but that's not exported for reuse.
 * This can probably be removed when https://github.com/WordPress/gutenberg/issues/13490 is resolved.
 *
 * @param {boolean} isSelected Whether or not the block is selected.
 * @return {*} Nothing when the block is selected, and the overlay when it's not.
 */
export default function( { isSelected } ) {
	if ( isSelected ) {
		return null;
	}

	return (
		<div className="deselected-iframe-overlay"></div>
	);
}
