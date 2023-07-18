<?php
/**
 * Attendee Visa Template
 */
class WordCamp_Docs_Template_Attendee_Visa implements WordCamp_Docs_Template {
	public function form( $data ) {
		$wordcamp    = get_wordcamp_post();
		$start_date  = ! empty( $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ) ? date( 'j F Y', $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ) : '';
		$end_date    = ! empty( $wordcamp->meta['End Date (YYYY-mm-dd)'][0] )   ? date( 'j F Y', $wordcamp->meta['End Date (YYYY-mm-dd)'][0] )   : $start_date;

		$data = wp_parse_args( $data, array(
			'attendee_first_name' => '',
			'attendee_last_name' => '',
			'country_of_residency' => '',
			'passport_number' => '',

			'wordcamp_name'       => $wordcamp->post_title          ?? '',
			'wordcamp_location'   => $wordcamp->meta['Location'][0] ?? '',
			'wordcamp_date_start' => $start_date,
			'wordcamp_date_end'   => $end_date,

			'organizer_name'     => $wordcamp->meta['Organizer Name'][0] ?? '',
			'organizer_contacts' => '',
		) );
		?>
		<style>
		.wcorg-docs-form label {
			display: block;
			clear: both;
			margin-top: 12px;
		}

		.wcorg-docs-form input,
		.wcorg-docs-form textarea,
		.wcorg-docs-form select {
			width: 240px;
		}

		.wcorg-docs-form textarea {
			height: 120px;
		}

		.wcorg-docs-form .description {
			display: block;
			clear: both;
		}
		</style>

		<h2>
			<?php _e( 'Attendee Visa Letter', 'wordcamporg' ); ?>
		</h2>

		<div class="wcorg-docs-form">
			<label><?php _e( 'Attendee First Name:', 'wordcamporg' ); ?></label>
			<input name="attendee_first_name" value="<?php echo esc_attr( $data['attendee_first_name'] ); ?>" />

			<label><?php _e( 'Attendee Last Name:', 'wordcamporg' ); ?></label>
			<input name="attendee_last_name" value="<?php echo esc_attr( $data['attendee_last_name'] ); ?>" />

			<label><?php _e( 'Country of Residency:', 'wordcamporg' ); ?></label>
			<input name="country_of_residency" value="<?php echo esc_attr( $data['country_of_residency'] ); ?>" />

			<label><?php _e( 'Passport Number:', 'wordcamporg' ); ?></label>
			<input name="passport_number" value="<?php echo esc_attr( $data['passport_number'] ); ?>" />

			<label><?php _e( 'WordCamp Name:', 'wordcamporg' ); ?></label>
			<input name="wordcamp_name" value="<?php echo esc_attr( $data['wordcamp_name'] ); ?>" />

			<label><?php _e( 'WordCamp Location:', 'wordcamporg' ); ?></label>
			<input name="wordcamp_location" value="<?php echo esc_attr( $data['wordcamp_location'] ); ?>" />

			<label><?php _e( 'WordCamp Date Start:', 'wordcamporg' ); ?></label>
			<input name="wordcamp_date_start" value="<?php echo esc_attr( $data['wordcamp_date_start'] ); ?>" />

			<label><?php _e( 'WordCamp Date End:', 'wordcamporg' ); ?></label>
			<input name="wordcamp_date_end" value="<?php echo esc_attr( $data['wordcamp_date_end'] ); ?>" />

			<label><?php _e( 'Organizer Name:', 'wordcamporg' ); ?></label>
			<input name="organizer_name" value="<?php echo esc_attr( $data['organizer_name'] ); ?>" />

			<label><?php _e( 'Organizer Contacts:', 'wordcamporg' ); ?></label>
			<textarea name="organizer_contacts"><?php echo esc_textarea( $data['organizer_contacts'] ); ?></textarea>
			<span class="description"><?php _e( 'Use multiple lines.', 'wordcamporg' ); ?></span>
		</div>

		<?php
	}

