<?php
defined( 'WPINC' ) || die();

use WP\MCP\Core\McpAdapter;

/**
 * Registers Campus Connect vetting Abilities and exposes them via a dedicated
 * MCP server (wcpt-vetting).
 *
 * Replaces the custom REST vetting controller from PR #1720. External agents
 * (wpcc_auto_agent.py and the GPL/trademark checker) connect to the MCP server
 * at /wp-json/mcp/wcpt-vetting and call these abilities via the standard
 * execute-ability tool instead of raw REST endpoints.
 *
 * Requires:
 *   - WordPress 6.9+ (Abilities API in core).
 *   - wordpress/mcp-adapter package (installed via wcpt/vendor/).
 *
 * Authentication: WordPress Application Password for a user holding the
 * `wordcamp_wrangle_wordcamps` capability (same as the former REST controller).
 *
 * @package WordCamp\WCPT
 */
class WCPT_Vetting_Abilities {

	/**
	 * MCP server ID for the wcpt vetting server.
	 *
	 * The server is accessible at /wp-json/mcp/wcpt-vetting.
	 *
	 * @var string
	 */
	const SERVER_ID = 'wcpt-vetting';

	/**
	 * Ability name: fetch the CC vetting queue.
	 *
	 * @var string
	 */
	const ABILITY_GET_QUEUE = 'wcpt/get-vetting-queue';

	/**
	 * Ability name: process (vet) a single CC application.
	 *
	 * @var string
	 */
	const ABILITY_PROCESS = 'wcpt/process-application';

