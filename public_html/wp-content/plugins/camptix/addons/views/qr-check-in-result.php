<?php
/**
 * Check-in confirmation page for the generic-camera fallback ( ?tix_action=checkin ).
 *
 * Loaded via `template_include` after CampTix_QR_Check_In::handle_checkin_page() has run the
 * check-in and stored the outcome on the addon instance.
 *
 * @var CampTix_Plugin $camptix
 */

defined( 'WPINC' ) || die();

global $camptix;

$camptix_result = array(
	'status'  => 'invalid',
	'message' => __( 'This QR code is not valid.', 'wordcamporg' ),
	'name'    => '',
);

foreach ( $camptix->addons_loaded as $camptix_addon ) {
	if ( $camptix_addon instanceof CampTix_QR_Check_In && ! empty( $camptix_addon->checkin_result ) ) {
		$camptix_result = $camptix_addon->checkin_result;
		break;
	}
}

$camptix_status = isset( $camptix_result['status'] ) ? $camptix_result['status'] : 'invalid';
$camptix_ok     = in_array( $camptix_status, array( 'success', 'already' ), true );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="referrer" content="no-referrer" />
	<title><?php esc_html_e( 'Attendee check-in', 'wordcamporg' ); ?></title>
	<?php wp_print_styles( array( 'dashicons', 'camptix-qr-scanner' ) ); ?>
</head>
<body class="tix-qr-scanner-page tix-qr-result-page">
	<main class="tix-qr-main">
		<div class="tix-qr-result tix-qr-result--<?php echo esc_attr( $camptix_status ); ?>" role="status">
			<div class="tix-qr-result-icon dashicons <?php echo esc_attr( $camptix_ok ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>" aria-hidden="true"></div>

			<?php if ( ! empty( $camptix_result['name'] ) ) : ?>
				<p class="tix-qr-result-name"><?php echo esc_html( $camptix_result['name'] ); ?></p>
			<?php endif; ?>

			<p class="tix-qr-result-message"><?php echo esc_html( $camptix_result['message'] ); ?></p>
		</div>
	</main>
</body>
</html>
<?php
exit;
