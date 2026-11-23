<?php
/**
 * Show event speakers' talks in their WordPress.org profile activity (#2075).
 *
 * Profiles can't read this network, so changes are pushed to it from cron.
 * `ACTIVITY_META` records what Profiles was last told, and each run sends only
 * the difference.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Profile_Activity;

use WP_Post;
use WordPressdotorg\Profiles;

use function WordCamp\Groups\Frontend\Capabilities\self_serve_roles_enabled;

defined( 'WPINC' ) || die();

const SPEAKERS_META   = '_event_speakers';
const ACTIVITY_META   = '_wporg_profile_activity';
const SYNC_HOOK       = 'wporg_groups_sync_profile_activity';
const RETRACT_HOOK    = 'wporg_groups_retract_profile_activity';
const MAX_ATTEMPTS    = 5;
const PROFILE_HELPERS = WP_CONTENT_DIR . '/mu-plugins-private/wporg-mu-plugins/pub/profile-helpers.php';

/**
 * How long a sync waits before running.
 *
 * The Group Settings form saves an event's speakers in a second request right
 * after the event itself, and the sync has to see both.
 */
const SYNC_DELAY = MINUTE_IN_SECONDS;

// Only on WordPress.org servers. Local stacks have no Profiles to talk to.
if ( is_readable( PROFILE_HELPERS ) ) {
	require_once PROFILE_HELPERS;
}

add_action( 'added_post_meta', __NAMESPACE__ . '\on_meta_change', 10, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\on_meta_change', 10, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\on_meta_change', 10, 3 );
add_action( 'transition_post_status', __NAMESPACE__ . '\on_status_change', 10, 3 );
add_action( 'post_updated', __NAMESPACE__ . '\on_post_updated', 10, 3 );
add_action( 'before_delete_post', __NAMESPACE__ . '\on_delete', 10, 2 );
add_action( SYNC_HOOK, __NAMESPACE__ . '\sync', 10, 2 );
add_action( RETRACT_HOOK, __NAMESPACE__ . '\retract', 10, 3 );

/**
 * Whether the Profiles API is available.
 */
function is_enabled(): bool {
	return function_exists( 'WordPressdotorg\Profiles\api' );
}

/**
 * Queue a sync when an event's speakers change.
 *
 * @param int|int[] $meta_id   Meta ID(s), unused.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 */
function on_meta_change( $meta_id, $object_id, $meta_key ): void {
	if ( SPEAKERS_META === $meta_key ) {
		schedule_sync( (int) $object_id );
	}
}

/**
 * Queue a sync when an event changes status, including trashing.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       The post.
 */
function on_status_change( $new_status, $old_status, $post ): void {
	if ( $new_status !== $old_status ) {
		schedule_sync( (int) $post->ID );
	}
}

/**
 * Queue a sync when a field the activity depends on changes.
 *
 * @param int     $post_id     Post ID.
 * @param WP_Post $post_after  The post after the update.
 * @param WP_Post $post_before The post before the update.
 */
function on_post_updated( $post_id, $post_after, $post_before ): void {
	foreach ( array( 'post_password', 'post_title', 'post_name' ) as $field ) {
		if ( $post_after->$field !== $post_before->$field ) {
			schedule_sync( (int) $post_id );
			return;
		}
	}
}

/**
 * Retract an event's profile activity before it's deleted, since its meta
 * goes with it.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    The post.
 */
function on_delete( $post_id, $post ): void {
	if ( ! is_enabled() || ! $post || 'gatherpress_event' !== $post->post_type ) {
		return;
	}

	$user_ids = array_keys( get_synced( (int) $post_id ) );

	if ( $user_ids ) {
		wp_schedule_single_event( time(), RETRACT_HOOK, array( (int) $post_id, $user_ids ) );
	}
}

/**
 * Queue a sync for an event, unless one is already queued.
 *
 * Events with no speakers and nothing sent are skipped, so most saves queue
 * nothing.
 *
 * @param int $event_id Event post ID.
 */
function schedule_sync( int $event_id ): void {
	$post = get_post( $event_id );

	if ( ! is_enabled() || ! $post || 'gatherpress_event' !== $post->post_type ) {
		return;
	}

	if ( ! get_post_meta( $event_id, SPEAKERS_META, true ) && ! get_synced( $event_id ) ) {
		return;
	}

	if ( ! wp_next_scheduled( SYNC_HOOK, array( $event_id ) ) ) {
		wp_schedule_single_event( time() + SYNC_DELAY, SYNC_HOOK, array( $event_id ) );
	}
}

/**
 * Bring Profiles in line with the event's current speakers.
 *
 * @param int $event_id Event post ID.
 * @param int $attempt  Which try this is.
 */
