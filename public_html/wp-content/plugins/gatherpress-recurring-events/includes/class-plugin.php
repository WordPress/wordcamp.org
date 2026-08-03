<?php
/**
 * Extension bootstrap and hooks.
 *
 * @package WordPressdotorg\GatherPress_Recurring_Events
 */

namespace WordPressdotorg\GatherPress_Recurring_Events;

use GatherPress\Core\Calendar\Calendar;

defined( 'WPINC' ) || die();

final class Plugin {

	private static ?self $instance = null;

	/** Gets the singleton instance. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Prevents direct construction. */
	private function __construct() {}

	/** Registers extension hooks. */
	public function register(): void {
		add_action( 'init', array( $this, 'init' ), 20 );
		add_filter( 'query_vars', array( Context::class, 'query_vars' ) );
		add_action( 'template_redirect', array( Context::class, 'resolve' ), 1 );
		add_action( 'template_redirect', array( $this, 'series_ical' ), 5 );
		add_filter( 'get_post_metadata', array( Context::class, 'metadata' ), 10, 4 );
		add_filter( 'post_type_link', array( Context::class, 'post_link' ), 10, 2 );
		add_filter( 'redirect_canonical', array( Context::class, 'canonical_redirect' ) );
		add_filter( 'the_content', array( Context::class, 'prepend_selector' ), 3 );

		add_action( 'pre_get_comments', array( Comments::class, 'prepare_query' ), 5 );
		add_filter( 'comments_clauses', array( Comments::class, 'clauses' ), 20, 2 );
		add_action( 'comment_form', array( Comments::class, 'hidden_field' ) );
		add_filter( 'render_block_gatherpress/rsvp-form', array( Comments::class, 'rsvp_form' ), 50 );
		add_filter( 'preprocess_comment', array( Comments::class, 'capture_submission' ), 5 );
		add_action( 'wp_insert_comment', array( Comments::class, 'map_inserted' ), 20, 2 );
		add_action( 'deleted_comment', array( Database::class, 'delete_comment' ) );

		add_filter( 'render_block_gatherpress/rsvp', array( Context::class, 'render_rsvp_block' ), 50 );
		add_filter( 'gatherpress_calendar_url', array( $this, 'calendar_url' ), 10, 2 );
		add_filter( 'posts_clauses', array( Query::class, 'clauses' ), 30, 2 );
		add_filter( 'the_posts', array( Query::class, 'posts' ), 20, 2 );
		add_action( 'the_post', array( Query::class, 'activate' ) );

		add_action( 'rest_api_init', array( Rest_API::class, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( Admin::class, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
		add_action( 'save_post_gatherpress_event', array( $this, 'save_event' ), 100, 2 );
		add_filter( 'update_post_metadata', array( $this, 'lock_published_schedule' ), 10, 4 );
		add_action( 'before_delete_post', array( $this, 'delete_event' ), 10, 2 );
		add_action( Occurrences::CRON_HOOK, array( Occurrences::class, 'project_all' ) );
	}

	/** Registers runtime metadata, schema, routing, and cron. */
	public function init(): void {
		Database::maybe_install();
		Admin::register_meta();
		Context::register();
		Occurrences::schedule_cron();

		foreach ( array( 'ical', 'outlook', 'google-calendar', 'yahoo-calendar' ) as $endpoint ) {
			add_rewrite_rule(
				'^event/([^/]+)/([0-9]{8}(?:T[0-9]{6})?)/(' . preg_quote( $endpoint, '/' ) . ')/?$',
				'index.php?gatherpress_event=$matches[1]&gpre_occurrence=$matches[2]&gatherpress_calendar=$matches[3]',
				'top'
			);
		}

		if ( VERSION !== get_option( 'gpre_rewrite_version' ) ) {
			flush_rewrite_rules( false );
			update_option( 'gpre_rewrite_version', VERSION, false );
		}
	}

	/**
	 * Projects occurrence rows after saving an event.
	 *
	 * @param int    $post_id Event post ID.
	 * @param object $post    Event post.
	 */
	public function save_event( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' === $post->post_status && Rule::is_recurring( $post_id ) ) {
			Occurrences::project( $post_id );
		} elseif ( ! Rule::is_recurring( $post_id ) && 'publish' !== $post->post_status ) {
			Database::delete_series( $post_id );
		}
	}

	/**
	 * Removes extension data when an event is permanently deleted.
	 *
	 * @param int    $post_id Event post ID.
	 * @param object $post    Event post.
	 */
	public function delete_event( int $post_id, $post ): void {
		if ( 'gatherpress_event' === $post->post_type ) {
			Database::delete_series( $post_id );
		}
	}

	/** Enqueues occurrence selector styling. */
	public function styles(): void {
		wp_enqueue_style( 'gpre', plugin_dir_url( FILE ) . 'assets/style.css', array(), VERSION );
	}

	/**
	 * Points calendar actions at the selected occurrence endpoint.
	 *
	 * @param string $url  Existing calendar endpoint URL.
	 * @param object $post Event post.
	 * @return string Filtered URL.
	 */
	public function calendar_url( string $url, $post ): string {
		if ( ! get_query_var( 'gpre_occurrence' ) || ! Context::get() || (int) Context::get()->series_post_id !== (int) $post->ID ) {
			return $url;
		}

		$base = trailingslashit( get_permalink( $post ) );
		if ( str_starts_with( $url, $base ) ) {
			return $base . Context::recurrence_id() . '/' . substr( $url, strlen( $base ) );
		}

		return $url;
	}

	/**
	 * Blocks schedule mutations after publishing a recurring series.
	 *
	 * Ending and cancelling remain available through their dedicated controls.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Event post ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Proposed value.
	 * @return mixed Existing value or false to block an update.
	 */
	public function lock_published_schedule( $check, int $object_id, string $meta_key, $meta_value ) {
		$locked = array(
			'gatherpress_datetime',
			Rule::META_PREFIX . 'frequency',
			Rule::META_PREFIX . 'interval',
			Rule::META_PREFIX . 'weekdays',
			Rule::META_PREFIX . 'monthly_mode',
			Rule::META_PREFIX . 'monthly_day',
			Rule::META_PREFIX . 'monthly_order',
			Rule::META_PREFIX . 'monthly_weekday',
			Rule::META_PREFIX . 'count',
		);

		if ( 'publish' !== get_post_status( $object_id ) || ! Rule::is_recurring( $object_id ) || ! in_array( $meta_key, $locked, true ) || ! metadata_exists( 'post', $object_id, $meta_key ) ) {
			return $check;
		}

		$current = get_post_meta( $object_id, $meta_key, true );
		return maybe_serialize( $current ) === maybe_serialize( $meta_value ) ? $check : false;
	}

	/** Serves the series-level iCalendar download with its RRULE. */
	public function series_ical(): void {
		$endpoint = (string) get_query_var( 'gatherpress_calendar' );
		$post_id  = get_queried_object_id();

		if ( get_query_var( 'gpre_occurrence' ) || ! in_array( $endpoint, array( 'ical', 'outlook' ), true ) || ! Rule::is_recurring( $post_id ) ) {
			return;
		}

		Context::set( null );
		$event = ( new Calendar( $post_id ) )->get_ical_event_string();
		$rrule = (string) get_post_meta( $post_id, Rule::META_PREFIX . 'rrule', true );
		$event = str_replace( 'END:VEVENT', 'RRULE:' . $rrule . "\r\nEND:VEVENT", $event );
		$body  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WordPress.org//GatherPress Recurring Events//EN\r\n" . $event . "\r\nEND:VCALENDAR\r\n";

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( get_post_field( 'post_name', $post_id ) . '.ics' ) . '"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 5545 payload from GatherPress.
		exit;
	}
}
