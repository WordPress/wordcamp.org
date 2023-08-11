<?php

/**
 * Specify a font to be preloaded.
 *
 * @see WordPressdotorg\MU_Plugins\Global_Fonts\preload_font.
 *
 * @param string $fonts   The font(s) to preload, comma-separated.
 * @param string $subsets The subset(s) to preload, comma-separated.
 *
 * @return bool If the font has been added to the preload list.
 */
function global_fonts_preload( $fonts, $subsets = '' ) {
	return WordPressdotorg\MU_Plugins\Global_Fonts\preload_font( $fonts, $subsets );
}