	/**
	 * Bootstrap: register abilities on wp_abilities_api_init and the MCP server
	 * on mcp_adapter_init. Both hooks fire on init (priority 10+).
	 */
	public static function init() {
		// Guard: MCP adapter package must be loaded.
		if ( ! class_exists( McpAdapter::class ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init',            array( __CLASS__, 'register_abilities' ) );
		add_action( 'mcp_adapter_init',                 array( __CLASS__, 'register_mcp_server' ) );
	}

	/**
	 * Register the 'wcpt' ability category.
	 *
	 * @hooked action wp_abilities_api_categories_init
	 */
	public static function register_category() {
		wp_register_ability_category(
			'wcpt',
			array(
				'label'       => __( 'WordCamp Post Type', 'wordcamporg' ),
				'description' => __( 'Abilities for managing WordCamp and Campus Connect posts.', 'wordcamporg' ),
			)
		);
	}

	/**
	 * Register the two vetting abilities with the WordPress Abilities API.
	 *
	 * Both abilities are marked mcp.public so they are discoverable through our
	 * custom MCP server (see register_mcp_server()). They are intentionally NOT
	 * exposed on the default MCP server to keep them isolated.
	 */
	public static function register_abilities() {

		// ── Ability 1: get-vetting-queue ──────────────────────────────────────
		wp_register_ability(
			self::ABILITY_GET_QUEUE,
			array(
				'label'            => __( 'Get Campus Connect Vetting Queue', 'wordcamporg' ),
				'description'      => __( 'Returns a paginated list of Campus Connect posts currently in Needs Vetting status, oldest first.', 'wordcamporg' ),
				'category'         => 'wcpt',
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'page'     => array(
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
							'description' => 'Page of results to return.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => 'Number of items per page (max 100).',
						),
					),
				),
				'execute_callback' => array( __CLASS__, 'execute_get_queue' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'meta'             => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);

		// ── Ability 2: process-application ───────────────────────────────────
		wp_register_ability(
			self::ABILITY_PROCESS,
			array(
				'label'            => __( 'Process Campus Connect Application', 'wordcamporg' ),
				'description'      => __( 'Attaches a vetting note to a Campus Connect post and advances it from Needs Vetting to Needs Action.', 'wordcamporg' ),
				'category'         => 'wcpt',
				'input_schema'     => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'note' ),
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID of the Campus Connect post to process.',
						),
						'note'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Vetting notes to attach as a private note on the post.',
						),
					),
				),
				'execute_callback' => array( __CLASS__, 'execute_process_application' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'meta'             => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Register a dedicated MCP server scoped to the two vetting abilities.
	 *
	 * Isolating them to their own server (wcpt-vetting) keeps them separate from
	 * any other abilities registered site-wide and makes the endpoint URL clear
	 * for the Render.com agent runner.
	 *
	 * Endpoint: /wp-json/mcp/wcpt-vetting
	 *
	 * @param McpAdapter $adapter The MCP Adapter instance.
	 */
	public static function register_mcp_server( McpAdapter $adapter ) {
		$adapter->create_server(
			self::SERVER_ID,
			'mcp',
			self::SERVER_ID,
			__( 'WCPT Vetting', 'wordcamporg' ),
			__( 'Campus Connect application vetting queue and processing for automated agents.', 'wordcamporg' ),
			'1.0.0',
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			array(
				self::ABILITY_GET_QUEUE,
				self::ABILITY_PROCESS,
			),
			array(), // Resources.
			array()  // Prompts.
		);
	}

	// ── Permission ────────────────────────────────────────────────────────────

	/**
	 * Shared permission callback for both abilities.
	 *
	 * Mirrors the check used by WordCamp_Admin::enforce_post_status().
	 *
	 * @return bool
	 */
	public static function check_permission() {
		return is_user_logged_in() && current_user_can( 'wordcamp_wrangle_wordcamps' );
	}

	// ── Ability executors ─────────────────────────────────────────────────────

	/**
	 * Execute: get-vetting-queue
	 *
	 * Returns a paginated list of Campus Connect posts in wcpt-needs-vetting
	 * status, ordered oldest-first (submission order). Each item includes the
	 * application fields the vetting agents need.
	 *
	 * @param array $input Validated input: page, per_page.
	 * @return array { items: array, total: int, total_pages: int }
	 */
	public static function execute_get_queue( array $input ) {
		$page     = absint( $input['page']     ?? 1  );
		$per_page = absint( $input['per_page'] ?? 20 );
		$per_page = min( $per_page, 100 );

		$query = new WP_Query(
			array(
				'post_type'      => WCPT_POST_TYPE_ID,
				'post_status'    => 'wcpt-needs-vetting',
				'meta_key'       => 'event_subtype',
				'meta_value'     => 'campusconnect',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$meta_fields = array(
			'Organizer Name',
			'WordPress.org Username',
			'Email Address',
			'Location',
			'Number of Anticipated Attendees',
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$meta = array();
			foreach ( $meta_fields as $field ) {
				$meta[ $field ] = get_post_meta( $post->ID, $field, true );
			}
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'date'     => $post->post_date,
				'content'  => $post->post_content,
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				'meta'     => $meta,
			);
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Execute: process-application
	 *
	 * Attaches a vetting note and advances the post to wcpt-needs-action.
	 * Includes an optimistic concurrency re-check after writing the note to
	 * guard against concurrent status changes.
	 *
	 * On success returns: { id, old_status, new_status }.
	 * On failure throws a RuntimeException with a descriptive message and an
	 * 'error_code' key in its context (checked by the MCP adapter).
	 *
	 * @param array $input Validated input: post_id (int), note (string).
	 * @return array
	 * @throws \RuntimeException On validation or write failure.
	 */
	public static function execute_process_application( array $input ) {
		$post_id = absint( $input['post_id'] );
		$note    = sanitize_textarea_field( wp_unslash( $input['note'] ?? '' ) );

		// ── Validate post ────────────────────────────────────────────────────
		$post = get_post( $post_id );

		if ( ! $post || WCPT_POST_TYPE_ID !== $post->post_type ) {
			throw new \RuntimeException(
				esc_html__( 'Post not found.', 'wordcamporg' ),
				404
			);
		}

		if ( 'campusconnect' !== get_post_meta( $post_id, 'event_subtype', true ) ) {
			throw new \RuntimeException(
				esc_html__( 'This post is not a Campus Connect application.', 'wordcamporg' ),
				400
			);
		}

		if ( '' === $note ) {
			throw new \RuntimeException(
				esc_html__( 'A vetting note is required.', 'wordcamporg' ),
				400
			);
		}

		if ( 'wcpt-needs-vetting' !== $post->post_status ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: current post status slug */
					esc_html__( 'Expected status wcpt-needs-vetting, got %s.', 'wordcamporg' ),
					esc_html( $post->post_status )
				),
				409
			);
		}

		// ── 1. Write the note first ──────────────────────────────────────────
		$note_id = wcpt_add_private_note( $post_id, $note, get_current_user_id() );

		if ( ! $note_id ) {
			throw new \RuntimeException(
				esc_html__( 'Could not save the vetting note. Status was not changed.', 'wordcamporg' ),
				500
			);
		}

		// ── 2. Optimistic concurrency re-check ───────────────────────────────
		$post = get_post( $post_id );
		if ( ! $post || 'wcpt-needs-vetting' !== $post->post_status ) {
			throw new \RuntimeException(
				esc_html__( 'The application status changed while this request was being processed. Please reload and try again.', 'wordcamporg' ),
				409
			);
		}

		// ── 3. Advance the status ────────────────────────────────────────────
		$old_status = $post->post_status;
		$result     = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'wcpt-needs-action',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ), 500 );
		}

		// ── 4. Log the transition ────────────────────────────────────────────
		self::log_status_transition( $post_id, $old_status, 'wcpt-needs-action' );

		return array(
			'id'         => $post_id,
			'old_status' => $old_status,
			'new_status' => 'wcpt-needs-action',
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Write a status-change log entry using CC-specific labels.
	 *
	 * Follows the same storage format as Event_Admin::log_status_changes().
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $old_status Old post status slug.
	 * @param string $new_status New post status slug.
	 */
	protected static function log_status_transition( $post_id, $old_status, $new_status ) {
		$locale_switched = switch_to_locale( 'en_US' );

		$cc_statuses = WordCamp_Loader::get_campus_connect_statuses();
		$old_label   = $cc_statuses[ $old_status ] ?? $old_status;
		$new_label   = $cc_statuses[ $new_status ] ?? $new_status;

		$log_id = add_post_meta(
			$post_id,
			'_status_change',
			array(
				'timestamp' => time(),
				'user_id'   => get_current_user_id(),
				'message'   => sprintf( '%s &rarr; %s', $old_label, $new_label ),
			)
		);

		if ( $log_id ) {
			add_post_meta( $post_id, '_status_change_log_' . WCPT_POST_TYPE_ID . ' ' . $log_id, time() );
		}

		if ( $locale_switched ) {
			restore_previous_locale();
		}
	}
}
