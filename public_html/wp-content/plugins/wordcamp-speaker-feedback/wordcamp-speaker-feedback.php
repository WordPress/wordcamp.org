<?php
/**
 * Plugin Name:     WordCamp Speaker Feedback
 * Plugin URI:      https://wordcamp.org
 * Description:     Tools to provide feedback to speakers at WordCamp events.
 * Author:          WordCamp.org
 * Author URI:      https://wordcamp.org
 * Version:         1
 *
 * @package         WordCamp\SpeakerFeedback
 */

namespace WordCamp\SpeakerFeedback;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', \plugins_url( '/', __FILE__ ) );


