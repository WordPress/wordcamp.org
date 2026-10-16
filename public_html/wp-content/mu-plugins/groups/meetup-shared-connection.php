<?php
/**
 * Share the WordCamp network's Meetup connection with the Groups network.
 *
 * The Meetup client keeps its OAuth token in a network option, so each network
 * would need its own connection -- and the Groups network can't get one by
 * itself: the connect flow's redirect URI points at WordCamp Central, so the
 * Connect button on this network's settings screen ends up storing the token
 * on Central's network instead. Central already holds a working connection
 * for the Meetup Pro account, which is the account the Meetup import
 * (`meetup-import.php`) needs too.
 *
 * So on this network the token option is a window onto Central's: reads come
 * from it, and the client's refreshes and resets go to it. Keeping one token
 * also avoids two networks refreshing copies of the same token and
 * invalidating each other's.
 *
 * Only loaded on the Groups network, via
 * `load-other-mu-plugins.php::wcorg_include_network_only_plugins()`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Meetup_Shared_Connection;

defined( 'WPINC' ) || die();

/**
 * The option the Meetup client keeps its token in.
 *
 * Mirrors `Meetup_OAuth2_Client::SITE_OPTION_KEY_OAUTH`, repeated for the
 * same reason as `WordCamp\Meetup_OAuth\TOKEN_SITE_OPTION`.
 */
const TOKEN_OPTION = 'meetup_access_token';

add_filter( 'pre_site_option_' . TOKEN_OPTION, __NAMESPACE__ . '\read_shared_token', 10, 3 );
add_filter( 'pre_update_site_option_' . TOKEN_OPTION, __NAMESPACE__ . '\write_shared_token', 10, 4 );
add_action( 'pre_delete_site_option_' . TOKEN_OPTION, __NAMESPACE__ . '\delete_shared_token', 10, 2 );

/**
 * Read the token from the WordCamp network instead of this one.
 *
 * The network check matters: reading Central's option runs this same filter,
 * for Central's network ID, and that read has to go through untouched.
 *
 * @param mixed  $pre        Short-circuit value; false to read normally.
 * @param string $option     Option name.
 * @param int    $network_id Network being read.
 * @return mixed
 */
function read_shared_token( $pre, $option, $network_id ) {
	if ( GROUPS_NETWORK_ID !== (int) $network_id ) {
		return $pre;
	}

	return get_network_option( WORDCAMP_NETWORK_ID, TOKEN_OPTION, array() );
}

/**
 * Save a refreshed token to the WordCamp network instead of this one.
 *
 * Returning the old value tells `update_network_option()` nothing changed, so
 * this network never stores a copy of its own.
 *
 * @param mixed  $value      New value.
 * @param mixed  $old_value  Current value, as read through `read_shared_token()`.
 * @param string $option     Option name.
 * @param int    $network_id Network being written.
 * @return mixed
 */
function write_shared_token( $value, $old_value, $option, $network_id ) {
	if ( GROUPS_NETWORK_ID !== (int) $network_id ) {
		return $value;
	}

	update_network_option( WORDCAMP_NETWORK_ID, TOKEN_OPTION, $value );

	return $old_value;
}

/**
 * Reset the WordCamp network's token when the client resets this one's.
 *
 * The client deletes its token when Meetup rejects it, and that token is
 * Central's, so Central's has to go too -- otherwise the next read would
 * hand back the same rejected token.
 *
 * @param string $option     Option name.
 * @param int    $network_id Network being written.
 */
function delete_shared_token( $option, $network_id ) {
	if ( GROUPS_NETWORK_ID !== (int) $network_id ) {
		return;
	}

	delete_network_option( WORDCAMP_NETWORK_ID, TOKEN_OPTION );
}
