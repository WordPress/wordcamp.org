<?php

defined( 'WPINC' ) || die();

/**
 * @var array  $camptix_opts
 * @var string $letter_number
 * @var string $letter_date
 * @var array  $letter_metas
 * @var string $logo
 */

$first_name       = ! empty( $letter_metas['first_name'] ) ? $letter_metas['first_name'] : '';
$last_name        = ! empty( $letter_metas['last_name'] ) ? $letter_metas['last_name'] : '';
$passport_country = ! empty( $letter_metas['passport_country'] ) ? $letter_metas['passport_country'] : '';
$passport_number  = ! empty( $letter_metas['passport_number'] ) ? $letter_metas['passport_number'] : '';
$date_of_birth    = ! empty( $letter_metas['date_of_birth'] ) ? $letter_metas['date_of_birth'] : '';
$nationality      = ! empty( $letter_metas['nationality'] ) ? $letter_metas['nationality'] : '';
$mailing_address  = ! empty( $letter_metas['mailing_address'] ) ? $letter_metas['mailing_address'] : '';

$event_name      = ! empty( $camptix_opts['event_name'] ) ? $camptix_opts['event_name'] : '';
$event_venue     = ! empty( $camptix_opts['visa-letter-event-venue'] ) ? $camptix_opts['visa-letter-event-venue'] : '';
$organizer_name  = ! empty( $camptix_opts['visa-letter-organizer-name'] ) ? $camptix_opts['visa-letter-organizer-name'] : '';
$organizer_title = ! empty( $camptix_opts['visa-letter-organizer-title'] ) ? $camptix_opts['visa-letter-organizer-title'] : __( 'Organizer', 'wordcamporg' );
$organizer_email = ! empty( $camptix_opts['visa-letter-organizer-email'] ) ? $camptix_opts['visa-letter-organizer-email'] : '';
$organizer_phone = ! empty( $camptix_opts['visa-letter-organizer-phone'] ) ? $camptix_opts['visa-letter-organizer-phone'] : '';
$custom_note     = ! empty( $camptix_opts['visa-letter-custom-note'] ) ? $camptix_opts['visa-letter-custom-note'] : '';

$letter_date_format = ! empty( $camptix_opts['visa-letter-date-format'] ) ? $camptix_opts['visa-letter-date-format'] : 'F j, Y';

// Present the date of birth in the configured letter date format.
if ( ! empty( $date_of_birth ) ) {
	$dob_timestamp = strtotime( $date_of_birth );
	if ( $dob_timestamp ) {
		$date_of_birth = date_i18n( $letter_date_format, $dob_timestamp );
	}
}

// Canadian compliance (IRCC) additions.
$canadian            = ! empty( $camptix_opts['visa-letter-canadian'] );
$transaction_id      = ! empty( $letter_metas['transaction_id'] ) ? $letter_metas['transaction_id'] : '';
$confirmation_number = $transaction_id ? $transaction_id : $letter_number;
$entry_date          = ! empty( $letter_metas['entry_date'] ) ? $letter_metas['entry_date'] : '';
$exit_date           = ! empty( $letter_metas['exit_date'] ) ? $letter_metas['exit_date'] : '';
$accommodation       = ! empty( $letter_metas['accommodation'] ) ? $letter_metas['accommodation'] : '';
foreach ( array( 'entry_date', 'exit_date' ) as $vl_date_var ) {
	if ( ! empty( $$vl_date_var ) && strtotime( $$vl_date_var ) ) {
		$$vl_date_var = date_i18n( $letter_date_format, strtotime( $$vl_date_var ) );
	}
}

// Signature image.
$signature_image = '';
if ( ! empty( $camptix_opts['visa-letter-signature'] ) ) {
	$signature_image = get_attached_file( $camptix_opts['visa-letter-signature'] );
}

