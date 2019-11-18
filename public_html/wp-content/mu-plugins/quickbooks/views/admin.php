<?php
namespace WordCamp\QuickBooks\Admin;

use WordCamp\QuickBooks\Client;
use const WordCamp\Quickbooks\{ PLUGIN_PREFIX };

defined( 'WPINC' ) || die();

/** @var array $errors */
/** @var Client $client */
/** @var string $cmd */
/** @var string $button */
?>

<div class="wrap">
	<h1>QuickBooks Settings</h1>

	<?php if ( $client->has_error() ) : ?>
		<?php foreach ( $client->error->get_error_codes() as $error_code ) : ?>
			<div class="notice notice-error">
				<?php if ( $client->error->get_error_data( $error_code ) ) : ?>
					<details style="margin:0.5em 0;padding:2px;">
						<summary>
							<?php echo wp_kses_post( $client->error->get_error_message( $error_code ) ); ?>
						</summary>
						<?php echo esc_html( print_r( $client->error->get_error_data( $error_code ), true ) ); ?>
					</details>
				<?php else : ?>
					<?php echo wp_kses_post( wpautop( $client->error->get_error_message( $error_code ) ) ); ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<h2>Authorization</h2>

	<?php if ( $client->has_valid_token() ) : ?>
		<p>
			<?php
			printf(
				'Connected to <strong>%1$s</strong>. Expires in <strong>%2$s</strong>.',
				wp_kses_data( $client->get_company_name() ),
				wp_kses_data( $client->get_refresh_token_expiration() )
			);
			?>
		</p>
	<?php else : ?>
		<p>
			Not connected.
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( $cmd, PLUGIN_PREFIX . '_oauth_' . $cmd ); ?>

		<input type="hidden" name="action" value="<?php echo esc_attr( PLUGIN_PREFIX ); ?>-oauth" />
		<input type="hidden" name="cmd" value="<?php echo esc_attr( $cmd ); ?>" />

		<?php submit_button( $button ); ?>
	</form>
</div>
