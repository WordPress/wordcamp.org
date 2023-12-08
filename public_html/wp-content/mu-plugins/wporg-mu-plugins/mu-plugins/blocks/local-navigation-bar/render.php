<?php
/**
 * Render the block content.
 */

use function WordPressdotorg\MU_Plugins\Global_Header_Footer\get_home_url;
?>

<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore ?> >
	<figure class="wp-block-image global-header__wporg-logo-mark">
		<a href="<?php echo esc_url( get_home_url() ); ?>">
			<?php require dirname( __DIR__ ) . '/global-header-footer/images/w-mark.svg'; ?>
		</a>
	</figure>
	<?php echo $content // phpcs:ignore ?>
</div>
