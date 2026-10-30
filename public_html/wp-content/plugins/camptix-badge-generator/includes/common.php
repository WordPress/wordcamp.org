<?php

namespace CampTix\Badge_Generator;
use CampTix_Plugin;
use CampTix\Badge_Generator\HTML;

defined( 'WPINC' ) || die();

if ( is_admin() ) {
	add_filter( 'camptix_menu_tools_tabs',   __NAMESPACE__ . '\add_badges_tab'     );
	add_action( 'camptix_menu_tools_badges', __NAMESPACE__ . '\render_badges_page' );
	add_action( 'admin_print_styles',        __NAMESPACE__ . '\print_admin_styles' );
}

add_filter( 'get_post_metadata', __NAMESPACE__ . '\add_dynamic_post_meta', 10, 3 );

/**
 * Add the Generate Badges tab to the CampTix Tools page
 *
 * @param array $sections
 *
 * @return array
 */
function add_badges_tab( $sections ) {
	$sections['badges'] = __( 'Generate Badges', 'wordcamporg' );

	return $sections;
}

/**
 * Render the main Generate Badges page
 */
function render_badges_page() {
	if ( ! current_user_can( REQUIRED_CAPABILITY ) ) {
		return;
	}

	$html_customizer_url = HTML\get_customizer_section_url();
	$notify_tool_url     = admin_url( 'edit.php?post_type=tix_ticket&page=camptix_tools&tix_section=notify' );
	$indesign_page_url   = admin_url( 'edit.php?post_type=tix_ticket&page=camptix_tools&tix_section=indesign_badges' );
	$qr_code_type        = get_option( 'cbg_qr_code_type', 'none' );

	require_once( dirname( __DIR__ ) . '/views/common/page-generate-badges.php' );
}

/**
 * Print CSS styles for wp-admin
 */
function print_admin_styles() {
	$screen = get_current_screen();

	if ( 'tix_ticket_page_camptix_tools' !== $screen->id ) {
		return;
	}

	?>

	<!-- BEGIN CampTix Badge Generator -->
	<style type="text/css">
		<?php require_once( dirname( __DIR__ ) . '/css/common.css' ); ?>
	</style>
	<!-- END CampTix Badge Generator -->

	<?php
}

/**
 * Get the attendees
 *
 * @param string|array $ticket_ids       Filter fetched attendees by the ticket they purchased. The string 'all'
 *                                       will result in all attendees being fetched, regardless of their
 *                                       ticket. An array of ticket IDs will result in only the attendees for
 *                                       those tickets being fetched.
 * @param string       $registered_after Filter fetched attendees by their registration date. Any value parseable
 *                                       by strtotime().
 * @param string       $admin_flag       Filter fetched attendees to only those who have the given admin flag
 *                                       assigned.
 *
 * @todo Maybe optimize this by looking at post_date rather than tix_timestamp
 *
 * @return array
 */
function get_attendees( $ticket_ids = 'all', $registered_after = '', $admin_flag = '' ) {
	$params = array(
		'post_type'      => 'tix_attendee',
		'posts_per_page' => 12000,
		'order'          => 'ASC',
		'orderby'        => 'title',
		'meta_query'     => array(),
	);

	if ( 'all' !== $ticket_ids ) {
		$params['meta_query'][] = array(
			'key'     => 'tix_ticket_id',
			'value'   => $ticket_ids,
			'compare' => 'IN',
		);
	}

	if ( ! empty( $registered_after ) ) {
		$params['meta_query'][] = array(
			'key'     => 'tix_timestamp',
			'value'   => strtotime( $registered_after ),
			'compare' => '>=',
		);
	}

	$attendees = get_posts( $params );

	// "Unknown Attendee" placeholders have no name to print.
	$attendees = array_filter(
		$attendees,
		function ( $attendee ) {
			return 'unknown.attendee@example.org' !== $attendee->tix_email;
		}
	);

	if ( '' !== $admin_flag ) {
		$attendees = array_filter( $attendees, function( $attendee ) use ( $admin_flag ) {
			$flags = get_post_meta( $attendee->ID, 'camptix-admin-flag' );

			return in_array( $admin_flag, $flags, true );
		} );
	}

	return $attendees;
}

