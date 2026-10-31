<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Store {
	private const VERSION = '3';
	private const VERSION_OPTION = 'pc_panapay_db_version';
	private const ID_OPTION = 'pc_panapay_site_id';
	private const LOCK_SECONDS = 300;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_panapay_transactions';
	}

	public static function creations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_panapay_creations';
	}

	public static function installed(): bool {
		return self::VERSION === get_option( self::VERSION_OPTION );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta( "CREATE TABLE {$table} (
 transaction_hash char(64) NOT NULL,
 order_id bigint(20) unsigned NOT NULL,
 attempt_id char(32) NOT NULL,
 completion_order_id bigint(20) unsigned DEFAULT NULL,
 owner_token char(32) NOT NULL,
 provider_transaction_box text NOT NULL,
 state varchar(16) NOT NULL,
 created_utc datetime NOT NULL,
 updated_utc datetime NOT NULL,
 PRIMARY KEY  (transaction_hash),
 UNIQUE KEY completion_order_id (completion_order_id),
 KEY order_id (order_id)
) {$wpdb->get_charset_collate()};" );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			throw new \RuntimeException( 'Payment storage is unavailable.' );
		}
		// A table existing is not enough: these exact unique keys enforce the financial fence.
		foreach ( array( 'PRIMARY' => 'transaction_hash', 'completion_order_id' => 'completion_order_id' ) as $key => $column ) {
			$index = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ), ARRAY_A );
			if ( ! is_array( $index ) || 1 !== count( $index ) || '' !== (string) $wpdb->last_error
				|| 0 !== (int) $index[0]['Non_unique'] || 1 !== (int) $index[0]['Seq_in_index']
				|| $column !== $index[0]['Column_name'] || null !== $index[0]['Sub_part'] ) {
				throw new \RuntimeException( 'Payment storage is unavailable.' );
			}
		}
		$column = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} WHERE Field = %s", 'provider_transaction_box' ), ARRAY_A );
		if ( ! is_array( $column ) || 1 !== count( $column ) || '' !== (string) $wpdb->last_error
			|| 'text' !== strtolower( (string) $column[0]['Type'] ) || 'NO' !== $column[0]['Null'] ) {
			throw new \RuntimeException( 'Payment storage is unavailable.' );
		}
		$creations = self::creations_table();
		dbDelta( "CREATE TABLE {$creations} (
 order_id bigint(20) unsigned NOT NULL,
 attempt_id char(32) NOT NULL,
 state varchar(16) NOT NULL,
 owner_token char(32) NOT NULL,
 updated_utc datetime NOT NULL,
 PRIMARY KEY  (order_id)
) {$wpdb->get_charset_collate()};" );
		if ( $creations !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $creations ) ) ) ) {
			throw new \RuntimeException( 'Payment storage is unavailable.' );
		}
		$primary = $wpdb->get_results( "SHOW INDEX FROM {$creations} WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( ! is_array( $primary ) || 1 !== count( $primary ) || '' !== (string) $wpdb->last_error
			|| 0 !== (int) $primary[0]['Non_unique'] || 1 !== (int) $primary[0]['Seq_in_index']
			|| 'order_id' !== $primary[0]['Column_name'] || null !== $primary[0]['Sub_part'] ) {
			throw new \RuntimeException( 'Payment storage is unavailable.' );
		}
		add_option( self::ID_OPTION, bin2hex( random_bytes( 4 ) ), '', false );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function site_id(): string {
		$id = get_option( self::ID_OPTION );
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{8}$/D', $id ) ) {
			throw new \RuntimeException( 'Payment site identity is unavailable.' );
		}
		return $id;
	}

	/** Ordinary request lease only. It never permits replay of claimed payment side effects. */
	public static function acquire( int $order_id ): array {
		global $wpdb;
		$name = 'pc_panapay_lock_' . $order_id;
		$value = sprintf( '%.6F', microtime( true ) + self::LOCK_SECONDS ) . '|' . bin2hex( random_bytes( 16 ) );
		$previous = $wpdb->suppress_errors( true );
		try {
			$acquired = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'no' ), array( '%s', '%s', '%s' ) );
			if ( ! $acquired ) {
				$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
				if ( is_string( $old ) && preg_match( '/^([0-9]+[.][0-9]{6})[|][a-f0-9]{32}$/D', $old, $match )
					&& (float) $match[1] < microtime( true ) ) {
					$acquired = $wpdb->query( $wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
						$value, $name, $old
					) );
				}
			}
			if ( 1 !== (int) $acquired ) { throw new \RuntimeException( 'payment_busy' ); }
			return array( 'name' => $name, 'value' => $value );
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	public static function assert_owner( array $lock ): void {
		global $wpdb;
		$actual = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock['name'] ) );
		if ( ! is_string( $actual ) || ! hash_equals( $lock['value'], $actual )
			|| (float) explode( '|', $actual, 2 )[0] <= microtime( true ) ) {
			throw new \RuntimeException( 'payment_lock_expired' );
		}
	}

	public static function release( array $lock ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $lock['name'], 'option_value' => $lock['value'] ), array( '%s', '%s' ) );
	}

	/** Remote creation has its own permanent per-order reservation, independent of request leases. */
	public static function reserve_creation( int $order_id, string $attempt_id ): array {
		if ( ! self::installed() || $order_id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/D', $attempt_id ) ) {
			throw new \RuntimeException( 'payment_creation_unavailable' );
		}
		$owner = bin2hex( random_bytes( 16 ) );
		$db = Database::open();
		$table = self::creations_table();
		try {
			self::no_completion( $db, $order_id );
			$result = $db->query( $db->prepare(
				"INSERT IGNORE INTO {$table} (order_id, attempt_id, state, owner_token, updated_utc) VALUES (%d, %s, %s, %s, %s)",
				$order_id, $attempt_id, 'creating', $owner, gmdate( 'Y-m-d H:i:s' )
			) );
			if ( false === $result ) { throw new \RuntimeException( 'payment_creation_unavailable' ); }
			$row = self::creation_row( $db, $order_id );
			if ( 1 !== (int) $result ) {
				if ( null === $row || 'failed' !== $row['state'] || hash_equals( $attempt_id, $row['attempt_id'] ) ) {
					throw new \RuntimeException( 'payment_creation_busy' );
				}
				// Only a definite failed attempt can be replaced; there is no clock-based takeover.
				$result = $db->query( $db->prepare(
					"UPDATE {$table} SET attempt_id = %s, state = %s, owner_token = %s, updated_utc = %s WHERE order_id = %d AND attempt_id = %s AND owner_token = %s AND state = %s",
					$attempt_id, 'creating', $owner, gmdate( 'Y-m-d H:i:s' ), $order_id, $row['attempt_id'], $row['owner_token'], 'failed'
				) );
				if ( 1 !== (int) $result ) { throw new \RuntimeException( 'payment_creation_busy' ); }
				$row = self::creation_row( $db, $order_id );
			}
			if ( null === $row || 'creating' !== $row['state'] || ! hash_equals( $attempt_id, $row['attempt_id'] ) || ! hash_equals( $owner, $row['owner_token'] ) ) {
				throw new \RuntimeException( 'payment_creation_unavailable' );
			}
			return array( 'order_id' => $order_id, 'attempt_id' => $attempt_id, 'owner_token' => $owner );
		} finally { $db->close(); }
	}

	/** An order already claimed for native completion must not be sent to a hosted checkout again. */
	public static function assert_no_completion( int $order_id ): void {
		if ( $order_id <= 0 ) { throw new \RuntimeException( 'payment_creation_unavailable' ); }
		$db = Database::open();
		try { self::no_completion( $db, $order_id ); }
		finally { $db->close(); }
	}

	private static function no_completion( \wpdb $db, int $order_id ): void {
		$table = self::table();
		$rows = $db->get_results( $db->prepare(
			"SELECT order_id FROM {$table} WHERE completion_order_id = %d LIMIT 2", $order_id
		), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 1 || '' !== (string) $db->last_error ) {
			throw new \RuntimeException( 'payment_creation_unavailable' );
		}
		if ( $rows ) { throw new \RuntimeException( 'payment_creation_busy' ); }
	}

	/** A creation worker may write its session book only while its permanent reservation is creating. */
	public static function assert_creation( array $reservation ): void {
		if ( ! is_int( $reservation['order_id'] ?? null ) || $reservation['order_id'] <= 0
			|| ! is_string( $reservation['attempt_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $reservation['attempt_id'] )
			|| ! is_string( $reservation['owner_token'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $reservation['owner_token'] ) ) {
			throw new \RuntimeException( 'payment_creation_unavailable' );
		}
		$db = Database::open();
		try {
			$row = self::creation_row( $db, $reservation['order_id'] );
			if ( null === $row || 'creating' !== $row['state'] || ! hash_equals( $reservation['attempt_id'], $row['attempt_id'] )
				|| ! hash_equals( $reservation['owner_token'], $row['owner_token'] ) ) {
				throw new \RuntimeException( 'payment_creation_busy' );
			}
		} finally { $db->close(); }
	}

	/** Legacy saved attempts can have no row; an in-flight creation must defer every notification. */
	public static function creation_ready( int $order_id ): bool {
		if ( $order_id <= 0 ) { throw new \RuntimeException( 'payment_creation_unavailable' ); }
		$db = Database::open();
		try {
			$row = self::creation_row( $db, $order_id );
			return null === $row || 'creating' !== $row['state'];
		} finally { $db->close(); }
	}

	/** Updates only the exact creation owner and attempt; stale responses cannot replace a newer attempt. */
	public static function set_creation_state( array $reservation, string $state ): void {
		if ( ! is_int( $reservation['order_id'] ?? null ) || $reservation['order_id'] <= 0
			|| ! is_string( $reservation['attempt_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $reservation['attempt_id'] )
			|| ! is_string( $reservation['owner_token'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $reservation['owner_token'] )
			|| ! in_array( $state, array( 'created', 'unknown', 'failed', 'paid' ), true ) ) {
			throw new \RuntimeException( 'payment_creation_unavailable' );
		}
		$db = Database::open();
		try {
			$row = self::creation_row( $db, $reservation['order_id'] );
			if ( null === $row || ! hash_equals( $reservation['attempt_id'], $row['attempt_id'] ) || ! hash_equals( $reservation['owner_token'], $row['owner_token'] ) ) {
				throw new \RuntimeException( 'payment_creation_busy' );
			}
			$allowed = array(
				'creating' => array( 'created', 'unknown', 'failed', 'paid' ),
				'created' => array( 'created', 'failed', 'paid' ),
				'unknown' => array( 'unknown', 'failed', 'paid' ),
				'failed' => array( 'failed', 'paid' ),
				'paid' => array( 'paid' ),
			);
			if ( ! in_array( $state, $allowed[ $row['state'] ], true ) ) { throw new \RuntimeException( 'payment_creation_busy' ); }
			if ( $state === $row['state'] ) { return; }
			$table = self::creations_table();
			$result = $db->query( $db->prepare(
				"UPDATE {$table} SET state = %s, updated_utc = %s WHERE order_id = %d AND attempt_id = %s AND owner_token = %s AND state = %s",
				$state, gmdate( 'Y-m-d H:i:s' ), $reservation['order_id'], $reservation['attempt_id'], $reservation['owner_token'], $row['state']
			) );
			$after = self::creation_row( $db, $reservation['order_id'] );
			if ( false === $result || null === $after || ! hash_equals( $reservation['attempt_id'], $after['attempt_id'] )
				|| ! hash_equals( $reservation['owner_token'], $after['owner_token'] ) || $state !== $after['state'] ) {
				throw new \RuntimeException( 'payment_creation_busy' );
			}
		} finally { $db->close(); }
	}

	/** An authenticated failure can unlock only the same active attempt, never a newer one or paid slot. */
	public static function fail_creation( int $order_id, string $attempt_id ): void {
		if ( $order_id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/D', $attempt_id ) ) { throw new \RuntimeException( 'payment_creation_unavailable' ); }
		$db = Database::open();
		try {
			$row = self::creation_row( $db, $order_id );
			if ( null === $row || ! hash_equals( $attempt_id, $row['attempt_id'] ) || in_array( $row['state'], array( 'paid', 'failed' ), true ) ) { return; }
			$table = self::creations_table();
			$result = $db->query( $db->prepare(
				"UPDATE {$table} SET state = %s, updated_utc = %s WHERE order_id = %d AND attempt_id = %s AND owner_token = %s AND state = %s",
				'failed', gmdate( 'Y-m-d H:i:s' ), $order_id, $attempt_id, $row['owner_token'], $row['state']
			) );
			if ( false === $result ) { throw new \RuntimeException( 'payment_creation_unavailable' ); }
			$after = self::creation_row( $db, $order_id );
			if ( null === $after ) { throw new \RuntimeException( 'payment_creation_unavailable' ); }
			if ( ! hash_equals( $attempt_id, $after['attempt_id'] ) || 'paid' === $after['state'] || 'failed' === $after['state'] ) { return; }
			throw new \RuntimeException( 'payment_creation_busy' );
		} finally { $db->close(); }
	}

	private static function creation_row( \wpdb $db, int $order_id ): ?array {
		$table = self::creations_table();
		$rows = $db->get_results( $db->prepare(
			"SELECT order_id, attempt_id, state, owner_token FROM {$table} WHERE order_id = %d LIMIT 2", $order_id
		), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 1 || '' !== (string) $db->last_error ) {
			throw new \RuntimeException( 'payment_creation_unavailable' );
		}
		if ( ! $rows ) { return null; }
		$row = $rows[0];
		if ( (int) ( $row['order_id'] ?? 0 ) !== $order_id || ! is_string( $row['attempt_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $row['attempt_id'] )
			|| ! is_string( $row['owner_token'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $row['owner_token'] )
			|| ! in_array( $row['state'] ?? null, array( 'creating', 'created', 'unknown', 'failed', 'paid' ), true ) ) {
			throw new \RuntimeException( 'payment_creation_unavailable' );
		}
		return $row;
	}

	public static function transaction_hash( string $transaction, string $scope = '' ): string {
		return hash( 'sha256', 'panapay-transaction-v2|' . strlen( $scope ) . ':' . $scope . '|' . $transaction );
	}

	/**
	 * Permanently reserves one completion invocation for an order, before Woo hooks run.
	 * Only the request that inserted the reserved row receives can_complete=true.
	 * A lease expiry, retry or process crash never grants that permission again.
	 *
	 * @return array{state:string,fresh:bool,can_complete:bool}
	 */
	public static function claim( string $transaction, int $order_id, string $attempt_id, string $scope = '', bool $reserveCompletion = true ): array {
		if ( ! self::installed() || $order_id <= 0 || '' === $transaction || ! preg_match( '/^[a-f0-9]{32}$/D', $attempt_id ) ) {
			throw new \RuntimeException( 'payment_storage_unavailable' );
		}
		$table = self::table();
		$hash = self::transaction_hash( $transaction, $scope );
		$owner = bin2hex( random_bytes( 16 ) );
		$provider_box = Crypto::encrypt( $transaction, 'ledger-transaction' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$db = Database::open();
		try {
			$inserted = self::insert_claim( $db, $hash, $order_id, $attempt_id, $owner, $provider_box, $reserveCompletion, $now );
			$row = self::claim_row( $db, $hash );
			if ( null === $row && $reserveCompletion ) {
				// Prove that the failed insert was a completion-slot conflict, not an unknown write failure.
				$holders = $db->get_results( $db->prepare(
					"SELECT order_id FROM {$table} WHERE completion_order_id = %d LIMIT 2", $order_id
				), ARRAY_A );
				if ( ! is_array( $holders ) || 1 !== count( $holders ) || '' !== (string) $db->last_error
					|| (int) $holders[0]['order_id'] !== $order_id ) {
					throw new \RuntimeException( 'payment_storage_unavailable' );
				}
				// Another transaction owns this order's completion slot. Record, but never complete again.
				$inserted = self::insert_claim( $db, $hash, $order_id, $attempt_id, $owner, $provider_box, false, $now );
				$row = self::claim_row( $db, $hash );
			}
			if ( null === $row ) { throw new \RuntimeException( 'payment_storage_unavailable' ); }
			if ( (int) $row['order_id'] !== $order_id || ! hash_equals( $attempt_id, $row['attempt_id'] ) ) {
				throw new \RuntimeException( 'transaction_reused' );
			}
			$fresh = 1 === $inserted && hash_equals( $owner, $row['owner_token'] );
			if ( 1 === $inserted && ! $fresh ) { throw new \RuntimeException( 'payment_storage_unavailable' ); }
			return array(
				'state' => $row['state'],
				'fresh' => $fresh,
				'can_complete' => $fresh && $reserveCompletion && 'processing' === $row['state']
					&& null !== $row['completion_order_id'] && (int) $row['completion_order_id'] === $order_id,
			);
		} finally { $db->close(); }
	}

	/** INSERT IGNORE makes both the transaction identity and per-order completion key atomic. */
	private static function insert_claim( \wpdb $db, string $hash, int $order_id, string $attempt_id, string $owner, string $provider_box, bool $reserve, string $now ): int {
		$table = self::table();
		if ( $reserve ) {
			$sql = $db->prepare(
				"INSERT IGNORE INTO {$table} (transaction_hash, order_id, attempt_id, completion_order_id, owner_token, provider_transaction_box, state, created_utc, updated_utc) VALUES (%s, %d, %s, %d, %s, %s, %s, %s, %s)",
				$hash, $order_id, $attempt_id, $order_id, $owner, $provider_box, 'processing', $now, $now
			);
		} else {
			$sql = $db->prepare(
				"INSERT IGNORE INTO {$table} (transaction_hash, order_id, attempt_id, completion_order_id, owner_token, provider_transaction_box, state, created_utc, updated_utc) VALUES (%s, %d, %s, NULL, %s, %s, %s, %s, %s)",
				$hash, $order_id, $attempt_id, $owner, $provider_box, 'review', $now, $now
			);
		}
		$result = $db->query( $sql );
		if ( false === $result ) { throw new \RuntimeException( 'payment_storage_unavailable' ); }
		return (int) $result;
	}

	private static function claim_row( \wpdb $db, string $hash ): ?array {
		$table = self::table();
		$rows = $db->get_results( $db->prepare(
			"SELECT order_id, attempt_id, completion_order_id, owner_token, state FROM {$table} WHERE transaction_hash = %s LIMIT 2", $hash
		), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 1 || '' !== (string) $db->last_error ) {
			throw new \RuntimeException( 'payment_storage_unavailable' );
		}
		if ( ! $rows ) { return null; }
		$row = $rows[0];
		if ( ! is_string( $row['attempt_id'] ?? null ) || ! is_string( $row['owner_token'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{32}$/D', $row['owner_token'] )
			|| ! array_key_exists( 'completion_order_id', $row )
			|| ! in_array( $row['state'] ?? null, array( 'processing', 'applied', 'review' ), true ) ) {
			throw new \RuntimeException( 'payment_storage_unavailable' );
		}
		return $row;
	}

	/** Caller must enforce edit_shop_orders; never expose this data through checkout or public APIs. */
	public static function admin_transactions( int $order_id ): array {
		if ( $order_id <= 0 ) { return array(); }
		$table = self::table();
		$db = Database::open();
		try {
			$rows = $db->get_results( $db->prepare(
				"SELECT attempt_id, state, provider_transaction_box FROM {$table} WHERE order_id = %d ORDER BY created_utc DESC, transaction_hash DESC LIMIT 20", $order_id
			), ARRAY_A );
			if ( ! is_array( $rows ) || count( $rows ) > 20 || '' !== (string) $db->last_error ) {
				throw new \RuntimeException( 'payment_storage_unavailable' );
			}
			$result = array();
			foreach ( $rows as $row ) {
				if ( ! is_string( $row['attempt_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $row['attempt_id'] )
					|| ! in_array( $row['state'] ?? null, array( 'processing', 'applied', 'review' ), true )
					|| ! is_string( $row['provider_transaction_box'] ?? null ) ) {
					throw new \RuntimeException( 'payment_storage_unavailable' );
				}
				$result[] = array(
					'attempt_id' => $row['attempt_id'],
					'state' => $row['state'],
					'transaction' => Crypto::decrypt( $row['provider_transaction_box'], 'ledger-transaction' ),
				);
			}
			return $result;
		} finally { $db->close(); }
	}

	public static function set_transaction_state( string $transaction, int $order_id, string $state, string $scope = '' ): void {
		if ( ! in_array( $state, array( 'applied', 'review' ), true ) ) { throw new \InvalidArgumentException( 'Invalid transaction state.' ); }
		$hash = self::transaction_hash( $transaction, $scope );
		$db = Database::open();
		try {
			$result = $db->update( self::table(), array( 'state' => $state, 'updated_utc' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'transaction_hash' => $hash, 'order_id' => $order_id ), array( '%s', '%s' ), array( '%s', '%d' ) );
			if ( false === $result ) { throw new \RuntimeException( 'payment_storage_unavailable' ); }
			$row = self::claim_row( $db, $hash );
			if ( null === $row || (int) $row['order_id'] !== $order_id || $state !== $row['state'] ) {
				throw new \RuntimeException( 'payment_storage_unavailable' );
			}
		} finally { $db->close(); }
	}
}
