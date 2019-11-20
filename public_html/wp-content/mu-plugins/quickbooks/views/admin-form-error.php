<?php
namespace WordCamp\QuickBooks\Admin;

use WordCamp\QuickBooks\Client;

defined( 'WPINC' ) || die();

/** @var Client $client */
?>

<?php if ( $client->has_error() ) : ?>
	<?php foreach ( $client->error->get_error_codes() as $error_code ) : ?>
		<div class="notice notice-error">
			<?php if ( $client->error->get_error_data( $error_code ) ) : ?>
				<details style="margin:0.5em 0;padding:2px;">
					<summary>
						<?php echo wp_kses_post( $client->error->get_error_message( $error_code ) ); ?>
					</summary>
					<pre>
						<?php echo esc_html( print_r( $client->error->get_error_data( $error_code ), true ) ); ?>
					</pre>
				</details>
			<?php else : ?>
				<?php echo wp_kses_post( wpautop( $client->error->get_error_message( $error_code ) ) ); ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
<?php endif; ?>