/**
 * Add dynamically-generated "post meta" to `\WP_Post` objects
 *
 * This makes it possible to access dynamic data related to a post object by simply referencing `$post->foo`.
 * That keeps the calling code much cleaner than if it were to have to do something like
 * `$foo = some_custom_logic( get_post_meta( $post->ID, 'bar', true ) ); echo esc_html( $foo )`.
 *
 * @param mixed  $value
 * @param int    $post_id
 * @param string $meta_key
 *
 * @return mixed
 *      `null` to instruct `get_metadata()` to pull the value from the database
 *      Any non-null value will be returned as if it were pulled from the database
 */
function add_dynamic_post_meta( $value, $post_id, $meta_key ) {
	/** @global CampTix_Plugin $camptix */
	global $camptix;

	$attendee = get_post( $post_id );

	if ( ! is_a( $attendee, 'WP_Post' ) ) {
		return $value;
	}

	if ( 'tix_attendee' !== $attendee->post_type ) {
		return $value;
	}

	switch ( $meta_key ) {
		case 'avatar_url':
			$value = get_avatar_url(
				$attendee->tix_email,
				array(
					'size'    => 1024,
					'default' => 'blank',
					'rating'  => 'g',
				)
			);
			$value = str_replace( 'secure.gravatar.com', 'en.gravatar.com', $value );   // work around Varnish bug on secure.gravatar.com that causes `503` response codes.
			break;

		case 'coupon':
			if ( $attendee->tix_coupon_id ) {
				$coupon = get_post( $attendee->tix_coupon_id );
				$value  = $coupon->post_name ?? '';
			}
			break;

		case 'css_classes':
			$value = get_css_classes( $attendee );
			break;

		case 'formatted_name':
			$value = $camptix->format_name_string(
				'<span class="first-name">%first%</span>
				 <span class="last-name">%last%</span>',
				$attendee->tix_first_name,
				$attendee->tix_last_name
			);
			break;

		case 'gravatar_profile_url':
			$email = strtolower( trim( (string) $attendee->tix_email ) );
			$value = ! empty( $email ) ? 'https://gravatar.com/' . md5( $email ) : '';
			break;

		case 'wporg_username':
			$value = get_wporg_username( $attendee );
			break;

		case 'hometown':
			$value = get_badge_field( $attendee, 'hometown', 'location' );
			break;

		case 'company':
			$value = get_badge_field( $attendee, 'company', 'company' );
			break;

		case 'website':
			// Gravatar only shares profile links with authenticated API requests, so this comes from a question.
			$value = normalize_website( get_badge_field( $attendee, 'website' ) );
			break;

		case 'website_label':
			$value = get_website_label( $attendee->website );
			break;

		case 'wporg_profile_url':
			// Profile URLs use the nicename, which differs from the login for names with spaces, capitals, etc.
			$user  = get_user_by( 'login', get_wporg_username( $attendee ) );
			$value = $user ? 'https://w.org/@' . rawurlencode( $user->user_nicename ) : '';
			break;

		case 'qr_code_target_url':
			$value = get_qr_code_target_url( $attendee, get_option( 'cbg_qr_code_type', 'none' ) );
			break;

		case 'qr_code_svg':
			$target_url = $attendee->qr_code_target_url;
			$value      = ! empty( $target_url ) ? QR_Code::get_svg( $target_url ) : '';
			break;

		case 'ticket':
			$ticket = get_post( $attendee->tix_ticket_id );
			$value  = $ticket->post_name ?? '';
			break;
	}

	return $value;
}

/**
 * Get an attendee's WordPress.org username
 *
 * @param \WP_Post $attendee
 *
 * @return string
 */
function get_wporg_username( $attendee ) {
	$username = get_post_meta( $attendee->ID, 'tix_username', true );

	if ( empty( $username ) || '[[ unconfirmed ]]' === $username ) {
		return '';
	}

	return trim( $username );
}

/**
 * Get the URL an attendee's QR code links to
 *
 * @param \WP_Post $attendee
 * @param string   $qr_code_type `none`, `gravatar`, or `wporg`.
 *
 * @return string An empty string when there's no QR code.
 */