// Try to get event dates from the WordCamp post.
$wordcamp_start = '';
$wordcamp_end   = '';
if ( function_exists( 'get_wordcamp_post' ) ) {
	$wordcamp = get_wordcamp_post();
	if ( $wordcamp ) {
		$wordcamp_start = ! empty( $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ) ? date_i18n( 'F j, Y', $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ) : '';
		$wordcamp_end   = ! empty( $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ) ? date_i18n( 'F j, Y', $wordcamp->meta['End Date (YYYY-mm-dd)'][0] ) : $wordcamp_start;
	}
}

?>

<html>
<head>
	<meta charset="UTF-8">
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This is rendered by wkhtmltopdf, so additional libraries are included directly. ?>
	<link href="http://fonts.googleapis.com/css?family=Open+Sans:300,600,700" rel="stylesheet" type="text/css"/>
	<style type="text/css">
		body {
			margin: 0;
			padding: 0;
			font-family: 'Open Sans', sans-serif;
			font-size: 15px;
			line-height: 1.6;
			font-weight: 300;
			color: #444;
		}

		strong {
			font-weight: 600;
		}

		p {
			margin: 0 0 1.2em;
		}

		.wrap {
			clear: both;
			margin: 40px 50px;
		}

		.logo-wrapper {
			text-align: center;
			margin-bottom: 30px;
		}

		.logo-wrapper img {
			max-width: 400px;
			height: auto;
		}

		.letter-header {
			margin-bottom: 2em;
		}

		.letter-header .letter-date {
			margin-bottom: 0.5em;
		}

		.letter-header .letter-ref {
			font-size: 13px;
			color: #666;
		}

		.letter-body p {
			text-align: justify;
		}

		.signature-block {
			margin-top: 3em;
		}

		.signature-block p {
			margin: 0;
			line-height: 1.8;
		}

		.signature-block .signature-image img {
			max-width: 200px;
			max-height: 80px;
			margin-bottom: 5px;
		}
	</style>
</head>
<body>

