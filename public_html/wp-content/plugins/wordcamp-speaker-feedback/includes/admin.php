<?php

namespace WordCamp\SpeakerFeedback\Admin;

use WordCamp\SpeakerFeedback\Feedback_List_Table;
use function WordCamp\SpeakerFeedback\{ get_assets_path, get_includes_path, get_views_path, get_assets_url };
use function WordCamp\SpeakerFeedback\Comment\{
	count_feedback, get_feedback, get_feedback_comment, delete_feedback,
	is_feedback, mark_feedback_inappropriate, unmark_feedback_inappropriate,
};
use function WordCamp\SpeakerFeedback\CommentMeta\{ get_feedback_questions, count_helpful_feedback };
use const WordCamp\SpeakerFeedback\SUPPORTED_POST_TYPES;
use const WordCamp\SpeakerFeedback\Comment\COMMENT_TYPE;

defined( 'WPINC' ) || die();

foreach ( SUPPORTED_POST_TYPES as $supported_post_type ) {
	add_filter( "manage_{$supported_post_type}_posts_columns", __NAMESPACE__ . '\add_post_list_table_columns' );
	add_action( "manage_{$supported_post_type}_posts_custom_column", __NAMESPACE__ . '\render_post_list_table_columns', 10, 2 );
}

add_action( 'admin_menu', __NAMESPACE__ . '\add_subpages' );
add_action( 'current_screen', __NAMESPACE__ . '\add_feedback_bubble' );
add_filter( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_filter( 'set-screen-option', __NAMESPACE__ . '\set_screen_options', 10, 3 );
add_filter( 'wp_count_comments', __NAMESPACE__ . '\adjust_comment_counts', 10, 2 );
add_filter( 'pre_wp_update_comment_count_now', __NAMESPACE__ . '\adjust_post_comment_count', 10, 3 );
add_filter( 'comment_notification_recipients', __NAMESPACE__ . '\remove_email_recipients', 10, 2 );
add_filter( 'comment_row_actions', __NAMESPACE__ . '\feedback_extra_actions', 10, 2 );

// Priority 0 to run before the core `wp_ajax_dim_comment`, which exits after running.
add_action( 'wp_ajax_dim-comment', __NAMESPACE__ . '\wp_ajax_mark_inappropriate', 0 );

/**
 * Add a Speaker Feedback column for post list tables that support speaker feedback.
 *
 * @param array $columns
 *
 * @return array
 */
function add_post_list_table_columns( $columns ) {
	$columns = array_slice( $columns, 0, -1, true )
		+ array( 'count_' . COMMENT_TYPE => __( 'Speaker Feedback', 'wordcamporg' ) )
		+ array_slice( $columns, -1, 1, true );

	return $columns;
}

/**
 * Render the cell contents for the Speaker Feedback column on list tables.
 *
 * @param string $column_name
 * @param int    $post_id
 *
 * @return void
 */
function render_post_list_table_columns( $column_name, $post_id ) {
	global $wp_list_table;

	switch ( $column_name ) {
		case 'count_' . COMMENT_TYPE:
			// The `column-comments` class is added here since it can't be injected into the usual `td` element.
			// This gives us the same comment bubble styles for free.
			?>
			<div class="column-comments post-com-count-wrapper">
			<?php feedback_bubble( $post_id ); ?>
			</div>
			<?php
			break;
	}
}

/**
 * Output a graphical representation of the approved/pending feedback comments for a particular post.
 *
 * This is based on the `comment_bubble` method in `WP_List_Table`.
 *
 * @param int $post_id
 *
 * @return void
 */
function feedback_bubble( $post_id ) {
	$feedback = get_feedback( array( $post_id ), array( 'approve', 'hold' ) );

	$counted_feedback = array_reduce(
		$feedback,
		function( $carry, $item ) {
			if ( in_array( $item->comment_approved, array( 1, '1', 'approve' ), true ) ) {
				$carry['approve'] ++;
			} else {
				$carry['hold'] ++;
			}

			return $carry;
		},
		array(
			'approve' => 0,
			'hold'    => 0,
		)
	);

	$counted_feedback_label = array_map( 'number_format_i18n', $counted_feedback );

	$approved_only_phrase = sprintf(
		/* translators: %s: Number of comments. */
		_n( '%s comment', '%s comments', $counted_feedback['approve'], 'wordcamporg' ),
		$counted_feedback_label['approve']
	);

	$approved_phrase = sprintf(
		/* translators: %s: Number of comments. */
		_n( '%s approved comment', '%s approved comments', $counted_feedback['approve'], 'wordcamporg' ),
		$counted_feedback_label['approve']
	);

	$pending_phrase = sprintf(
		/* translators: %s: Number of comments. */
		_n( '%s pending comment', '%s pending comments', $counted_feedback['hold'], 'wordcamporg' ),
		$counted_feedback_label['hold']
	);

	// No comments at all.
	if ( ! $counted_feedback['approve'] && ! $counted_feedback['hold'] ) {
		printf(
			'<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">%s</span>',
			esc_html__( 'No comments', 'wordcamporg' )
		);
		// Approved comments have different display depending on some conditions.
	} elseif ( $counted_feedback['approve'] ) {
		printf(
			'<a href="%s" class="post-com-count post-com-count-approved"><span class="comment-count-approved" aria-hidden="true">%s</span><span class="screen-reader-text">%s</span></a>',
			esc_url(
				add_query_arg(
					array(
						'p'              => $post_id,
						'comment_status' => 'approved',
					),
					get_subpage_url( get_post_type( $post_id ) )
				)
			),
			esc_html( $counted_feedback_label['approve'] ),
			$counted_feedback['hold'] ? esc_html( $approved_phrase ) : esc_html( $approved_only_phrase )
		);
	} else {
		printf(
			'<span class="post-com-count post-com-count-no-comments"><span class="comment-count comment-count-no-comments" aria-hidden="true">%s</span><span class="screen-reader-text">%s</span></span>',
			esc_html( $counted_feedback_label['approve'] ),
			$counted_feedback['hold'] ? esc_html__( 'No approved comments', 'wordcamporg' ) : esc_html__( 'No comments', 'wordcamporg' )
		);
	}

	if ( $counted_feedback['hold'] ) {
		printf(
			'<a href="%s" class="post-com-count post-com-count-pending"><span class="comment-count-pending" aria-hidden="true">%s</span><span class="screen-reader-text">%s</span></a>',
			esc_url(
				add_query_arg(
					array(
						'p'              => $post_id,
						'comment_status' => 'moderated',
					),
					get_subpage_url( get_post_type( $post_id ) )
				)
			),
			esc_html( $counted_feedback_label['hold'] ),
			esc_html( $pending_phrase )
		);
	} else {
		printf(
			'<span class="post-com-count post-com-count-pending post-com-count-no-pending"><span class="comment-count comment-count-no-pending" aria-hidden="true">%s</span><span class="screen-reader-text">%s</span></span>',
			esc_html( $counted_feedback_label['hold'] ),
			$counted_feedback['approve'] ? esc_html__( 'No pending comments', 'wordcamporg' ) : esc_html__( 'No comments', 'wordcamporg' )
		);
	}
}

/**
 * Register an admin page for each post type that supports speaker feedback.
 *
 * @return void
 */
function add_subpages() {
	foreach ( SUPPORTED_POST_TYPES as $supported_post_type ) {
		$parent_slug = add_query_arg( 'post_type', $supported_post_type, 'edit.php' );

		add_submenu_page(
			$parent_slug,
			__( 'Speaker Feedback', 'wordcamporg' ),
			__( 'Feedback', 'wordcamporg' ),
			'moderate_' . COMMENT_TYPE,
			COMMENT_TYPE,
			__NAMESPACE__ . '\render_subpage'
		);

		$page_hook = get_plugin_page_hook( COMMENT_TYPE, $parent_slug );

		add_action(
			"load-$page_hook",
			function() {
				// This is a hack to ensure that the list table columns are registered properly. It has to happen
				// before the subpage's render function is called.
				get_feedback_list_table();

				// This also has to be called before the render function fires.
				add_screen_option( 'per_page' );
			}
		);
	}
}

/**
 * Display the count of unapproved speaker feedback comments, injected into the admin menu.
 *
 * Run on current_screen so we can conditionally add this to the top-level CPT item, or the Feedback item in
 * the submenu.
 *
 * @param WP_Screen $screen Current WP_Screen object.
 */
function add_feedback_bubble( $screen ) {
	global $menu, $submenu;
	if ( ! isset( $menu ) || empty( $menu ) ) {
		return;
	}
	if ( ! current_user_can( 'moderate_' . COMMENT_TYPE ) ) {
		return;
	}

	$counts = count_feedback();
	if ( $counts['moderated'] <= 0 ) {
		return;
	}

	foreach ( SUPPORTED_POST_TYPES as $supported_post_type ) {
		// Attach the bubble to the top-level item when we're not in that section, but Feedback once we are.
		$is_section   = $supported_post_type === $screen->post_type;
		$section_slug = add_query_arg( array( 'post_type' => $supported_post_type ), 'edit.php' );
		$search_menu  = $is_section && isset( $submenu[ $section_slug ] ) ? $submenu[ $section_slug ] : $menu;
		$menu_slug    = $is_section ? 'wc-speaker-feedback' : $section_slug;

		foreach ( $search_menu as $index => $menu_item ) {
			if ( $menu_slug === $menu_item[2] ) {
				$bubble = sprintf(
					" <span class='sft-feedback-unread count-%d awaiting-mod'><span class='sft-feedback-unread-count'>%s</span></span>",
					$counts['moderated'],
					number_format_i18n( $counts['moderated'] )
				);

				if ( $is_section ) {
					$submenu[ $section_slug ][ $index ][0] .= $bubble; // phpcs:ignore
				} else {
					$menu[ $index ][0] .= $bubble; // phpcs:ignore
				}
				break;
			}
		}
	}
}

/**
 * Generate a full URL for a feedback list table page.
 *
 * @param string $post_type
 *
 * @return string
 */
function get_subpage_url( $post_type ) {
	if ( ! in_array( $post_type, SUPPORTED_POST_TYPES, true ) ) {
		return '';
	}

	return add_query_arg(
		array(
			'post_type' => $post_type,
			'page'      => COMMENT_TYPE,
		),
		esc_url( admin_url( 'edit.php' ) )
	);
}

/**
 * Add assets to the form page.
 *
 * @param string $hook_suffix The current admin page.
 */
function enqueue_assets( $hook_suffix ) {
	if ( 'wcb_session_page_wc-speaker-feedback' === $hook_suffix ) {
		wp_enqueue_script(
			'speaker-feedback-admin',
			get_assets_url() . 'js/admin.js',
			array( 'admin-comments', 'jquery' ),
			filemtime( dirname( __DIR__ ) . '/assets/js/admin.js' ),
			true
		);
	}
}

/**
 * Ensure screen options for our custom subpages are saved.
 *
 * @param bool   $keep
 * @param string $option
 * @param int    $value
 *
 * @return int
 */
function set_screen_options( $keep, $option, $value ) {
	$valid_option_keys = array();

	foreach ( SUPPORTED_POST_TYPES as $post_type ) {
		$page_hook = $post_type . '_page_' . COMMENT_TYPE;
		$page_hook = str_replace( '-', '_', $page_hook );

		$valid_option_keys[] = $page_hook . '_per_page';
	}

	if ( in_array( $option, $valid_option_keys, true ) ) {
		return absint( $value );
	}

	return $keep;
}

/**
 * Filter to remove feedback comments from the standard comment counts.
 *
 * @param array $count
 * @param int   $post_id
 *
 * @return object
 */
function adjust_comment_counts( $count, $post_id ) {
	$cache_key = "sft-modified-comments-{$post_id}";

	$cached_count = wp_cache_get( $cache_key, 'counts' );

	if ( false !== $cached_count ) {
		return $cached_count;
	}

	$original_count              = get_comment_count( $post_id );
	$original_count['moderated'] = $original_count['awaiting_moderation'];
	unset( $original_count['awaiting_moderation'] );

	$feedback_count = count_feedback( $post_id );
	$adjusted_count = array();

	foreach ( $original_count as $status => $value ) {
		$adjusted_count[ $status ] = absint( $value ) - absint( $feedback_count[ $status ] );
	}

	$adjusted_count = (object) $adjusted_count;
	wp_cache_set( $cache_key, $adjusted_count, 'counts' );

	return $adjusted_count;
}

/**
 * Modify the count of approved comments that gets stored in the wp_posts table.
 *
 * Feedback comments should not be counted for this.
 *
 * @param int|null $new
 * @param int      $old
 * @param int      $post_id
 *
 * @return mixed
 */
function adjust_post_comment_count( $new, $old, $post_id ) {
	$counts = adjust_comment_counts( array(), $post_id );

	return $counts->approved;
}

/**
 * Render the admin page for displaying a feedback comments list table.
 *
 * @return void
 */
function render_subpage() {
	if ( ! current_user_can( 'moderate_' . COMMENT_TYPE ) ) {
		wp_die(
			'<h1>' . esc_html__( 'You need a higher level of permission.', 'wordcamporg' ) . '</h1>' .
			'<p>' . esc_html__( 'Sorry, you are not allowed to edit feedback comments.', 'wordcamporg' ) . '</p>',
			403
		);
	}

	$post_id        = filter_input( INPUT_GET, 'p', FILTER_VALIDATE_INT );
	$search         = wp_unslash( filter_input( INPUT_GET, 's' ) );
	$paged          = filter_input( INPUT_GET, 'paged', FILTER_VALIDATE_INT );
	$comment_status = wp_unslash( filter_input( INPUT_GET, 'comment_status' ) );
	$list_table     = get_feedback_list_table();
	$messages       = array();

	$action = wp_unslash( filter_input( INPUT_GET, 'action' ) );
	$nonce  = wp_unslash( filter_input( INPUT_GET, 'bulk_edit_nonce' ) );

	if ( ! $action || '-1' === $action ) {
		$action = wp_unslash( filter_input( INPUT_GET, 'action2' ) );
	}

	if ( $action && '-1' !== $action ) {
		$messages = handle_bulk_edit_actions( $action, $nonce );
	}

	wp_enqueue_style(
		'speaker-feedback',
		get_assets_url() . 'build/style.css',
		array(),
		filemtime( get_assets_path() . 'build/style.css' )
	);
	wp_enqueue_script( 'admin-comments' );
	enqueue_comment_hotkeys_js();

	toggle_list_table_filters();
	require_once get_views_path() . 'edit-feedback.php';
	toggle_list_table_filters();
}

/**
 * Load necessary files and instantiate the list table class.
 *
 * @return Feedback_List_Table
 */
function get_feedback_list_table() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-comments-list-table.php';
	require_once get_includes_path() . 'class-feedback-list-table.php';

	return new Feedback_List_Table();
}

