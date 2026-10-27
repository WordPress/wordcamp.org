<?php
/**
 * Read-only previews of the sponsor-group maintenance commands.
 *
 * `wp mes migrate-groups`, `wp mes propagate` and `wp mes seed-flagships` all
 * accept `--dry-run` and report what they would change without writing. Running
 * a shell command needs access that not everyone responsible for the rollout
 * decision has, so this screen exposes the *dry runs only*, in wp-admin.
 *
 * Nothing here writes, and there is deliberately no button that performs a real
 * run. Those stay on WP-CLI, where there is no request timeout -- which matters
 * because propagation inserts posts on every camp's own site.
 */

defined( 'WPINC' ) || die();

/**
 * A dry-run preview screen for the sponsor-group commands.
 */
class MES_Dry_Run_Page {
	/**
	 * The `admin_post` action this screen submits to.
	 */
	const ACTION = 'mes_dry_run';

	/**
	 * The submenu page slug.
	 */
	const PAGE_SLUG = 'mes-dry-run';

	/**
	 * Capability required to view the screen and run a preview.
	 *
	 * Matches every other Multi-Event Sponsors admin surface, including the
	 * `push-to-active-camps` REST route. Widening it is a deliberate access
	 * decision, and is not made here.
	 */
	const CAPABILITY = 'manage_network';

	/**
	 * How long a preview result is held for the user who ran it, in seconds.
	 */
	const RESULT_TTL = 300;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
	}

	/**
	 * The previews this screen offers.
	 *
	 * A closed set. `run_task()` matches against these keys and treats anything
	 * else as unknown, so a crafted request cannot reach an arbitrary callable.
	 *
	 * @return array Task slug => human-readable label.
	 */
	public static function tasks() {
		return array(
			'migrate-groups' => __( 'Migrate sponsor regions to groups', 'wordcamporg' ),
			'propagate'      => __( 'Propagate sponsors to their camps', 'wordcamporg' ),
			'seed-flagships' => __( 'Seed the flagship events group', 'wordcamporg' ),
		);
	}

	/**
	 * Add the screen under the Multi-Event Sponsors post type.
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . MES_Sponsor::POST_TYPE_SLUG,
			__( 'Sponsor Groups Dry Run', 'wordcamporg' ),
			__( 'Dry Run', 'wordcamporg' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Run one preview.
	 *
	 * The seam this screen is tested through: no nonce, no redirect, no output,
	 * so the behaviour can be asserted directly.
	 *
	 * @param string $task One of the keys from `tasks()`.
	 *
	 * @return array|WP_Error The command's summary, or an error.
	 */
	public static function run_task( $task ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'mes_dry_run_forbidden',
				__( 'You are not allowed to preview sponsor group changes.', 'wordcamporg' )
			);
		}

		if ( ! array_key_exists( $task, self::tasks() ) ) {
			return new WP_Error(
				'mes_dry_run_unknown_task',
				__( 'Unknown preview.', 'wordcamporg' )
			);
		}

		switch ( $task ) {
			case 'migrate-groups':
				return MES_Migrate_Groups::run( true );

			case 'propagate':
				return MES_Propagator::run( 0, true );

			case 'seed-flagships':
				return MES_Flagships::seed( array(), true );
		}

		// Unreachable: the allowlist above is the same set as this switch.
		return new WP_Error(
			'mes_dry_run_unknown_task',
			__( 'Unknown preview.', 'wordcamporg' )
		);
	}

	/**
	 * Handle the form submission, then redirect back to the screen.
	 */
	public function handle_post() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to preview sponsor group changes.', 'wordcamporg' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$task   = isset( $_POST['mes_task'] ) ? sanitize_key( wp_unslash( $_POST['mes_task'] ) ) : '';
		$result = self::run_task( $task );

		set_transient(
			self::result_transient_key( get_current_user_id() ),
			array(
				'task'   => $task,
				'result' => is_wp_error( $result ) ? $result->get_error_message() : $result,
				'error'  => is_wp_error( $result ),
			),
			self::RESULT_TTL
		);

		wp_safe_redirect( self::page_url() );

		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to preview sponsor group changes.', 'wordcamporg' ), '', array( 'response' => 403 ) );
		}

		$key    = self::result_transient_key( get_current_user_id() );
		$last   = get_transient( $key );
		$tasks  = self::tasks();
		$action = self::ACTION;

		if ( $last ) {
			delete_transient( $key );
		}

		require dirname( __DIR__ ) . '/views/page-dry-run.php';
	}

	/**
	 * Turn one summary value into the lines shown for it.
	 *
	 * Counts and flags are one line. A list value is a line per entry: `camps_skipped_no_author`
	 * lists camp post IDs, shown with the camp's title, and `regions_skipped` maps region IDs to names.
	 *
	 * @param mixed $value
	 *
	 * @return string[] Plain text, not escaped.
	 */
	public static function format_value( $value ) {
		if ( is_bool( $value ) ) {
			return array( $value ? __( 'yes', 'wordcamporg' ) : __( 'no', 'wordcamporg' ) );
		}

		if ( ! is_array( $value ) ) {
			return array( number_format_i18n( $value ) );
		}

		if ( ! $value ) {
			return array( __( 'none', 'wordcamporg' ) );
		}

		$lines = array();

		foreach ( $value as $key => $item ) {
			$lines[] = array_is_list( $value )
				? sprintf( '%d: %s', $item, get_the_title( $item ) )
				: sprintf( '%d: %s', $key, $item );
		}

		return $lines;
	}

	/**
	 * The screen's own URL.
	 *
	 * @return string
	 */
	public static function page_url() {
		return add_query_arg(
			array(
				'post_type' => MES_Sponsor::POST_TYPE_SLUG,
				'page'      => self::PAGE_SLUG,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Transient key holding the last preview for one user.
	 *
	 * Scoped per user so two people previewing at once don't read each other's
	 * results.
	 *
	 * @param int $user_id
	 *
	 * @return string
	 */
	protected static function result_transient_key( $user_id ) {
		return 'mes_dry_run_result_' . absint( $user_id );
	}
}
