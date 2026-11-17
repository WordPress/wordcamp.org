<?php
/**
 * When, and how, each member joined the group.
 *
 * Core only stores a member's role on a site, and `user_registered` is when
 * their WordPress.org account was made, so nothing said when someone joined
 * a group. Organizers asked, e.g. to see who joined since a Meetup import.
 *
 * Recorded on core's `add_user_to_blog` action, which every way in goes
 * through: the Join button, RSVPing, the Meetup import, and wp-admin. Members
 * from before this was recorded have no date.
 *
 * @package WordCamp\Groups\Frontend
 */

namespace WordCamp\Groups\Frontend\Member_Joined;

use WP_User_Query;

use function WordCamp\Groups\Frontend\Capabilities\get_current_group_slug;

defined( 'WPINC' ) || die();

/**
 * User meta, prefixed per site like core's `capabilities`: when the user
 * joined, as a Unix timestamp.
 */
const JOINED_META = 'wporg_groups_joined';

/**
 * User meta, prefixed per site: how the user joined, one of `SOURCES`.
 */
const SOURCE_META = 'wporg_groups_joined_via';

const SOURCE_JOIN   = 'join';
const SOURCE_RSVP   = 'rsvp';
const SOURCE_IMPORT = 'meetup_import';
const SOURCE_ADDED  = 'added';

/**
 * How someone can join. `added` covers anything else: wp-admin, a new
 * group site's organizer, and so on.
 */
const SOURCES = array( SOURCE_JOIN, SOURCE_RSVP, SOURCE_IMPORT, SOURCE_ADDED );

/**
 * The `orderby` value for the wp-admin Users list column.
 */
const ORDERBY = 'joined';

/**
 * Register hooks.
 *
 * Runs on every site of the groups network, including the root site the
 * Meetup import runs from, so it's registered before the GatherPress guard.
 */
function bootstrap(): void {
	add_action( 'add_user_to_blog', __NAMESPACE__ . '\record_join', 10, 3 );
	add_action( 'remove_user_from_blog', __NAMESPACE__ . '\forget_join', 10, 2 );

	add_filter( 'manage_users_columns', __NAMESPACE__ . '\add_users_column' );
	add_filter( 'manage_users_custom_column', __NAMESPACE__ . '\render_users_column', 10, 3 );
	add_filter( 'manage_users_sortable_columns', __NAMESPACE__ . '\add_sortable_column' );
	add_action( 'pre_get_users', __NAMESPACE__ . '\sort_users_by_joined' );
}

/**
 * The per-site meta key, the way core prefixes `capabilities`.
 *
 * @param string $key     Unprefixed key.
 * @param int    $site_id Site; the current one by default.
 */
function meta_key( string $key, int $site_id = 0 ): string {
	global $wpdb;

	return $wpdb->get_blog_prefix( $site_id ?: get_current_blog_id() ) . $key;
}

/**
 * How the add currently under way came about.
 *
 * @param string|null $source Set it, or `null` to read it.
 * @param bool        $reset  Clear it.
 */
function current_source( ?string $source = null, bool $reset = false ): string {
	static $current = '';

	if ( $reset ) {
		$current = '';
	} elseif ( null !== $source ) {
		$current = $source;
	}

	return '' !== $current ? $current : SOURCE_ADDED;
}

/**
 * Run a callback that may add a user to a site, recording how they joined.
 *
 * @param string   $source   One of `SOURCES`.
 * @param callable $callback What to run.
 * @return mixed What the callback returns.
 */
function with_source( string $source, callable $callback ) {
	current_source( in_array( $source, SOURCES, true ) ? $source : SOURCE_ADDED );

	try {
		return $callback();
	} finally {
		current_source( null, true );
	}
}

/**
 * Record when, and how, a user joined a site.
 *
 * A date already there is kept: leaving clears it, so one that's still there
 * means they never left.
 *
 * @param int    $user_id User added.
 * @param string $role    Role they were given.
 * @param int    $site_id Site they were added to.
 */
function record_join( $user_id, $role, $site_id ): void {
	$user_id = (int) $user_id;
	$site_id = (int) $site_id;

	if ( get_user_meta( $user_id, meta_key( JOINED_META, $site_id ), true ) ) {
		return;
	}

	update_user_meta( $user_id, meta_key( JOINED_META, $site_id ), time() );
	update_user_meta( $user_id, meta_key( SOURCE_META, $site_id ), current_source() );
}

