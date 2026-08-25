<?php

defined( 'WPINC' ) || die();

/**
 * Shared fixtures for the WordCamp post type suites.
 *
 * `$GLOBALS['wordcamp_admin']` is one instance for the whole run, so anything keyed on the
 * current user has to be set up per test rather than per class.
 */
trait WordCamp_Fixtures {
	/**
	 * Create a Contributor and make them the current user.
	 *
	 * @return int The user ID.
	 */
	protected function become_contributor() {
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Give the current user the WordCamp Wrangler capability.
	 *
	 * It has to come through the subroles system: `omit_usermeta_caps()` deliberately
	 * strips anything granted with `WP_User::add_cap()`.
	 */
	protected function become_wrangler() {
		$GLOBALS['wcorg_subroles'][ get_current_user_id() ] = array( 'wordcamp_wrangler' );
	}

	/**
	 * Create an application.
	 *
	 * @param int    $author_id The lead organizer.
	 * @param string $status    The application's status.
	 *
	 * @return int The post ID.
	 */
	protected function create_wordcamp( $author_id, $status = WCPT_DEFAULT_STATUS ) {
		return self::factory()->post->create(
			array(
				'post_type'   => WCPT_POST_TYPE_ID,
				'post_status' => $status,
				'post_author' => $author_id,
			)
		);
	}

	/**
	 * Build a WordCamp query that reports itself as the main query.
	 *
	 * `WP_Query::is_main_query()` compares against `$wp_the_query`, which the harness
	 * replaces in `tear_down()`.
	 *
	 * @return \WP_Query
	 */
	protected function build_main_query() {
		$query = new \WP_Query();
		$query->set( 'post_type', WCPT_POST_TYPE_ID );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `is_main_query()` compares against it.
		$GLOBALS['wp_the_query'] = $query;

		return $query;
	}
}
