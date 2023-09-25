<?php

namespace WordPressdotorg\MU_Plugins\Global_Header_Footer\Footer;

defined( 'WPINC' ) || die();

wp_footer();

// Intentionally calling this in addition to `wp_footer()` from the block. See `classic-header.php` for details.
if ( function_exists( 'gp_footer' ) ) {
	gp_footer();
}

?>

	</body>
</html>
