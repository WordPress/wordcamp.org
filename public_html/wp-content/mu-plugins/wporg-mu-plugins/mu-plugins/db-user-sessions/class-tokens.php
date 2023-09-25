<?php
namespace WordPressdotorg\MU_Plugins\DB_User_Sessions;

class Tokens extends \WP_Session_Tokens {
	const MAX_USER_SESSIONS = 100;
	const TABLE             = 'wporg_user_sessions';

	protected function get_sessions() {
		$user_sessions = $this->get_all_user_sessions();

		return array_filter( $user_sessions, [ $this, 'is_still_valid' ] );
	}

	protected function get_session( $verifier ) {
		$cache_key = $this->user_id . '__' . $verifier;
		$session   = wp_cache_get( $cache_key, 'user_sessions' );
		if ( is_array( $session ) && $this->is_still_valid( $session ) ) {
			return $session;
		}

		$all_sessions = $this->get_all_user_sessions();
		if (
			! isset( $all_sessions[ $verifier ] ) ||
			! $this->is_still_valid( $all_sessions[ $verifier ] )
		) {
			return null;
		}

		wp_cache_set( $cache_key, $all_sessions[ $verifier ], 'user_sessions' );

		return $all_sessions[ $verifier ];
	}

	protected function limit_user_sessions( $verifier = null ) {
		$all_user_sessions = $this->get_all_user_sessions();
		$sessions          = [];

		foreach( $all_user_sessions as $session_verifier => $session ) {
			if ( $verifier === $session_verifier ) {
				continue;
			}

			$sessions[] = [
				'login'    => $session['login'],
				'verifier' => $session_verifier,
				'host'     => $session['host'] ?? '',
			];
		}

		usort( $sessions, static function( $session_a, $session_b ) {
			return -( $session_a['login'] <=> $session_b['login'] );
		} );

		$session = $sessions[ self::MAX_USER_SESSIONS - 1 ] ?? null;
		if ( empty( $session ) ) {
			return;
		}

		$sessions_to_delete = array_map(
			static function( $session ) {
				return $session['verifier'];
			},
			array_slice( $sessions, self::MAX_USER_SESSIONS - 50 )
		);

		$this->delete_sessions_by_verifiers( $sessions_to_delete );
	}

	protected function update_session( $verifier, $session = null ) {
		global $wpdb;

		if ( ! $session ) {
			return $this->delete_sessions_by_verifiers( [ $verifier ] );
		}

		// Delete expired sessions
		$sessions_to_delete = array();
		$all_user_sessions  = $this->get_all_user_sessions();

		foreach ( $all_user_sessions as $session_verifier => $session_data ) {
			if ( $this->is_still_valid( $session_data ) ) {
				continue;
			}
			if ( $verifier == $session_verifier ) {
				continue;
			}

			$sessions_to_delete[] = $session_verifier;
			unset( $all_user_sessions[ $session_verifier ] );
		}

		if ( ! empty( $sessions_to_delete ) ) {
			$this->delete_sessions_by_verifiers( $sessions_to_delete );
		}

		if ( count( $all_user_sessions ) >= self::MAX_USER_SESSIONS ) {
			$this->limit_user_sessions( $verifier );
		}

		$new_session = $this->convert_session_to_db_format( $verifier, $session );

		// Not using the heavier REPLACE because we take advantage of the DB Slaves
		if ( isset( $all_user_sessions[ $verifier ] ) ) {
			$wpdb->update(
				self::TABLE,
				$new_session,
				[
					'user_id' => $this->user_id,
					'verifier' => $verifier
				],
				[ '%d', '%s', '%d', '%d', '%s', '%s' ]
			);
		} else {
			$wpdb->insert( self::TABLE, $new_session, [ '%d', '%s', '%d', '%d', '%s', '%s' ] );
		}

		$this->clear_user_session_cache( $verifier );
	}