/**
 * Process list table form submissions for bulk actions.
 *
 * @param string $action
 * @param string $nonce
 *
 * @return array An multidimensional associated array of message strings for different types of notices.
 */
function handle_bulk_edit_actions( $action, $nonce ) {
	$nonce_is_valid = wp_verify_nonce( $nonce, 'bulk_edit_' . COMMENT_TYPE );
	$valid_actions  = array(
		'approve', 'unapprove', 'mark-inappropriate', 'unmark-inappropriate',
		'spam', 'unspam', 'trash', 'untrash', 'delete',
	);
	$items          = filter_input( INPUT_GET, 'bulk_edit', FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY );
	$edited         = 0;
	$not_edited     = 0;
	$messages       = array(
		'error' => array(),
		'info'  => array(),
	);

	if ( false === $nonce_is_valid || ! in_array( $action, $valid_actions, true ) ) {
		$messages['error'][] = __( 'Invalid form submission.', 'wordcamporg' );
	}

	if ( empty( $items ) ) {
		$messages['error'][] = __( 'No feedback was selected for bulk editing.', 'wordcamporg' );
	}

	if ( empty( $messages['error'] ) ) {
		foreach ( $items as $feedback_id ) {
			$feedback = get_feedback_comment( $feedback_id );

			if ( is_feedback( $feedback ) ) {
				switch ( $action ) {
					case 'approve':
						$result = wp_set_comment_status( $feedback->comment_ID, 'approve' );
						break;
					case 'unapprove':
						$result = wp_set_comment_status( $feedback->comment_ID, 'hold' );
						break;
					case 'mark-inappropriate':
						$result = mark_feedback_inappropriate( $feedback->comment_ID );
						break;
					case 'unmark-inappropriate':
						$result = unmark_feedback_inappropriate( $feedback->comment_ID );
						break;
					case 'spam':
						$result = wp_spam_comment( $feedback->comment_ID );
						break;
					case 'unspam':
						$result = wp_unspam_comment( $feedback->comment_ID );
						break;
					case 'trash':
						$result = delete_feedback( $feedback->comment_ID );
						break;
					case 'untrash':
						$result = wp_untrash_comment( $feedback->comment_ID );
						break;
					case 'delete':
						$result = delete_feedback( $feedback->comment_ID, true );
						break;
				}

				if ( true === $result ) {
					$edited ++;
				} else {
					$not_edited ++;
				}
			} else {
				$not_edited ++;
			}
		}
	}

	if ( $edited ) {
		$messages['info'][] = sprintf(
			_n(
				'%s feedback comment was successfully edited.',
				'%s feedback comments were successfully edited.',
				absint( $edited ),
				'wordcamporg'
			),
			number_format_i18n( $edited )
		);
	}

	if ( $not_edited ) {
		$messages['error'][] = sprintf(
			_n(
				'%s feedback comment could not be edited.',
				'%s feedback comments could not be edited.',
				absint( $not_edited ),
				'wordcamporg'
			),
			number_format_i18n( $not_edited )
		);
	}

	return $messages;
}