	public function render( $data ) {
		ob_start();
		?>
<html>
<head>
<meta charset="UTF-8">
<link href="http://fonts.googleapis.com/css?family=Open+Sans:300,600,700" rel="stylesheet" type="text/css" />
<style type="text/css">
body {
	margin: 0;
	padding: 0;
	font-family: 'Open Sans', sans-serif;
	font-size: 15px;
	line-height: 1.5;
	font-weight: 300;
	color: #444;
}

p {
	margin: 0 0 1.5em;
}

h1, h2, h3, h4, div, p, span, table {
	font-family: 'Open Sans', sans-serif;
}

strong {
	font-weight: 600;
}

table {
	table-layout: fixed;
	width: 100%;
	margin: 0;
	padding: 0;
	border: none;
	border-collapse: collapse;

	font-family: 'Open Sans', sans-serif;
	font-size: 15px;
	line-height: 1.5;
	font-weight: 300;
	color: #444;
}

h2 {
	font-size: 18px;
}

.wrap {
	clear: both;
	margin: 40px 20px;
}

.main {
	float: left;
	width: 100%;
	margin-top: 40px;
}

.logo-wrapper {
	text-align: center;
	margin-bottom: 40px;
}

.logo {
	width: 400px;
	height: auto;
}
</style>
</head>

<body>

<div class="wrap">
	<div class="main">

		<div class="logo-wrapper">
			<img class="logo" src="wpcs-logo.png" alt="WordPress Community Support, PBC">
		</div>

		<p><?php echo date( 'F j, Y' ); ?></p>

		<p>To Whom It May Concern:</p>

		<p>This letter is to confirm that <?php echo esc_html( $data['attendee_first_name'] ); ?> <?php echo esc_html( $data['attendee_last_name'] ); ?>
		<?php echo esc_html( $data['country_of_residency'] ); ?> passport number <?php echo esc_html( $data['passport_number'] ); ?>,
		has purchased a ticket to attend <?php echo esc_html( $data['wordcamp_name'] ); ?>, a community-organized event focusing on WordPress
		development and technology.</p>

		<p>WordPress is a web software you can use to create a beautiful website or blog. The core software is built by hundreds of community
		volunteers. The mission of the WordPress open source project is to democratize publishing through Open Source, GPL software.</p>

		<p>Attending <?php echo esc_html( $data['wordcamp_name'] ); ?> will require <?php echo esc_html( $data['attendee_first_name'] ); ?> to be
		in <?php echo esc_html( $data['wordcamp_location'] ); ?> from <?php echo esc_html( $data['wordcamp_date_start'] ); ?>
		through <?php echo esc_html( $data['wordcamp_date_end'] ); ?>.</p>

		<p>I would be happy to provide any further information you may require.</p>

		<p>Sincerely,<br />
		<?php echo esc_html( $data['organizer_name'] ); ?><br />
		Organizer<br />
		<?php echo esc_html( $data['wordcamp_name'] ); ?></p>

		<p><?php echo nl2br( esc_html( $data['organizer_contacts'] ) ); ?></p>

	</div>

</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	public function sanitize( $input ) {
		$output = array();
		foreach ( array(
			'attendee_first_name',
			'attendee_last_name',
			'country_of_residency',
			'passport_number',
			'wordcamp_name',
			'wordcamp_location',
			'wordcamp_date_start',
			'wordcamp_date_end',
			'organizer_name',
		) as $field )
			$output[ $field ] = sanitize_text_field( wp_strip_all_tags( $input[ $field ] ) );

		$output['organizer_contacts'] = wp_strip_all_tags( $input['organizer_contacts'] );
		return $output;
	}

	public function get_name() {
		return __( 'Attendee Visa Letter', 'wordcamporg' );
	}

	public function get_filename() {
		return 'attendee-visa.pdf';
	}

	public function get_assets() {
		return array(
			dirname( __FILE__ ) . '/assets/wpcs-logo.png',
		);
	}
}
