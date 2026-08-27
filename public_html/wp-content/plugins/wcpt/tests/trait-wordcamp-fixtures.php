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
	 * Run the query the WordCamp list screen produces, list table included.
	 *
	 * `build_main_query()` alone is not that screen. `WP_Posts_List_Table::__construct()`
	 * sets `$_GET['author']` for a viewer without `edit_others_posts` who has authored one
	 * of these, and `wp_edit_posts_query()` then hands it to the main query, so a scoping
	 * bug that only shows up once those two have run is invisible to a bare `WP_Query`.
	 *
	 * @return \WP_Query
	 */
	protected function run_list_screen_query() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `edit.php` sets this.
		$GLOBALS['typenow'] = WCPT_POST_TYPE_ID;

		// `wp_edit_posts_query()` reads the type off the request, not off `$typenow`.
		$_GET['post_type']     = WCPT_POST_TYPE_ID;
		$_REQUEST['post_type'] = WCPT_POST_TYPE_ID;

		set_current_screen( 'edit-' . WCPT_POST_TYPE_ID );

		new \WP_Posts_List_Table( array( 'screen' => get_current_screen() ) );

		/*
		 * `WP->main()` runs the query and then fires `wp`, where `maybe_add_latest_site_hints()`
		 * switches to a blog this environment does not have. The query is already done by then,
		 * so drop the callbacks. `WP_UnitTestCase` restores the hook registry after the test.
		 */
		remove_all_actions( 'wp' );

		wp_edit_posts_query();

		return $GLOBALS['wp_query'];
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
