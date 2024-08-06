<?php
namespace WordCamp\QuickBooks\Admin;

use WordCamp\QuickBooks\Client;
use const WordCamp\Quickbooks\{ PLUGIN_DIR, PLUGIN_PREFIX };

defined( 'WPINC' ) || die();

/** @var Client $client */
/** @var string $cmd */
/** @var string $button_label */
/** @var array  $button_attributes */
?>

<div class="wrap">
	<h1>QuickBooks Settings</h1>

	<?php require PLUGIN_DIR . '/views/admin-form-error.php'; ?>

	<h2>Connection</h2>

	<p>
		This connection allows us make data requests to QuickBooks Online in order to manage various types of payments
		for WordCamps as well as generate reports.
	</p>

	<?php if ( $client->has_valid_token() ) : ?>
		<p>
			<span class="dashicons dashicons-yes" aria-hidden="true"></span>
			<?php
			printf(
				'Connected to <strong>%1$s</strong>. Expires on <strong>%2$s</strong>.',
				wp_kses_data( $client->get_company_name() ),
				wp_kses_data( date( 'd M Y h:i', $client->get_refresh_token_expiration() ) )
			);
			?>
		</p>
	<?php else : ?>
		<p>
			<span class="dashicons dashicons-no" aria-hidden="true"></span>
			Not connected.
		</p>
		<p>
			Reconnect by clicking the button below. You will be asked to log in to a QuickBooks Online account in order
			to complete the connection process. Use the <strong>WordCamp Developer</strong> account for this login.
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( $cmd, PLUGIN_PREFIX . '_oauth_' . $cmd ); ?>

		<input type="hidden" name="action" value="<?php echo esc_attr( PLUGIN_PREFIX ); ?>-oauth" />
		<input type="hidden" name="cmd" value="<?php echo esc_attr( $cmd ); ?>" />

		<?php
		submit_button(
			$button_label,
			'primary',
			'submit',
			true,
			$button_attributes
		);
		?>
	</form>
</div>

<script>
	jQuery( function( $ ) {
		$( '#wordcamp-qbo-submit-revoke' ).click( function( event ) {
			if ( ! confirm( "This will halt all functionality that interacts with QuickBooks Online.\n\nAre you sure you want to disconnect?" ) ) {
				event.preventDefault();
			}
		} );
	} );
</script>
