<?php
/*
Plugin Name: WordCamp Forms WordPress.org login requirement
Description:
Version:     0.1
Author:      WordCamp Central
Author URI:  http://wordcamp.org
*/

namespace WordCamp\Forms_Worg_Login_Requirement;

defined( 'WPINC' ) || die();

add_action( 'wp_print_styles', __NAMESPACE__ . '\print_front_end_styles' );
add_filter( 'the_content', __NAMESPACE__ . '\force_login_to_use_form', 15 );

function get_require_settings() {
  return array(
    // Meetup organizer application
    '3070672' => array(
      'start'   => '<form id="meetup-application"',
      'end'     => '</form>',
      'message' => sprintf( __( 'Before submitting your Meetup Organizer Application, please <a href="%s">log in to WordCamp.org</a> using your <strong>WordPress.org</strong>* account.', 'wordcamporg' ), wp_login_url( get_permalink() ) ),
    ),
  );
}

function maybe_require_login() {
  if ( is_user_logged_in() ) {
    return false;
  }

  $require = false;
  $require_settings = get_require_settings();

  // Check central.wordcamp.org pages
  if ( is_main_site() ) {
    // Meetup organizer application
    if ( 3070672 === get_the_id() ) {
      $require = '3070672';
    }
  }

  // Return settings for this spesific require case
  if ( isset( $require_settings[ $require ] ) ) {
    $require = $require_settings[ $require ];
  }

  return apply_filters( 'forms_worg_login_required', $require );
}

/**
 * Print CSS for the front-end
 */
function print_front_end_styles() {
  if ( ! maybe_require_login() ) {
    return;
  } ?>

  <style>
    <?php require_once( __DIR__ . '/front-end.css' ); ?>
  </style>

<?php }

/**
 * Force user to login to use certain forms.
 *
 * @param string $content
 *
 * @return string
 */
function force_login_to_use_form( $content ) {
  $require_settings = maybe_require_login();

  if ( ! $require_settings ) {
    return $content;
  }

  return inject_disabled_form_elements( $content, $require_settings['start'], $require_settings['end'], $require_settings['message'] );
}

/**
 * Inject the HTML elements that are used to disable a form until the user logs in
 *
 * @param string $content
 * @param string $please_login_message
 *
 * @return string
 */
function inject_disabled_form_elements( $content, $el_start, $el_end, $please_login_message ) {
  $please_login_message = str_replace(
    __( 'Please use your <strong>WordPress.org</strong>* account to log in.', 'wordcamporg' ),
    $please_login_message,
    wcorg_login_message( '', get_permalink() )
  );

  // Prevent wpautop() from converting tabs into empty paragraphs in #wcorg-login-message.
  $please_login_message = trim( str_replace( "\t", '', $please_login_message ) );

  $form_wrapper = '<div class="wcfd-disabled-form">' . $please_login_message . '<div class="wcfd-overlay"></div> '. $el_start;
  $content      = str_replace( $el_start, $form_wrapper, $content );
  $content      = str_replace( $el_end, $el_end . '</div>', $content );

  return $content;
}
