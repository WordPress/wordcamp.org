<?php

/**
 * If you're writing an addon, make sure you extend from this class.
 *
 * @since 1.1
 */
abstract class CampTix_Addon {
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'camptix_init', array( $this, 'camptix_init' ) );
	}

	/**
	 * Initialization
	 */
	public function camptix_init() {}

	/**
	 * Filter callback: Set the locale to `en_US`.
	 *
	 * For some purposes, such as internal logging, strings that would normally be translated to the
	 * current user's locale should be in English, so that other users who may not share the same
	 * locale can read them.
	 *
	 * @return string
	 */
	public function set_locale_to_en_us() {
		return 'en_US';
	}
}

/**
 * Register an addon
 *
 * @param string $class_name
 *
 * @return bool
 */
function camptix_register_addon( $class_name ) {
	/** @var $camptix CampTix_Plugin */
	global $camptix;

	return $camptix->register_addon( $class_name );
}
