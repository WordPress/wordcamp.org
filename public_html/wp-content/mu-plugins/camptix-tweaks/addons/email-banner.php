<?php

namespace WordCamp\CampTix_Tweaks;

defined( 'WPINC' ) || die();

const EMAIL_BANNER_OPTION = 'email_banner_id';

add_action( 'admin_init',               __NAMESPACE__ . '\add_email_banner_setting', 11    );
add_filter( 'camptix_validate_options', __NAMESPACE__ . '\validate_email_banner',    10, 2 );

/**
 * Add the email banner field to Tickets > Setup > E-mail Templates.
 *
 * CampTix registers its own fields for that section on `admin_init` and fires no hook for it.
 */
function add_email_banner_setting() {
	/** @var \CampTix_Plugin $camptix */
	global $camptix;

	if ( ! isset( $camptix->admin_setup ) || 'email-templates' !== $camptix->admin_setup->get_setup_section() ) {
		return;
	}

	add_settings_field(
		EMAIL_BANNER_OPTION,
		__( 'Email banner', 'wordcamporg' ),
		__NAMESPACE__ . '\render_email_banner_field',
		'camptix_options',
		'general'
	);
}

/**
 * Render the email banner media field.
 */
function render_email_banner_field() {
	$banner_id = get_email_banner_id();
	$preview   = $banner_id ? wp_get_attachment_image_url( $banner_id, 'medium' ) : '';

	wp_enqueue_media();
	wp_enqueue_script(
		'camptix-email-banner',
		plugins_url( 'js/email-banner.js', __FILE__ ),
		array( 'jquery', 'media-editor' ),
		filemtime( __DIR__ . '/js/email-banner.js' ),
		true
	);
	wp_localize_script(
		'camptix-email-banner',
		'camptixEmailBanner',
		array(
			'title'  => __( 'Choose an email banner', 'wordcamporg' ),
			'button' => __( 'Use this banner', 'wordcamporg' ),
		)
	);

	?>
	<div class="camptix-email-banner">
		<div class="camptix-email-banner-preview">
			<?php if ( $preview ) : ?>
				<img src="<?php echo esc_url( $preview ); ?>" alt="" style="max-width: 300px; height: auto;" />
			<?php endif; ?>
		</div>

		<input type="hidden" name="camptix_options[<?php echo esc_attr( EMAIL_BANNER_OPTION ); ?>]" value="<?php echo esc_attr( $banner_id ?: '' ); ?>" class="camptix-email-banner-id" />
		<button type="button" class="button camptix-email-banner-choose"><?php esc_html_e( 'Choose image', 'wordcamporg' ); ?></button>
		<button type="button" class="button camptix-email-banner-remove" <?php echo $banner_id ? '' : 'style="display: none;"'; ?>><?php esc_html_e( 'Remove image', 'wordcamporg' ); ?></button>

		<p class="description">
			<?php esc_html_e( 'Replaces the WordPress logo and event name at the top of ticket, receipt, refund and Notify emails. Emails show it 600 pixels wide, so use an image at least that wide.', 'wordcamporg' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Save the email banner, keeping only image attachments.
 *
 * @param array $output The sanitized options.
 * @param array $input  The submitted options.
 *
 * @return array
 */
function validate_email_banner( $output, $input ) {
	if ( ! array_key_exists( EMAIL_BANNER_OPTION, (array) $input ) ) {
		return $output;
	}

	$banner_id = absint( $input[ EMAIL_BANNER_OPTION ] );

	if ( $banner_id && wp_attachment_is_image( $banner_id ) ) {
		$output[ EMAIL_BANNER_OPTION ] = $banner_id;
	} else {
		unset( $output[ EMAIL_BANNER_OPTION ] );
	}

	return $output;
}

/**
 * Get the attachment ID of the site's email banner.
 *
 * @return int The attachment ID, or 0 when none is set.
 */
function get_email_banner_id() {
	/** @var \CampTix_Plugin $camptix */
	global $camptix;

	$options = $camptix ? $camptix->get_options() : array();

	return absint( $options[ EMAIL_BANNER_OPTION ] ?? 0 );
}

/**
 * Get the URL of the email banner, sized for the 600px email template.
 *
 * @return string The URL, or an empty string when no banner is set.
 */
function get_email_banner_url() {
	$banner_id = get_email_banner_id();

	if ( ! $banner_id || ! wp_attachment_is_image( $banner_id ) ) {
		return '';
	}

	return (string) wp_get_attachment_image_url( $banner_id, 'large' );
}