/**
 * Add extra actions to the list of links displayed for each comment.
 *
 * @param array      $actions An array of comment actions.
 * @param WP_Comment $comment Comment data object.
 *
 * @return array
 */
function feedback_extra_actions( $actions, $comment ) {
	$comment_id = $comment->comment_ID;
	$feedback = get_feedback_comment( $comment );
	if ( is_null( $feedback ) ) {
		return $actions;
	}

	$unapprove_url = add_query_arg(
		array(
			'bulk_edit[]' => $comment_id,
			'action' => 'unmark-inappropriate',
			'_wpnonce' => wp_create_nonce( "inappropriate-comment_{ $comment_id }" ),
			'bulk_edit_nonce' => wp_create_nonce( 'bulk_edit_' . COMMENT_TYPE ),
		)
	);

	$inappropriate_url = add_query_arg(
		array(
			'bulk_edit[]' => $comment_id,
			'action' => 'mark-inappropriate',
			'_wpnonce' => wp_create_nonce( "inappropriate-comment_{ $comment_id }" ),
			'bulk_edit_nonce' => wp_create_nonce( 'bulk_edit_' . COMMENT_TYPE ),
		)
	);

	if ( 'inappropriate' === $comment->comment_approved ) {
		$actions['unapprove'] = sprintf(
			'<a href="%1$s" data-wp-lists="%2$s" class="vim-u aria-button-if-js" aria-label="%3$s">%4$s</a>',
			esc_url( $unapprove_url ),
			// This is meta information for wpList, to trigger an action when clicked. Most of it can be ignored,
			// the relevant part is the last: query params to pass to admin-ajax.php. See wp_ajax_mark_inappropriate
			// for how these are used.
			// See https://core.trac.wordpress.org/browser/trunk/src/js/_enqueues/lib/lists.js?rev=46800#L217.
			"delete:the-comment-list:comment-{$comment_id}:e7e7d3:action=dim-comment&amp;new=uninappropriate",
			esc_attr__( 'Move this comment to pending', 'wordcamporg' ),
			__( 'Move to Pending', 'wordcamporg' )
		);
	} else {
		$action_inappropriate = array(
			'inappropriate' => sprintf(
				'<a href="%1$s" data-wp-lists="%2$s" class="vim-a vim-destructive aria-button-if-js" aria-label="%3$s">%4$s</a>',
				esc_url( $inappropriate_url ),
				"delete:the-comment-list:comment-{$comment_id}:e7e7d3:action=dim-comment&amp;new=inappropriate",
				esc_attr__( 'Mark as Inappropriate', 'wordcamporg' ),
				__( 'Inappropriate', 'wordcamporg' )
			),
		);

		$key_indexes = array_keys( $actions );
		$after       = array_slice( $actions, array_search( 'spam', $key_indexes, true ), null, true );
		$before      = array_slice( $actions, 0, count( $actions ) - count( $after ), true );

		$actions = $before + $action_inappropriate + $after;
	}

	return $actions;
}