function sync( int $event_id, int $attempt = 1 ): void {
	if ( ! is_enabled() ) {
		return;
	}

	$post    = get_post( $event_id );
	$synced  = get_synced( $event_id );
	$targets = get_target_user_ids( $post, $synced );
	$sig     = $targets ? md5( $post->post_title . get_permalink( $post ) ) : '';
	$retry   = false;

	foreach ( $targets as $user_id ) {
		if ( isset( $synced[ $user_id ] ) && $sig === $synced[ $user_id ]['sig'] ) {
			continue;
		}

		$result = send( 'add', $event_id, $user_id, $post );

		if ( 'sent' === $result ) {
			$synced[ $user_id ] = array(
				'role' => 'speaker',
				'sig'  => $sig,
			);
			save_synced( $event_id, $synced );
		} elseif ( 'failed' === $result ) {
			$retry = true;

			// Profiles may have saved it anyway, so record it as pending: a
			// later sync then sends the add again, or a remove if it's no
			// longer wanted.
			if ( ! isset( $synced[ $user_id ] ) ) {
				$synced[ $user_id ] = array(
					'role' => 'speaker',
					'sig'  => '',
				);
				save_synced( $event_id, $synced );
			}
		}
	}

	foreach ( array_diff( array_keys( $synced ), $targets ) as $user_id ) {
		$result = send( 'remove', $event_id, $user_id );

		if ( 'sent' === $result ) {
			unset( $synced[ $user_id ] );
			save_synced( $event_id, $synced );
		} elseif ( 'failed' === $result ) {
			$retry = true;
		}
	}

	if ( $retry ) {
		schedule_retry( SYNC_HOOK, array( $event_id ), $attempt );
	}
}

/**
 * Remove the profile activity of an event that no longer exists.
 *
 * @param int   $event_id Event post ID.
 * @param int[] $user_ids Users Profiles has activity for.
 * @param int   $attempt  Which try this is.
 */
function retract( int $event_id, array $user_ids, int $attempt = 1 ): void {
	if ( ! is_enabled() ) {
		return;
	}

	$failed = array();

	foreach ( $user_ids as $user_id ) {
		if ( 'failed' === send( 'remove', $event_id, (int) $user_id ) ) {
			$failed[] = (int) $user_id;
		}
	}

	if ( $failed ) {
		schedule_retry( RETRACT_HOOK, array( $event_id, $failed ), $attempt );
	}
}

/**
 * The speakers who should have profile activity for an event right now.
 *
 * Only group members are added. Someone already sent stays after leaving the
 * group, since they still spoke.
 *
 * Testing groups send nothing: anyone can make themselves an organizer there.
 *
 * @param WP_Post|null $post   The event.
 * @param array        $synced See `get_synced()`.
 * @return int[]
 */
function get_target_user_ids( ?WP_Post $post, array $synced ): array {
	if ( ! $post || 'gatherpress_event' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return array();
	}

	if ( self_serve_roles_enabled() ) {
		return array();
	}

	$ids = get_post_meta( $post->ID, SPEAKERS_META, true );
	$ids = array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );

	return array_values(
		array_filter(
			$ids,
			static fn( int $id ): bool => $id > 0
				&& false !== get_userdata( $id )
				&& ( isset( $synced[ $id ] ) || is_user_member_of_blog( $id ) )
		)
	);
}

/**
 * What Profiles has been told about an event, keyed by user ID.
 *
 * An empty `sig` marks an add that may not have reached Profiles.
 *
 * @param int $event_id Event post ID.
 * @return array<int, array{role:string, sig:string}>
 */
function get_synced( int $event_id ): array {
	$synced = get_post_meta( $event_id, ACTIVITY_META, true );

	return is_array( $synced ) ? $synced : array();
}

/**
 * Store what Profiles has been told, removing the meta when it's nothing.
 *
 * @param int   $event_id Event post ID.
 * @param array $synced   See `get_synced()`.
 */
function save_synced( int $event_id, array $synced ): void {
	if ( $synced ) {
		update_post_meta( $event_id, ACTIVITY_META, $synced );
	} else {
		delete_post_meta( $event_id, ACTIVITY_META );
	}
}

/**
 * Send one profile activity change to Profiles.
 *
 * @param string       $command  `add` or `remove`.
 * @param int          $event_id Event post ID.
 * @param int          $user_id  The speaker.
 * @param WP_Post|null $post     The event, needed for `add`.
 * @return string `sent`, `rejected` (a 400, not worth retrying) or `failed`.
 */
function send( string $command, int $event_id, int $user_id, ?WP_Post $post = null ): string {
	$payload = array(
		'action'   => 'wporg_handle_activity',
		'source'   => 'groups',
		'command'  => $command,
		'role'     => 'speaker',
		'user'     => $user_id,
		'event_id' => $event_id,
		'group_id' => get_current_blog_id(),
	);

	if ( 'add' === $command ) {
		$payload['event_title'] = $post->post_title;
		$payload['url']         = get_permalink( $post );
		$payload['group_name']  = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$payload['group_url']   = home_url( '/' );
	}

	$response = Profiles\api( $payload );
	$code     = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 === $code && 1 === (int) wp_remote_retrieve_body( $response ) ) {
		return 'sent';
	}

	if ( 400 === $code ) {
		trigger_error(
			sprintf(
				'Profiles rejected the speaker profile activity `%s` for user %d on event %d of site %d.',
				esc_html( $command ),
				absint( $user_id ),
				absint( $event_id ),
				absint( get_current_blog_id() )
			),
			E_USER_WARNING
		);

		return 'rejected';
	}

	return 'failed';
}

/**
 * Try a job again in an hour, up to `MAX_ATTEMPTS` tries.
 *
 * @param string $hook    Cron hook.
 * @param array  $args    The job's arguments, without the attempt.
 * @param int    $attempt The try that just failed.
 */
function schedule_retry( string $hook, array $args, int $attempt ): void {
	if ( $attempt >= MAX_ATTEMPTS ) {
		trigger_error(
			sprintf(
				'Gave up on the profile activity job `%s` for event %d of site %d after %d tries.',
				esc_html( $hook ),
				absint( $args[0] ),
				absint( get_current_blog_id() ),
				absint( $attempt )
			),
			E_USER_WARNING
		);

		return;
	}

	$args[] = $attempt + 1;

	wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook, $args );
}
