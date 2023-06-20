<?php

namespace WordPress_Community\Applications\Pilot_Event;

defined( 'WPINC' ) or die();

add_shortcode( $wordcamp_application::SHORTCODE_SLUG, array( $wordcamp_application, 'render_application_shortcode' ) );

add_action( 'wp_enqueue_scripts', array( $wordcamp_application, 'enqueue_common_assets' ), 11 );

add_action( 'wp_enqueue_scripts', array( $wordcamp_application, 'enqueue_assets' ) );