/**
 * Intercept dim_comment ajax handler if the intended status is 'inappropriate'.
 */
function wp_ajax_mark_inappropriate() {
	if ( isset( $_POST['new'] ) && in_array( $_POST['new'], array( 'inappropriate', 'uninappropriate' ) ) ) {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$feedback = get_feedback_comment( $id );

		if ( ! $feedback ) {
			wp_die( time() ); // phpcs:ignore
		}

		check_ajax_referer( "inappropriate-comment_{ $id }" );

		if ( 'inappropriate' === $_POST['new'] ) {
			$r = mark_feedback_inappropriate( $feedback->comment_ID );
		} else {
			$r = unmark_feedback_inappropriate( $feedback->comment_ID );
		}

		if ( $r ) {
			// Decide if we need to send back '1' or a more complicated response including page links and comment counts.
			_wp_ajax_delete_comment_response( $feedback->comment_ID );
		}

		wp_die( 0 );
	}
}

/**
 * Add or remove a bunch of filters to customize our list table.
 *
 * @return string The current state of the filters. 'on' or 'off'.
 */
function toggle_list_table_filters() {
	static $current_state = 'off';

	$screen_id = get_current_screen()->id;

	switch ( $current_state ) {
		case 'off':
			add_filter( 'comments_list_table_query_args', __NAMESPACE__ . '\filter_list_table_query_args' );
			add_filter( "views_{$screen_id}", __NAMESPACE__ . '\filter_list_table_views' );
			add_filter( 'comment_row_actions', __NAMESPACE__ . '\filter_list_table_row_actions', 10, 2 );
			add_filter( 'wp_count_comments', __NAMESPACE__ . '\filter_list_table_view_counts', 99, 2 );
			add_filter( 'get_avatar_comment_types', __NAMESPACE__ . '\filter_list_table_enable_comment_avatar' );
			add_filter( 'comment_author', __NAMESPACE__ . '\filter_list_table_add_comment_avatar', 10, 2 );
			add_filter( 'comment_class', __NAMESPACE__ . '\filter_list_table_modify_comment_classes', 10, 3 );

			$current_state = 'on';
			break;

		case 'on':
			remove_filter( 'comments_list_table_query_args', __NAMESPACE__ . '\filter_list_table_query_args' );
			remove_filter( "views_{$screen_id}", __NAMESPACE__ . '\filter_list_table_views' );
			remove_filter( 'comment_row_actions', __NAMESPACE__ . '\filter_list_table_row_actions', 10 );
			remove_filter( 'wp_count_comments', __NAMESPACE__ . '\filter_list_table_view_counts', 99 );
			remove_filter( 'get_avatar_comment_types', __NAMESPACE__ . '\filter_list_table_enable_comment_avatar' );
			remove_filter( 'comment_author', __NAMESPACE__ . '\filter_list_table_add_comment_avatar', 10 );
			remove_filter( 'comment_class', __NAMESPACE__ . '\filter_list_table_modify_comment_classes', 10 );

			$current_state = 'off';
			break;
	}

	return $current_state;
}

