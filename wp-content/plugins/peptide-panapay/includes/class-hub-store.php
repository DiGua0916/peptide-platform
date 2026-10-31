<?php
/** Central attempts and durable notification delivery. All financial writes use the committed primary. */
namespace VialCove\Panapay;
defined( 'ABSPATH' ) || exit;

final class Hub_Store {
	public const VERSION = '2';
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'attempts', 'events' ), true ) ) { throw new \RuntimeException( 'invalid_table' ); }
		return $wpdb->prefix . 'pc_pay_hub_' . $name;
	}
	public static function installed(): bool { return self::VERSION === get_option( 'pc_panapay_hub_db' ); }
	public static function install(): void {
		global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$a = self::table( 'attempts' ); $e = self::table( 'events' ); $collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$a} (
 ref char(34) NOT NULL,
 site_id bigint(20) unsigned NOT NULL,
 site_uuid varchar(64) NOT NULL,
 order_id bigint(20) unsigned NOT NULL,
 local_reference varchar(96) NOT NULL,
 request_hash char(64) NOT NULL,
 state varchar(16) NOT NULL,
 owner_token char(32) NOT NULL,
 active_order char(64) DEFAULT NULL,
 amount varchar(40) NOT NULL,
 currency varchar(8) NOT NULL,
 secret_box text NOT NULL,
 link_key_box text NOT NULL,
 scope_hash char(64) NOT NULL,
 checkout_box text NOT NULL,
 return_box text NOT NULL,
 return_token_hash char(64) NOT NULL,
 type varchar(16) NOT NULL DEFAULT '',
 test_mode tinyint(1) NOT NULL DEFAULT 1,
 created_at bigint(20) unsigned NOT NULL,
 updated_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (ref),
 UNIQUE KEY store_reference (site_id,local_reference),
 UNIQUE KEY active_order (active_order),
 KEY store_order (site_id,order_id)
) {$collate};
CREATE TABLE {$e} (
 event_id char(64) NOT NULL,
 ref char(34) NOT NULL,
 status varchar(16) NOT NULL,
 transaction_box text NOT NULL,
 unique_tx char(64) DEFAULT NULL,
 unique_order char(64) DEFAULT NULL,
 delivery_state varchar(16) NOT NULL,
 disposition varchar(16) NOT NULL DEFAULT '',
 tries int(10) unsigned NOT NULL DEFAULT 0,
 next_delivery bigint(20) unsigned NOT NULL,
 last_error varchar(32) NOT NULL DEFAULT '',
 created_at bigint(20) unsigned NOT NULL,
 updated_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (event_id),
 UNIQUE KEY unique_tx (unique_tx),
 UNIQUE KEY unique_order (unique_order),
 KEY delivery (delivery_state,next_delivery),
 KEY reference_id (ref)
) {$collate};" );
		foreach ( array( $a => array( 'PRIMARY' => array( 'ref' ), 'store_reference' => array( 'site_id', 'local_reference' ), 'active_order' => array( 'active_order' ) ),
			$e => array( 'PRIMARY' => array( 'event_id' ), 'unique_tx' => array( 'unique_tx' ), 'unique_order' => array( 'unique_order' ) ) ) as $table => $keys ) {
			foreach ( $keys as $key => $columns ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name=%s", $key ), ARRAY_A );
				if ( ! is_array( $rows ) || count( $rows ) !== count( $columns ) || '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'hub_storage_unavailable' ); }
				foreach ( $rows as $i => $row ) {
					if ( (int) $row['Non_unique'] !== 0 || $row['Column_name'] !== $columns[ $i ] || null !== $row['Sub_part'] ) { throw new \RuntimeException( 'hub_storage_unavailable' ); }
				}
			}
		}
		update_option( 'pc_panapay_hub_db', self::VERSION, false );
	}
	private static function read( string $sql ): array {
		$db = Database::open();
		try {
			$rows = $db->get_results( $sql, ARRAY_A );
			if ( ! is_array( $rows ) || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_storage_unavailable' ); }
			return $rows;
		} finally { $db->close(); }
	}
	public static function get( string $ref ): ?array {
		global $wpdb; $t = self::table( 'attempts' );
		$r = self::read( $wpdb->prepare( "SELECT * FROM {$t} WHERE ref=%s LIMIT 2", $ref ) );
		return count( $r ) === 1 ? $r[0] : null;
	}
	public static function find( int $site, string $reference ): ?array {
		global $wpdb; $t = self::table( 'attempts' );
		$r = self::read( $wpdb->prepare( "SELECT * FROM {$t} WHERE site_id=%d AND local_reference=%s LIMIT 2", $site, $reference ) );
		return count( $r ) === 1 ? $r[0] : null;
	}
	/** Only the winner of the committed unique insert may send the provider request. */
	public static function reserve( array $record ): array {
		$record['active_order'] = hash( 'sha256', $record['site_id'] . '|' . $record['site_uuid'] . '|' . $record['order_id'] );
		$db = Database::open(); $t = self::table( 'attempts' );
		try {
			$cols = array_keys( $record );
			$sql = 'INSERT IGNORE INTO ' . $t . ' (`' . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $cols ), '%s' ) ) . ')';
			$n = $db->query( $db->prepare( $sql, ...array_values( $record ) ) );
			if ( false === $n || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_storage_unavailable' ); }
			$winner = $n === 1;
		} finally { $db->close(); }
		$row = self::find( (int) $record['site_id'], $record['local_reference'] );
		if ( ! $row || ! hash_equals( $record['site_uuid'], $row['site_uuid'] ) || ! hash_equals( $record['request_hash'], $row['request_hash'] ) ) { throw new Protocol_Error( 'payment_reference_conflict', 409 ); }
		return array( 'winner' => $winner, 'row' => $row );
	}
	public static function finish_create( array $record, array $response ): void {
		$state = $response['state'] ?? 'unknown';
		if ( ! in_array( $state, array( 'created', 'failed', 'unknown' ), true ) ) { $state = 'unknown'; }
		$data = array( 'state' => $state, 'updated_at' => time(), 'checkout_box' => '', 'type' => '' );
		if ( 'failed' === $state ) { $data['active_order'] = null; }
		if ( 'created' === $state ) { $data['checkout_box'] = Crypto::encrypt( $response['url'], 'hub-checkout' ); $data['type'] = $response['type']; }
		$db = Database::open();
		try {
			$n = $db->update( self::table( 'attempts' ), $data, array( 'ref' => $record['ref'], 'state' => 'creating', 'owner_token' => $record['owner_token'] ) );
			if ( $n !== 1 ) { throw new \RuntimeException( 'hub_creation_unconfirmed' ); }
			$a = self::table( 'attempts' ); $e = self::table( 'events' );
			$n = $db->query( $db->prepare( "UPDATE {$a} a SET active_order=NULL,state='failed',checkout_box='',type='' WHERE ref=%s AND EXISTS (SELECT 1 FROM {$e} f WHERE f.ref=a.ref AND f.status='FAILED') AND NOT EXISTS (SELECT 1 FROM {$e} s WHERE s.ref=a.ref AND s.status='SUCCEEDED')", $record['ref'] ) );
			if ( false === $n || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_creation_unconfirmed' ); }
		} finally { $db->close(); }
	}
	/** A durable event IS the relay outbox. Vendor acceptance does not depend on store availability. */
	public static function receive( array $attempt, string $status, string $transaction ): array {
		$event = hash( 'sha256', $attempt['ref'] . '|' . $status . '|' . $transaction );
		$db = Database::open(); $t = self::table( 'events' );
		try {
			$record = array( 'event_id' => $event, 'ref' => $attempt['ref'], 'status' => $status,
				'transaction_box' => Crypto::encrypt( $transaction, 'hub-transaction' ),
				'unique_tx' => 'SUCCEEDED' === $status ? hash( 'sha256', $attempt['scope_hash'] . '|' . $transaction ) : null,
				'unique_order' => 'SUCCEEDED' === $status ? hash( 'sha256', $attempt['site_id'] . '|' . $attempt['site_uuid'] . '|' . $attempt['order_id'] ) : null,
				'delivery_state' => 'pending', 'disposition' => '', 'tries' => 0, 'next_delivery' => time(), 'last_error' => '', 'created_at' => time(), 'updated_at' => time() );
			$sql = $db->prepare( "INSERT IGNORE INTO {$t} (event_id,ref,status,transaction_box,unique_tx,unique_order,delivery_state,disposition,tries,next_delivery,last_error,created_at,updated_at) VALUES (%s,%s,%s,%s,NULLIF(%s,''),NULLIF(%s,''),'pending','',0,%d,'',%d,%d)",
				$event, $record['ref'], $status, $record['transaction_box'], $record['unique_tx'] ?? '', $record['unique_order'] ?? '', time(), time(), time() );
			$n = $db->query( $sql );
			if ( false === $n || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_event_unconfirmed' ); }
			$row = $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE event_id=%s", $event ), ARRAY_A );
			if ( ! is_array( $row ) && $n === 0 ) {
				// Another order/transaction owns the financial slot. Keep evidence, never relay another success.
				$record['unique_tx'] = null; $record['unique_order'] = null; $record['delivery_state'] = 'review'; $record['disposition'] = 'review';
				$n = $db->query( $db->prepare( "INSERT IGNORE INTO {$t} (event_id,ref,status,transaction_box,delivery_state,disposition,tries,next_delivery,last_error,created_at,updated_at) VALUES (%s,%s,%s,%s,'review','review',0,%d,'transaction_conflict',%d,%d)", $event, $record['ref'], $status, $record['transaction_box'], time(), time(), time() ) );
				if ( false === $n || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_event_unconfirmed' ); }
				$row = $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE event_id=%s", $event ), ARRAY_A );
			}
			if ( ! is_array( $row ) || ! hash_equals( $attempt['ref'], $row['ref'] ) || $row['status'] !== $status
				|| ! hash_equals( $transaction, Crypto::decrypt( $row['transaction_box'], 'hub-transaction' ) ) ) { throw new \RuntimeException( 'hub_event_unconfirmed' ); }
			if ( 'FAILED' === $status ) {
				$a = self::table( 'attempts' );
				// A result arriving during create cannot release its durable creation authority.
				$n = $db->query( $db->prepare( "UPDATE {$a} a SET active_order=NULL,state='failed',checkout_box='',type='',updated_at=%d WHERE ref=%s AND state<>'creating' AND NOT EXISTS (SELECT 1 FROM {$t} e WHERE e.ref=a.ref AND e.status='SUCCEEDED')", time(), $attempt['ref'] ) );
				if ( false === $n || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_event_unconfirmed' ); }
			}
			return $row;
		} finally { $db->close(); }
	}
	public static function pending( int $limit = 10 ): array {
		global $wpdb; $t = self::table( 'events' );
		return self::read( $wpdb->prepare( "SELECT * FROM {$t} WHERE delivery_state='pending' AND next_delivery<=%d ORDER BY created_at,event_id LIMIT %d", time(), min( 30, max( 1, $limit ) ) ) );
	}
	/** Claims a short delivery window; repeats remain safe at the store's durable financial fence. */
	public static function claim_delivery( string $event ): ?array {
		$db = Database::open(); $t = self::table( 'events' );
		try {
			$n = $db->query( $db->prepare( "UPDATE {$t} SET next_delivery=%d,tries=tries+1,updated_at=%d WHERE event_id=%s AND delivery_state='pending' AND next_delivery<=%d", time() + 120, time(), $event, time() ) );
			if ( false === $n || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_event_unconfirmed' ); }
			return $n === 1 ? $db->get_row( $db->prepare( "SELECT * FROM {$t} WHERE event_id=%s", $event ), ARRAY_A ) : null;
		} finally { $db->close(); }
	}
	public static function delivery_result( string $event, string $state, string $disposition = '', string $error = '' ): void {
		if ( ! in_array( $state, array( 'pending', 'delivered', 'review' ), true ) || ! in_array( $disposition, array( '', 'paid', 'failed', 'review' ), true ) ) { throw new \RuntimeException( 'hub_event_invalid' ); }
		$db = Database::open();
		try {
			$n = $db->update( self::table( 'events' ), array( 'delivery_state' => $state, 'disposition' => $disposition,
				'last_error' => $error, 'next_delivery' => time() + 300, 'updated_at' => time() ), array( 'event_id' => $event, 'delivery_state' => 'pending' ) );
			if ( false === $n || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'hub_event_unconfirmed' ); }
		} finally { $db->close(); }
	}
	public static function attempts( array $filters = array(), int $page = 1 ): array {
		global $wpdb; $a = self::table( 'attempts' ); $e = self::table( 'events' );
		$where = '1=1';
		if ( ! empty( $filters['site_id'] ) ) { $where .= $wpdb->prepare( ' AND a.site_id=%d', (int) $filters['site_id'] ); }
		if ( in_array( $filters['state'] ?? '', array( 'creating', 'created', 'unknown', 'failed' ), true ) ) { $where .= $wpdb->prepare( ' AND a.state=%s', $filters['state'] ); }
		if ( in_array( $filters['status'] ?? '', array( 'SUCCEEDED', 'FAILED', 'pending', 'delivered', 'review' ), true ) ) {
			$where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$e} f WHERE f.ref=a.ref AND (f.status=%s OR f.delivery_state=%s))", $filters['status'], $filters['status'] );
		}
		$total = self::read( "SELECT COUNT(*) AS total FROM {$a} a WHERE {$where}" );
		$rows = self::read( $wpdb->prepare( "SELECT a.ref,a.site_id,a.site_uuid,a.order_id,a.local_reference,a.amount,a.currency,a.state,a.created_at,a.updated_at FROM {$a} a WHERE {$where} ORDER BY a.created_at DESC,a.ref DESC LIMIT 20 OFFSET %d", ( max( 1, min( 100000, $page ) ) - 1 ) * 20 ) );
		foreach ( $rows as &$row ) {
			$events = self::read( $wpdb->prepare( "SELECT status AS event_status,delivery_state,disposition FROM {$e} WHERE ref=%s ORDER BY (status='SUCCEEDED') DESC,created_at DESC,event_id DESC LIMIT 1", $row['ref'] ) );
			$row = array_merge( $row, $events[0] ?? array( 'event_status' => '', 'delivery_state' => '', 'disposition' => '' ) );
		} unset( $row );
		return array( 'rows' => $rows, 'total' => (int) $total[0]['total'], 'per_page' => 20 );
	}
	public static function summary( int $site_id = 0 ): array {
		global $wpdb; $a = self::table( 'attempts' ); $e = self::table( 'events' );
		$where = $site_id > 0 ? $wpdb->prepare( ' AND a.site_id=%d', $site_id ) : '';
		$currencies = self::read( "SELECT a.currency,CAST(SUM(CAST(a.amount AS DECIMAL(24,8))) AS CHAR) AS confirmed_amount,COUNT(*) AS confirmed_count FROM {$e} e INNER JOIN {$a} a ON a.ref=e.ref WHERE e.status='SUCCEEDED' AND e.unique_tx IS NOT NULL{$where} GROUP BY a.currency ORDER BY a.currency" );
		$counts = self::read( "SELECT SUM(e.delivery_state='pending') AS pending_count,SUM(e.delivery_state='review' OR e.disposition='review') AS review_count FROM {$e} e INNER JOIN {$a} a ON a.ref=e.ref WHERE 1=1{$where}" );
		return array( 'currencies' => $currencies, 'pending_count' => (int) ( $counts[0]['pending_count'] ?? 0 ), 'review_count' => (int) ( $counts[0]['review_count'] ?? 0 ) );
	}
}