<div class="wrap">

	<div class="logo-wrapper">
		<img src="<?php echo esc_url( $logo ); ?>" alt="">
	</div>

	<div class="letter-header">
		<p class="letter-date"><?php echo esc_html( $letter_date ); ?></p>
		<p class="letter-ref">
			<?php
			// translators: visa letter reference number.
			printf( esc_html__( 'Ref: %s', 'wordcamporg' ), esc_html( $letter_number ) );
			?>
		</p>
	</div>

	<div class="letter-body">
		<p><strong><?php esc_html_e( 'To Whom It May Concern:', 'wordcamporg' ); ?></strong></p>

		<p>
			<?php
			printf(
				/* translators: 1: first name, 2: last name, 3: nationality (adjective), 4: passport number, 5: passport issuing country, 6: event name */
				esc_html__( 'This letter is to confirm that %1$s %2$s, a %3$s citizen, holding passport number %4$s issued by %5$s, has purchased a ticket to attend %6$s, a community-organized event focusing on WordPress development and technology.', 'wordcamporg' ),
				esc_html( $first_name ),
				esc_html( $last_name ),
				esc_html( $nationality ),
				esc_html( $passport_number ),
				esc_html( $passport_country ),
				esc_html( $event_name )
			);
			?>
		</p>

		<?php if ( ! empty( $date_of_birth ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: first name, 2: last name, 3: date of birth */
				esc_html__( '%1$s %2$s was born on %3$s.', 'wordcamporg' ),
				esc_html( $first_name ),
				esc_html( $last_name ),
				esc_html( $date_of_birth )
			);
			?>
		</p>
		<?php endif; ?>

		<?php if ( $canadian && ! empty( $confirmation_number ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: registration confirmation number */
				esc_html__( 'Registration confirmation number: %s', 'wordcamporg' ),
				esc_html( $confirmation_number )
			);
			?>
		</p>
		<?php endif; ?>

		<p>
			<?php esc_html_e( 'WordPress is web software used to create websites and blogs. The core software is built by hundreds of community volunteers. The mission of the WordPress open source project is to democratize publishing through Open Source, GPL software.', 'wordcamporg' ); ?>
		</p>

		<?php if ( ! empty( $wordcamp_start ) && ! empty( $event_venue ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: event name, 2: first name, 3: event venue, 4: start date, 5: end date */
				esc_html__( 'Attending %1$s will require %2$s to be at %3$s from %4$s through %5$s.', 'wordcamporg' ),
				esc_html( $event_name ),
				esc_html( $first_name ),
				esc_html( $event_venue ),
				esc_html( $wordcamp_start ),
				esc_html( $wordcamp_end )
			);
			?>
		</p>
		<?php elseif ( ! empty( $wordcamp_start ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: event name, 2: first name, 3: start date, 4: end date */
				esc_html__( 'Attending %1$s will require %2$s to be present from %3$s through %4$s.', 'wordcamporg' ),
				esc_html( $event_name ),
				esc_html( $first_name ),
				esc_html( $wordcamp_start ),
				esc_html( $wordcamp_end )
			);
			?>
		</p>
		<?php endif; ?>

		<?php if ( $canadian && ! empty( $entry_date ) && ! empty( $exit_date ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: first name, 2: last name, 3: entry date, 4: exit date */
				esc_html__( '%1$s %2$s intends to enter Canada on %3$s and depart on %4$s.', 'wordcamporg' ),
				esc_html( $first_name ),
				esc_html( $last_name ),
				esc_html( $entry_date ),
				esc_html( $exit_date )
			);
			?>
		</p>
		<?php endif; ?>

		<?php if ( $canadian && ! empty( $accommodation ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: first name, 2: last name */
				esc_html__( 'During their stay, %1$s %2$s will be accommodated at:', 'wordcamporg' ),
				esc_html( $first_name ),
				esc_html( $last_name )
			);
			?>
			<br />
			<?php echo nl2br( esc_html( $accommodation ) ); ?>
		</p>
		<?php endif; ?>

		<?php if ( ! empty( $mailing_address ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: first name, 2: last name */
				esc_html__( 'The mailing address for %1$s %2$s is:', 'wordcamporg' ),
				esc_html( $first_name ),
				esc_html( $last_name )
			);
			?>
			<br />
			<?php echo nl2br( esc_html( $mailing_address ) ); ?>
		</p>
		<?php endif; ?>

		<?php if ( ! empty( $custom_note ) ) : ?>
		<p><?php echo nl2br( esc_html( $custom_note ) ); ?></p>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: 1: first name, 2: last name */
				esc_html__( 'We kindly request that you grant %1$s %2$s the necessary visa to attend this event. Should you require any additional information, please do not hesitate to contact us.', 'wordcamporg' ),
				esc_html( $first_name ),
				esc_html( $last_name )
			);
			?>
		</p>
	</div>

	<div class="signature-block">
		<p><?php esc_html_e( 'Sincerely,', 'wordcamporg' ); ?></p>
		<?php if ( ! empty( $signature_image ) ) : ?>
			<div class="signature-image">
				<img src="<?php echo esc_url( $signature_image ); ?>" alt="<?php esc_attr_e( 'Signature', 'wordcamporg' ); ?>">
			</div>
		<?php else : ?>
			<p>&nbsp;</p>
		<?php endif; ?>
		<?php if ( ! empty( $organizer_name ) ) : ?>
			<p><strong><?php echo esc_html( $organizer_name ); ?></strong></p>
		<?php endif; ?>
		<?php if ( ! empty( $organizer_title ) ) : ?>
			<p><?php echo esc_html( $organizer_title ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html( $event_name ); ?></p>
		<?php if ( ! empty( $organizer_email ) ) : ?>
			<p><?php echo esc_html( $organizer_email ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $organizer_phone ) ) : ?>
			<p><?php echo esc_html( $organizer_phone ); ?></p>
		<?php endif; ?>
	</div>

</div>
</body>
</html>