	protected function destroy_other_sessions( $verifier ) {
		global $wpdb;

		$sessions_to_delete = [];
		$all_user_sessions  = $this->get_all_user_sessions();

		foreach ( $all_user_sessions as $session_verifier => $session_data ) {
			if ( $verifier == $session_verifier ) {
				continue;
			}

			$sessions_to_delete[] = $session_verifier;
		}

		if ( empty( $sessions_to_delete ) ) {
			return;
		}

		$this->delete_sessions_by_verifiers( $sessions_to_delete );
	}

	protected function destroy_all_sessions() {
		$sessions_to_delete = array_keys( $this->get_all_user_sessions() );
		if ( empty( $sessions_to_delete ) ) {
			return;
		}

		$this->delete_sessions_by_verifiers( $sessions_to_delete );
	}

	public static function drop_sessions() {
		return; // Not supported.
	}

	// Internal functions

	protected function get_all_user_sessions() {
		global $wpdb;

		$cache_key = 'sessions__' . $this->user_id;
		$sessions  = wp_cache_get( $cache_key, 'user_sessions' );
		if ( false !== $sessions ) {
			return $sessions;
		}

		$num_sessions = $wpdb->query( $wpdb->prepare(
			'SELECT `verifier`, `expiration`, `ip`, `login`, `session_meta` FROM %i WHERE `user_id` = %d',
			self::TABLE,
			(int) $this->user_id
		) );

		$user_sessions = $wpdb->last_result;
		if ( false === $num_sessions || ! is_array( $user_sessions ) ) {
			return [];
		}

		$sessions = [];
		foreach ( $user_sessions as $user_session ) {
			$sessions[ $user_session->verifier ] = $this->convert_session_from_db_format( $user_session );
		}

		wp_cache_add( $cache_key, $sessions, 'user_sessions' );

		return $sessions;
	}

	protected function convert_session_to_db_format( $verifier, $session ) {
		$ip = null;
		if ( isset( $session['ip'] ) ) {
			$ip = inet_pton( $session['ip'] );
			unset( $session['ip'] );
		}

		if ( ! empty( $_SERVER['HTTP_HOST'] ) && ! isset( $session['host'] ) ) {
			$session['host'] = $_SERVER['HTTP_HOST'];
		}

		$expiration = $session['expiration'];
		$login      = $session['login'];
		unset( $session['expiration'], $session['login'] );

		return array(
			'user_id'      => $this->user_id,
			'verifier'     => $verifier,
			'expiration'   => $expiration,
			'login'        => $login,
			'ip'           => $ip,
			'session_meta' => json_encode( $session, JSON_UNESCAPED_UNICODE )
		);
	}

	protected function convert_session_from_db_format( $session ) {
		$new_session = (array) json_decode( $session->session_meta );

		foreach ( [ 'expiration', 'login' ] as $column ) {
			$new_session[$column] = $session->$column;
		}

		if ( ! empty( $session->ip ) ) {
			$new_session['ip'] = inet_ntop( $session->ip );
		}

		return $new_session;
	}

	protected function delete_sessions_by_verifiers( $verifiers ) {
		global $wpdb;

		if ( empty( $verifiers ) || ! is_array( $verifiers ) ) {
			return;
		}

		$verifier_in_sql = implode( "', '", esc_sql( $verifiers ) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM %i WHERE `user_id` = %d AND `verifier` IN ('$verifier_in_sql')",
			self::TABLE,
			$this->user_id
		) );

		foreach ( $verifiers as $verifier ) {
			$this->clear_user_session_cache( $verifier );
		}
	}

	function clear_user_session_cache( $verifier = false, $clear_all = false ) {
		if ( $verifier ) {
			wp_cache_delete( $this->user_id . '__' . $verifier, 'user_sessions' );
		}

		if ( $clear_all ) {
			foreach ( $this->get_all_user_sessions() as $verifier => $session ) {
				wp_cache_delete( $this->user_id . '__' . $verifier, 'user_sessions' );
			}
		}

		wp_cache_delete( 'sessions__' . $this->user_id, 'user_sessions' );
	}
}
