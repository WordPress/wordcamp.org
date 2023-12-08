/**
 * Returns the height of the preview window.
 *
 * @param {number} cardWidth   The width of the card
 * @param {number} aspectRatio The aspect ration of the card
 */
export default function getCardFrameHeight( cardWidth, aspectRatio ) {
	return cardWidth * aspectRatio;
}
