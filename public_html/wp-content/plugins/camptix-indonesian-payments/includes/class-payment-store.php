<?php
namespace WordCamp\CampTix\IndonesianPayments;

defined( 'ABSPATH' ) || exit;

/** Durable, site-scoped attempts and connection-scoped database locks. */
class Payment_Store {
	private $locks = array();
	/** Retrieve an attempt using a private CampTix payment token. */
	public function get( $token ) {
		return $this->get_by_key( hash( 'sha256', $token ) );
	}

	/** Retrieve an attempt by its local index key. */
	public function get_by_key( $key ) {
		// A webhook lookup may have primed an older value before the order lock was acquired.
		wp_cache_delete( 'camptix_mayar_order_' . $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( 'camptix_mayar_order_' . $key, array() );
	}

	/** Persist an attempt and its public reference/provider indexes without autoloading. */
	public function save( $attempt ) {
		$key = hash( 'sha256', $attempt['token'] );
		foreach ( array( 'reference', 'payment_id', 'transaction_id' ) as $field ) {
			if ( ! empty( $attempt[ $field ] ) ) {
				$index = hash( 'sha256', $attempt['environment'] . ':' . $field . ':' . $attempt[ $field ] );
				$name  = 'camptix_mayar_index_' . $index;
				// The unique option name prevents one provider transaction from being assigned to two orders.
				add_option( $name, $key, '', false );
				wp_cache_delete( $name, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( get_option( $name ) !== $key ) {
					return false;
				}
			}
		}
		update_option( 'camptix_mayar_order_' . $key, $attempt, false );
		return $this->get( $attempt['token'] ) === $attempt;
	}

	/** Resolve only locally known identifiers; never search arbitrary blog metadata. */
	public function find( $environment, $field, $value ) {
		if ( ! is_string( $value ) || ! wp_is_uuid( $value ) ) {
			return array();
		}
		$index = hash( 'sha256', $environment . ':' . $field . ':' . $value );
		$key   = get_option( 'camptix_mayar_index_' . $index, '' );
		return $key ? $this->get_by_key( $key ) : array();
	}

	/** Serialize initiation and finalization without a lease that can expire mid-request. */
	public function lock( $identifier, $network = false ) {
		global $wpdb;
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}
		$prefix = $network ? $wpdb->base_prefix : $wpdb->prefix;
		$name   = 'mayar_' . substr( hash( 'sha256', DB_NAME . ':' . $prefix . ':' . $identifier ), 0, 56 );
		// Named locks are released by MySQL if the connection dies.
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '1' !== (string) $locked ) {
			return false;
		}
		if ( ! $this->locks ) {
			register_shutdown_function( array( $this, 'release_locks' ) );
		}
		$this->locks[ $name ] = true;
		return $name;
	}

	/** Release only a lock owned by this database connection. */
	public function unlock( $name ) {
		global $wpdb;
		if ( isset( $this->locks[ $name ] ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			unset( $this->locks[ $name ] );
		}
	}

	/** Also release locks after a fatal error on a potentially persistent connection. */
	public function release_locks() {
		foreach ( array_keys( $this->locks ) as $name ) {
			$this->unlock( $name );
		}
	}

	/** Generate a stable webhook secret per environment. Never change it on settings save. */
	public function webhook_secret( $environment ) {
		$key = 'camptix_mayar_webhook_' . $environment;
		add_option( $key, bin2hex( random_bytes( 32 ) ), '', false );
		return get_option( $key );
	}
}