function get_qr_code_target_url( $attendee, $qr_code_type ) {
	if ( 'gravatar' === $qr_code_type ) {
		return $attendee->gravatar_profile_url;
	}

	if ( 'wporg' === $qr_code_type ) {
		return $attendee->wporg_profile_url;
	}

	return '';
}

/**
 * Get the CampTix questions that organizers mapped to badge fields
 *
 * @return array Question IDs keyed by `hometown`, `website`, and `company`. 0 when a field isn't mapped.
 */
function get_badge_field_questions() {
	$saved     = (array) get_option( 'cbg_field_questions', array() );
	$questions = array();

	foreach ( array_keys( get_badge_field_labels() ) as $field ) {
		$questions[ $field ] = absint( $saved[ $field ] ?? 0 );
	}

	return $questions;
}

/**
 * Get the labels of the badge fields that can be mapped to CampTix questions
 *
 * @return array
 */
function get_badge_field_labels() {
	return array(
		'hometown' => __( 'Hometown', 'wordcamporg' ),
		'website'  => __( 'Website', 'wordcamporg' ),
		'company'  => __( 'Company', 'wordcamporg' ),
	);
}

/**
 * Get a badge field for an attendee
 *
 * The attendee's public Gravatar profile wins, because that's what they've chosen to share publicly. The answer
 * to the question the organizer mapped to the field is the fallback.
 *
 * @param \WP_Post $attendee
 * @param string   $field          `hometown`, `website`, or `company`.
 * @param string   $gravatar_field The matching Gravatar profile field, if there is one.
 *
 * @return string
 */
function get_badge_field( $attendee, $field, $gravatar_field = '' ) {
	if ( $gravatar_field && use_gravatar_profiles() ) {
		$profile = get_gravatar_profile( $attendee->tix_email );

		if ( ! empty( $profile[ $gravatar_field ] ) ) {
			return $profile[ $gravatar_field ];
		}
	}

	$question_id = get_badge_field_questions()[ $field ] ?? 0;
	$answers     = (array) $attendee->tix_questions;

	if ( ! $question_id || ! isset( $answers[ $question_id ] ) ) {
		return '';
	}

	$answer = $answers[ $question_id ];

	return trim( is_array( $answer ) ? implode( ', ', $answer ) : (string) $answer );
}

/**
 * Turn a website answer into a full URL
 *
 * Attendees often type `example.org` without the scheme.
 *
 * @param string $website
 *
 * @return string An empty string when it isn't a usable http(s) URL.
 */
function normalize_website( $website ) {
	$website = trim( (string) $website );

	if ( '' === $website ) {
		return '';
	}

	if ( ! preg_match( '#^https?://#i', $website ) ) {
		$website = 'https://' . ltrim( $website, '/' );
	}

	$website = esc_url_raw( $website, array( 'http', 'https' ) );
	$host    = wp_parse_url( $website, PHP_URL_HOST );

	if ( ! $host || false === strpos( $host, '.' ) ) {
		return '';
	}

	return $website;
}

/**
 * Get the short form of a website to print on a badge
 *
 * @param string $website A URL from normalize_website().
 *
 * @return string For example, `example.org/blog`.
 */
function get_website_label( $website ) {
	$label = preg_replace( '#^https?://(www\.)?#i', '', (string) $website );

	return untrailingslashit( $label );
}

/**
 * Prime the caches needed to render many badges
 *
 * @param array $attendees
 */
function prefetch_badge_data( $attendees ) {
	if ( ! use_gravatar_profiles() ) {
		return;
	}

	$hashes = array();

	foreach ( $attendees as $attendee ) {
		$hashes[] = get_gravatar_hash( $attendee->tix_email );
	}

	prefetch_gravatar_profiles( $hashes );
}

/**
 * Get the CSS classes for an attendee element
 *
 * @param \WP_Post $attendee
 *
 * @return string
 */
function get_css_classes( $attendee ) {
	$classes = array(
		'attendee-' . $attendee->post_name,
		'ticket-'   . $attendee->ticket,
	);

	if ( $attendee->coupon ) {
		$classes[] = 'coupon-' . $attendee->coupon;
	}

	if ( $attendee->qr_code_target_url ) {
		$classes[] = 'has-qr-code';
	}

	return implode( ' ', $classes );
}