/**
 * Tweak the args for the comment query in the list table.
 *
 * - Ensure the list table query for feedback comments always has the correct comment type specified.
 * - Search the feedback meta values instead of comment content.
 * - Enable ordering by the `rating` meta value.
 *
 * @global string $comment_status
 *
 * @param array $args
 *
 * @return array
 */
function filter_list_table_query_args( $args ) {
	global $comment_status;

	$requested_status = filter_input( INPUT_GET, 'comment_status' );
	if ( 'inappropriate' === $requested_status ) {
		$comment_status = 'inappropriate'; // phpcs:ignore -- The parent class overrides statuses it doesn't recognize.
		$args['status'] = 'inappropriate';
	}

	$args['type'] = COMMENT_TYPE;

	$helpful = filter_input( INPUT_GET, 'helpful' );
	if ( $helpful ) {
		$meta_query = array(
			'key'   => 'helpful',
			'value' => 1,
			'type'  => 'NUMERIC',
		);

		if ( ! isset( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}

		$args['meta_query'][] = $meta_query;
	}

	if ( $args['search'] ) {
		$meta_query = array(
			'relation' => 'OR',
		);

		foreach ( array_keys( get_feedback_questions() ) as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'value'   => $args['search'],
				'compare' => 'LIKE',
			);
		}

		if ( ! isset( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}

		$args['meta_query'][] = $meta_query;

		// This needs to be removed, otherwise no results are returned since comment content fields are empty.
		unset( $args['search'] );
	}

	if ( 'rating' === $args['orderby'] ) {
		$args['orderby']  = 'meta_value_num';
		$args['meta_key'] = 'rating';
	}

	return $args;
}

/**
 * Modify the list of available views for the feedback comments list table.
 *
 * - Remove unnecessary views.
 * - Replace the default view URLs with ones that link back to our feedback list table page.
 * - Add views for feedback marked as inappropriate and as helpful.
 *
 * @global int    $post_id
 * @global string $typenow
 *
 * @param array $views
 *
 * @return mixed
 */
function filter_list_table_views( $views ) {
	global $post_id, $typenow;

	// Feedback from an admin of the event site would probably be rare, so this one is unnecessary.
	unset( $views['mine'] );

	foreach ( $views as $status => $view ) {
		// Note that the HTML here is wrapping href attributes with single quotes.
		preg_match( '#href=[\'"]+([^\'"]+)[\'"]+#', $view, $orig_url );
		$parsed_url = wp_parse_url( $orig_url[1] );
		wp_parse_str( $parsed_url['query'], $query_args );

		$new_url = add_query_arg( $query_args, get_subpage_url( $typenow ) );

		$views[ $status ] = str_replace(
			$orig_url[1],
			$new_url,
			$view
		);
	}

	$link_base = get_subpage_url( $typenow );
	if ( $post_id ) {
		$link_base = add_query_arg( 'p', $post_id, $link_base );
	}

	$current_link_attributes = ' class="current" aria-current="page"';

	$helpful       = filter_input( INPUT_GET, 'helpful' );
	$helpful_count = ( $post_id ) ? count_helpful_feedback( $post_id ) : count_helpful_feedback();

	$views['helpful'] = sprintf(
		'<a href="%1$s"%2$s>%3$s</a>',
		add_query_arg( 'helpful', 1, $link_base ),
		( $helpful ) ? $current_link_attributes : '',
		sprintf(
			// translators: %s is the number of helpful comments.
			_x( 'Helpful <span class="count">(%s)</span>', 'wordcamporg' ),
			sprintf(
				'<span class="helpful-count">%s</span>',
				number_format_i18n( $helpful_count )
			)
		)
	);

	$status          = filter_input( INPUT_GET, 'comment_status' );
	$feedback_counts = ( $post_id ) ? count_feedback( $post_id ) : count_feedback();
	$feedback_counts = (object) $feedback_counts;

	$view_inappropriate = array(
		'inappropriate' => sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			add_query_arg( 'comment_status', 'inappropriate', $link_base ),
			( 'inappropriate' === $status ) ? $current_link_attributes : '',
			sprintf(
				// translators: %s is the number of inappropriate comments.
				_x( 'Inappropriate <span class="count">(%s)</span>', 'wordcamporg' ),
				sprintf(
					'<span class="inappropriate-count">%s</span>',
					number_format_i18n( $feedback_counts->inappropriate )
				)
			)
		),
	);

	$key_indexes = array_keys( $views );
	$after       = array_slice( $views, array_search( 'spam', $key_indexes, true ), null, true );
	$before      = array_slice( $views, 0, count( $views ) - count( $after ), true );

	$views = $before + $view_inappropriate + $after;

	return $views;
}

