<?php

namespace WordPress_Community\Applications\Meetup;

require_once WCPT_DIR . 'wcpt-meetup/class-meetup-application.php';
use WordPress_Community\Applications\Meetup_Application;

defined( 'WPINC' ) || die();

$meetup_application = new Meetup_Application();

add_shortcode( $meetup_application::SHORTCODE_SLUG, array( $meetup_application, 'render_application_shortcode' ) );

add_action( 'wp_enqueue_scripts', array( $meetup_application, 'enqueue_assets' ), 11 );
