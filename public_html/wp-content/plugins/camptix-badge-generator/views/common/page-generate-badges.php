<?php

namespace CampTix\Badge_Generator;
defined( 'WPINC' ) || die();

/**
 * @var string $html_customizer_url
 * @var string $indesign_page_url
 * @var string $notify_tool_url
 * @var string $qr_code_type
 */

?>

<p>
	<?php esc_html_e(
		'This tool will help you create personalized badges for attendees to wear during the event. There are three methods for this, depending on your preferences:',
		'wordcamporg'
	); ?>
</p>

<div id="cbg-method-overviews">
	<div id="html-badge-overview">
		<h2>
			<?php esc_html_e( 'HTML and CSS', 'wordcamporg' ); ?>
		</h2>

		<ul class="ul-disc">
			<li><?php esc_html_e( 'The Easiest method.',                                                                              'wordcamporg' ); ?></li>
			<li><?php esc_html_e( 'Can be as simple as using the default design and printing at home.',                               'wordcamporg' ); ?></li>
			<li><?php esc_html_e( 'Design is customizable by a designer or developer, but options are limited compared to InDesign.', 'wordcamporg' ); ?></li>
		</ul>

		<a class="button button-primary" href="<?php echo esc_url( $html_customizer_url ); ?>">
			<?php esc_html_e( 'Create Badges with HTML and CSS', 'wordcamporg' ); ?>
		</a>
	</div>

	<div id="indesign-badges-overview" class="cbg-method-overview">
		<h2>
			<?php esc_html_e( 'InDesign', 'wordcamporg' ); ?>
		</h2>

		<ul class="ul-disc">
			<li><?php esc_html_e( 'The best results, but requires more work.', 'wordcamporg' ); ?></li>
			<li><?php esc_html_e( 'Most flexible design options.',             'wordcamporg' ); ?></li>
			<li>
				<?php printf(
					wp_kses_post( __( 'Requires a designer and a copy of InDesign. <a href="%s">Free trial copies are available</a>.', 'wordcamporg' ) ),
					'https://www.adobe.com/products/indesign.html'
				); ?>
			</li>
		</ul>

		<a class="button button-primary" href="<?php echo esc_url( $indesign_page_url ); ?>">
			<?php esc_html_e( 'Create Badges with InDesign', 'wordcamporg' ); ?>
		</a>
	</div>

	<div id="canva-badges-overview" class="cbg-method-overview">
		<h2>
			<?php esc_html_e( 'Canva', 'wordcamporg' ); ?>
		</h2>

		<ul class="ul-disc">
			<li><?php esc_html_e( 'Design the badge as a Canva template, then fill it in for every attendee at once.', 'wordcamporg' ); ?></li>
			<li><?php esc_html_e( "Download a spreadsheet with each attendee's details, photo, and QR code.", 'wordcamporg' ); ?></li>
			<li>
				<?php printf(
					wp_kses_post( __( 'Requires <a href="%s">Bulk Create</a>, which is part of Canva Pro and Canva for Nonprofits.', 'wordcamporg' ) ),
					'https://www.canva.com/help/bulk-create/'
				); ?>
			</li>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Canva\ACTION ); ?>" />
			<?php wp_nonce_field( Canva\ACTION ); ?>

			<p>
				<label for="cbg-canva-qr-code-type"><?php esc_html_e( 'QR code destination', 'wordcamporg' ); ?></label>
				<select id="cbg-canva-qr-code-type" name="cbg_qr_code_type">
					<option value="none" <?php selected( $qr_code_type, 'none' ); ?>><?php esc_html_e( 'None', 'wordcamporg' ); ?></option>
					<option value="gravatar" <?php selected( $qr_code_type, 'gravatar' ); ?>><?php esc_html_e( 'Gravatar profile', 'wordcamporg' ); ?></option>
					<option value="wporg" <?php selected( $qr_code_type, 'wporg' ); ?>><?php esc_html_e( 'WordPress.org profile', 'wordcamporg' ); ?></option>
				</select>
			</p>

			<p class="description">
				<?php esc_html_e( 'This can take a few minutes for large events, while the photos are downloaded.', 'wordcamporg' ); ?>
			</p>

			<?php submit_button( __( 'Download Spreadsheet for Canva', 'wordcamporg' ), 'primary', 'submit', false ); ?>
		</form>
	</div>
</div>

<p>
	<?php printf(
		// translators: 1: Gravatar.com URL, 2: Notify tool URL.
		wp_kses_post( __(
			'Regardless of which method you choose, you\'ll get the best results if you encourage attendees to create <a href="%1$s">Gravatar</a> accounts before you create the badges. You can use <a href="%2$s">the Notify tool</a> to e-mail everyone. Make sure to tell them to create their Gravatar account using the same e-mail address that provided when purchasing a ticket.',
			'wordcamporg'
		) ),
		'https://gravatar.com',
		esc_url( $notify_tool_url )
	); ?>
</p>