/**
 * Modify the list of available row actions for each feedback comment.
 *
 * - Check permissions.
 * - Remove irrelevant actions.
 *
 * @param array $actions
 *
 * @return array
 */
function filter_list_table_row_actions( $actions ) {
	if ( ! current_user_can( 'moderate_' . COMMENT_TYPE ) ) {
		return array();
	}

	unset( $actions['reply'], $actions['quickedit'], $actions['edit'] );

	return $actions;
}

/**
 * Filter the comment counts to show only feedback and not other comment types.
 *
 * @param object $count
 * @param int    $post_id
 *
 * @return bool|mixed|object
 */
function filter_list_table_view_counts( $count, $post_id ) {
	$cache_key = "sft-feedback-{$post_id}";

	$cached_count = wp_cache_get( $cache_key, 'counts' );

	if ( false !== $cached_count ) {
		return $cached_count;
	}

	$feedback_count = (object) count_feedback( $post_id );
	wp_cache_set( $cache_key, $feedback_count, 'counts' );

	return $feedback_count;
}

/**
 * Add feedback comment types to the list of types allowed to show an avatar.
 *
 * @param array $types
 *
 * @return array
 */
function filter_list_table_enable_comment_avatar( $types ) {
	$types[] = COMMENT_TYPE;

	return $types;
}

