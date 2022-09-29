<?php

/*
 * Miscellaneous cron jobs that don't fit anywhere else.
 *
 * These aren't setup as Unix cron jobs, because adding/modifying those would require a systems request. It's
 * quicker and more convenient for devs to launch these at the application layer.
 *
 *
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- `exec()` is intentionally used here,
 * and approved by the Systems team. See https://make.wordpress.org/systems/?p=1273.
 */

namespace WordCamp\Cron;
defined( 'WPINC' ) || die();

// Allocate additional ram, since jobs sometimes need to loop through all sites.
if ( wp_doing_cron() ) {
	wp_raise_memory_limit( 'wordcamp_high' );
}

if ( 'production' === WORDCAMP_ENVIRONMENT && is_main_site() ) {
	add_action( 'init',                __NAMESPACE__ . '\schedule_daily_jobs' );
	add_action( 'wordcamp_daily_jobs', __NAMESPACE__ . '\execute_daily_jobs' );
}


/**
 * Schedule cron jobs.
 */
function schedule_daily_jobs() {
	// Run when there's the highest likelihood that a developer will be online, in case something goes wrong.
	$next_safe_time = strtotime( 'Next weekday 10am America/Los_Angeles' );

	if ( ! wp_next_scheduled( 'wordcamp_daily_jobs' ) ) {
		wp_schedule_single_event( $next_safe_time, 'wordcamp_daily_jobs' );
	}
}

/**
 * Execute jobs that should run once a day.
 */
function execute_daily_jobs() {
	/*
	 * Install localizations updates for the `wordcamporg` text domain.
	 *
	 * Ideally this would run hourly, but `checkIfPOHasNoStringChanges()` is not accurate enough, and some
	 * immaterial changes often slip through. Running daily mitigates the unnecessary commits cluttering the SVN
	 * history.
	 */
	exec( WORDCAMP_SCRIPT_DIR . '/bash/lang-update.sh 2>&1', $full_output, $exit_code );

	if ( 0 !== $exit_code ) {
		trigger_error(
			'lang-update error: ' . esc_html( implode( $full_output ) ),
			E_USER_WARNING
		);
	}
}

// phpcs:enable
