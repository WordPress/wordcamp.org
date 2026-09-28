<?php
defined( 'WPINC' ) || die();

/**
 * Enforces which post status a WordCamp application may be written to.
 *
 * Not in `WordCamp_Admin`: `WCPT_Loader::core_admin()` only constructs that class for
 * admin and cron requests, and applications are also written over REST and by WP-CLI.
 *
 * @package WordCamp\WCPT
 */
class WordCamp_Status_Guard {

	/**
	 * Register the guard early: `WordCamp_Admin::require_complete_meta_to_publish_wordcamp()`
	 * runs at 11 and expects the status to be settled.
	 */
	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'enforce_post_status' ), 9, 2 );
	}

	/**
	 * Enforce a valid post status for WordCamps.
	 *
	 * @param array $post_data
	 * @param array $post_data_raw
	 * @return array
	 */
	public static function enforce_post_status( $post_data, $post_data_raw ) {
		if ( WCPT_POST_TYPE_ID != $post_data['post_type'] ) {
			return $post_data;
		}

		/*
		 * Cron and WP-CLI without a user are the workflow's own writers:
		 * `close_wordcamps_after_event()` sets `wcpt-closed` from cron, and operators move
		 * applications from the command line. A user set with `wp --user` is held to the
		 * capability like any other.
		 */
		$system_context = wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI && ! is_user_logged_in() );

		// Not `WordCamp_Admin::get_edit_capability()`: that class is admin and cron only.
		$may_set_status = $system_context || current_user_can( 'wordcamp_wrangle_wordcamps' );

		/*
		 * Updates only. An insert reaching `wp_insert_post()` has already been decided by the
		 * caller, and clamping there would overrule trusted server-side code that names a
		 * status on purpose. What keeps an application from being created at an arbitrary
		 * status is `create_posts`, which `register_post_type()` maps to the curating
		 * capability, so that mapping is load-bearing and should stay as strict as it is.
		 */
		if ( empty( $post_data_raw['ID'] ) ) {
			return $post_data;
		}

		$post = get_post( $post_data_raw['ID'] );
		if ( ! $post ) {
			return $post_data;
		}

		if ( ! empty( $post_data['post_status'] ) ) {
			// The curating role owns the workflow, so a status only moves when they say so.
			if ( ! $may_set_status ) {
				$post_data['post_status'] = $post->post_status;
			}

			// Enforce a valid status. Include all global statuses plus CC-exclusive ones.
			$statuses   = array_keys( WordCamp_Loader::get_post_statuses() );
			$statuses[] = 'trash';

			if ( ! in_array( $post_data['post_status'], $statuses, true ) ) {
				$post_data['post_status'] = WCPT_DEFAULT_STATUS;
			}

			// Block CC-exclusive statuses from being applied to non-Campus-Connect posts.
			if ( 'wcpt-needs-action' === $post_data['post_status'] && ! self::is_campus_connect_post_for_save( $post_data_raw['ID'] ) ) {
				$post_data['post_status'] = $post->post_status;
			}
		}

		return $post_data;
	}

	/**
	 * Check whether a post being saved is a Campus Connect event.
	 *
	 * Uses the submitted subtype when present so direct POSTs are validated
	 * against the value being saved, then falls back to stored post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_campus_connect_post_for_save( $post_id ) {
		$submitted_subtype = sanitize_text_field( wp_unslash( $_POST['event_subtype'] ?? '' ) );
		$event_subtype     = $submitted_subtype ?: get_post_meta( absint( $post_id ), 'event_subtype', true );

		return 'campusconnect' === $event_subtype;
	}
}
