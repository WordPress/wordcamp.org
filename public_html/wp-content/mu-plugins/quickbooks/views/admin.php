<?php
namespace WordCamp\QuickBooks\Admin;

use WordCamp\QuickBooks\Client;
use const WordCamp\Quickbooks\{ PLUGIN_PREFIX };

defined( 'WPINC' ) || die();

/** @var array $errors */
/** @var array $messages */
/** @var Client $client */
/** @var string $cmd */
/** @var string $button */
?>

<div class="wrap">
	<h2>QuickBooks Authorization</h2>

	<?php if ( ! empty( $errors ) ) : ?>
		<?php foreach ( $errors as $error_message ) : ?>
			<div class="notice notice-error">
				<?php echo wp_kses_post( wpautop( $error_message ) ); ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( $cmd, PLUGIN_PREFIX . '_oauth_' . $cmd ); // TODO ?>

		<input type="hidden" name="action" value="<?php echo esc_attr( PLUGIN_PREFIX ); ?>-oauth" />
		<input type="hidden" name="cmd" value="<?php echo esc_attr( $cmd ); ?>" />

		<?php submit_button( $button ); ?>
	</form>
</div>