/**
 * Show the feedback submitter's avatar along with their name.
 *
 * This replaces the WP_Comments_List_Table::floated_admin_avatar method, which was firing twice
 * because it was getting hooked during class instantiation.
 *
 * @param string $name
 * @param int    $comment_id
 *
 * @return string
 */
function filter_list_table_add_comment_avatar( $name, $comment_id ) {
	$comment = get_comment( $comment_id );
	$avatar  = get_avatar( $comment, 32, 'mystery' );

	return "$avatar $name";
}

/**
 * Modify the HTML classes that are added to a comment.
 *
 * The `wp_get_comment_status()` function strips out statuses it doesn't recognize, so we have to re-add them.
 *
 * @global string $comment_status
 *
 * @param array  $classes
 * @param string $class
 * @param int    $comment_id
 *
 * @return array
 */
function filter_list_table_modify_comment_classes( $classes, $class, $comment_id ) {
	$feedback = get_feedback_comment( $comment_id );

	if ( $feedback && 'inappropriate' === $feedback->comment_approved ) {
		$classes[] = 'inappropriate';
	}

	return $classes;
}

/**
 * Remove the session author from getting session comment notifications.
 *
 * The speaker is set as the session author, but should not get notifications from WordPress about feedback. By
 * default, this is the only email notifications are sent to, so we can remove all recipients to bypass sending
 * the notification email.
 *
 * @param string[] $emails     An array of email addresses to receive a comment notification.
 * @param int      $comment_id The comment ID.
 *
 * @return array
 */
function remove_email_recipients( $emails, $comment_id ) {
	if ( is_feedback( $comment_id ) ) {
		return array();
	}
	return $emails;
}
