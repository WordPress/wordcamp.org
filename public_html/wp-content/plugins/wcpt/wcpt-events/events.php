<?php

namespace WordPress_Events\Applications\Events;

require_once WCPT_DIR . 'wcpt-events/class-events-application.php';

defined( 'WPINC' ) || die();

$events_application = new Events_Application();

add_shortcode( $events_application::SHORTCODE_SLUG, array( $events_application, 'render_application_shortcode' ) );

add_action( 'wp_enqueue_scripts', array( $events_application, 'enqueue_assets' ), 11 );