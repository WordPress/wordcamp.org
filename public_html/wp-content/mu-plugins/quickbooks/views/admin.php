<?php
namespace WordCamp\QuickBooks\Admin;

defined( 'WPINC' ) || die();

?>

<div class="wrap">
	<h2>QuickBooks Authorization</h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">


		<?php submit_button(); ?>
	</form>
</div>
