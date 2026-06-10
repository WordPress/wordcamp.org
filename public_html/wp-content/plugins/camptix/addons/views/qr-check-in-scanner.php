<?php
/**
 * Full-page live-camera QR check-in scanner.
 *
 * Loaded via `template_include` for logged-in, capability-checked organizers. Renders a minimal
 * standalone document (no theme chrome) and prints only this addon's enqueued assets.
 *
 * @var CampTix_Plugin $camptix
 */

defined( 'WPINC' ) || die();

global $camptix;

$camptix_options    = $camptix->get_options();
$camptix_event_name = isset( $camptix_options['event_name'] ) ? $camptix_options['event_name'] : get_bloginfo( 'name' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="referrer" content="no-referrer" />
	<title><?php echo esc_html( sprintf( /* translators: %s: event name. */ __( '%s check-in', 'wordcamporg' ), $camptix_event_name ) ); ?></title>
	<?php wp_print_styles( array( 'dashicons', 'camptix-qr-scanner' ) ); ?>
</head>
<body class="tix-qr-scanner-page">
	<header class="tix-qr-header">
		<h1><?php echo esc_html( $camptix_event_name ); ?></h1>
		<p><?php esc_html_e( 'Attendee check-in', 'wordcamporg' ); ?></p>
	</header>

	<main class="tix-qr-main">
		<div id="tix-qr-reader" class="tix-qr-reader"></div>

		<div id="tix-qr-status" class="tix-qr-status" aria-live="polite"></div>

		<div id="tix-qr-result" class="tix-qr-result" role="status" aria-live="assertive" hidden>
			<div class="tix-qr-result-icon" aria-hidden="true"></div>
			<p class="tix-qr-result-name"></p>
			<p class="tix-qr-result-message"></p>
			<button type="button" class="tix-qr-result-again button"><?php esc_html_e( 'Scan next attendee', 'wordcamporg' ); ?></button>
		</div>

		<div class="tix-qr-manual">
			<label for="tix-qr-manual-input"><?php esc_html_e( 'Manual entry', 'wordcamporg' ); ?></label>
			<p class="description"><?php esc_html_e( 'If the camera will not read a code, paste the check-in link or code here.', 'wordcamporg' ); ?></p>
			<input type="text" id="tix-qr-manual-input" autocomplete="off" autocapitalize="off" spellcheck="false" />
			<button type="button" id="tix-qr-manual-submit" class="button"><?php esc_html_e( 'Check in', 'wordcamporg' ); ?></button>
		</div>
	</main>

	<?php wp_print_scripts( array( 'html5-qrcode', 'camptix-qr-scanner' ) ); ?>
</body>
</html>
<?php
exit;