/**
 * Forget the join date when a user leaves, so rejoining starts a new one.
 *
 * @param int $user_id User removed.
 * @param int $site_id Site they were removed from.
 */
function forget_join( $user_id, $site_id ): void {
	delete_user_meta( (int) $user_id, meta_key( JOINED_META, (int) $site_id ) );
	delete_user_meta( (int) $user_id, meta_key( SOURCE_META, (int) $site_id ) );
}

/**
 * When, and how, a user joined the current site.
 *
 * @param int $user_id User.
 * @return array{time: int, source: string}|null Null when it wasn't recorded.
 */
function get_join( int $user_id ): ?array {
	$time = (int) get_user_meta( $user_id, meta_key( JOINED_META ), true );

	if ( ! $time ) {
		return null;
	}

	$source = (string) get_user_meta( $user_id, meta_key( SOURCE_META ), true );

	return array(
		'time'   => $time,
		'source' => in_array( $source, SOURCES, true ) ? $source : SOURCE_ADDED,
	);
}

/**
 * Whether a Meetup import put the user in the current group, or found them in it.
 *
 * @param int $user_id User.
 */
function is_from_meetup_import( int $user_id ): bool {
	if ( ! defined( '\WordCamp\Groups\Meetup_Import\IMPORTED_USERS_OPTION' ) ) {
		return false;
	}

	$imported = array_map( 'intval', (array) get_option( \WordCamp\Groups\Meetup_Import\IMPORTED_USERS_OPTION, array() ) );

	return in_array( $user_id, $imported, true );
}

/**
 * One line for organizers on when and how a member joined.
 *
 * Members from before dates were recorded get nothing, unless the Meetup
 * import brought them in.
 *
 * @param int $user_id User.
 */
function describe_join( int $user_id ): string {
	$join = get_join( $user_id );

	if ( ! $join ) {
		return is_from_meetup_import( $user_id ) ? __( 'From the Meetup import', 'wordcamporg' ) : '';
	}

	$date = wp_date( get_option( 'date_format' ), $join['time'] );

	switch ( $join['source'] ) {
		case SOURCE_JOIN:
			/* translators: %s: Date. */
			return sprintf( __( 'Joined %s', 'wordcamporg' ), $date );

		case SOURCE_RSVP:
			/* translators: %s: Date. */
			return sprintf( __( 'Joined %s by RSVPing', 'wordcamporg' ), $date );

		case SOURCE_IMPORT:
			/* translators: %s: Date. */
			return sprintf( __( 'Imported from Meetup %s', 'wordcamporg' ), $date );

		default:
			/* translators: %s: Date. */
			return sprintf( __( 'Added %s', 'wordcamporg' ), $date );
	}
}

/**
 * Add the Joined column to the wp-admin Users list.
 *
 * Only on a group's own site; the `/group/` root isn't a group anyone joins.
 *
 * @param array $columns Column slug => label.
 * @return array
 */
function add_users_column( $columns ) {
	if ( is_network_admin() || '' === get_current_group_slug() ) {
		return $columns;
	}

	$columns[ ORDERBY ] = __( 'Joined', 'wordcamporg' );

	return $columns;
}

/**
 * Render the Joined column.
 *
 * @param string $output      Column output so far.
 * @param string $column_name Column slug.
 * @param int    $user_id     User.
 * @return string
 */
function render_users_column( $output, $column_name, $user_id ) {
	if ( ORDERBY !== $column_name ) {
		return $output;
	}

	$description = describe_join( (int) $user_id );

	return '' !== $description ? esc_html( $description ) : '&mdash;';
}

/**
 * Make the Joined column sortable.
 *
 * @param array $columns Sortable column slug => orderby value.
 * @return array
 */
function add_sortable_column( $columns ) {
	$columns[ ORDERBY ] = ORDERBY;

	return $columns;
}

/**
 * Sort the Users list by join date.
 *
 * @param WP_User_Query $query The users query.
 */
function sort_users_by_joined( $query ): void {
	if ( ORDERBY !== $query->get( 'orderby' ) ) {
		return;
	}

	$key = meta_key( JOINED_META, (int) ( $query->get( 'blog_id' ) ?: get_current_blog_id() ) );

	// The NOT EXISTS clause keeps members without a date in the list.
	$query->set(
		'meta_query',
		array(
			'relation'    => 'OR',
			'joined'      => array(
				'key'     => $key,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			'joined_none' => array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
		)
	);
	$query->set( 'orderby', 'joined' );
}
