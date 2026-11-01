<?php
/** Fresh mail-attempt facts. Installation is explicit; report requests never migrate. */
namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

final class Observation_Store {
	public const SCHEMA = 'pc_mail_observation_schema';
	public const DAYS = 180;
	public const BATCH = 200;

	public static function table( string $kind = 'messages' ): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_mail_' . ( in_array( $kind, array( 'messages', 'links', 'recipients' ), true ) ? $kind : 'messages' );
	}
	public static function installed(): bool { return '1' === get_option( self::SCHEMA ); }
	public static function now(): int { return (int) floor( microtime( true ) * 1000 ); }
	public static function opaque(): string { return bin2hex( random_bytes( 32 ) ); }
	public static function hash( string $value ): string { return hash( 'sha256', $value ); }
	public static function email_hash( string $email ): string { return hash_hmac( 'sha256', 'recipient|' . strtolower( trim( $email ) ), wp_salt( 'secure_auth' ) ); }
	public static function sender_key( string $email ): string { return hash_hmac( 'sha256', 'sender|' . $email, wp_salt( 'secure_auth' ) ); }

	/** Root must review/adopt this schema before calling install. No init/install hook. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$m = self::table(); $l = self::table( 'links' ); $r = self::table( 'recipients' ); $charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $m (
id char(64) NOT NULL,
family varchar(16) NOT NULL,
source_key varchar(96) NOT NULL,
source_attempt varchar(96) NOT NULL DEFAULT '',
attempted_ms bigint(20) unsigned NOT NULL,
expires_ms bigint(20) unsigned NOT NULL,
status varchar(16) NOT NULL DEFAULT 'pending',
mime varchar(32) NOT NULL DEFAULT '',
coverage varchar(32) NOT NULL DEFAULT 'not_observed',
sender varchar(254) NOT NULL DEFAULT '',
sender_key char(64) NOT NULL DEFAULT '',
recipient_count int(10) unsigned NOT NULL DEFAULT 0,
token_hash char(64) NOT NULL,
open_eligible tinyint(1) NOT NULL DEFAULT 0,
click_eligible tinyint(1) NOT NULL DEFAULT 0,
opened_ms bigint(20) unsigned NOT NULL DEFAULT 0,
clicked_ms bigint(20) unsigned NOT NULL DEFAULT 0,
woo_log_id bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY token (token_hash),
KEY cohort (attempted_ms,status),
KEY sender_cohort (sender_key,attempted_ms),
KEY expires_ms (expires_ms)
) ENGINE=InnoDB $charset;" );
		dbDelta( "CREATE TABLE $l (
id char(64) NOT NULL,
message_id char(64) NOT NULL,
token_hash char(64) NOT NULL,
target text NOT NULL,
clicked_ms bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY token (token_hash),
KEY message_id (message_id)
) ENGINE=InnoDB $charset;" );
		dbDelta( "CREATE TABLE $r (
message_id char(64) NOT NULL,
email_hash char(64) NOT NULL,
PRIMARY KEY  (message_id,email_hash),
KEY email_hash (email_hash)
) ENGINE=InnoDB $charset;" );
		$found = true;
		$columns = array(
			$m => array( 'id', 'family', 'source_key', 'source_attempt', 'attempted_ms', 'expires_ms', 'status', 'mime', 'coverage', 'sender', 'sender_key', 'recipient_count', 'token_hash', 'open_eligible', 'click_eligible', 'opened_ms', 'clicked_ms', 'woo_log_id' ),
			$l => array( 'id', 'message_id', 'token_hash', 'target', 'clicked_ms' ),
			$r => array( 'message_id', 'email_hash' ),
		);
		foreach ( $columns as $table => $expected ) {
			$actual = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
			$state = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			$found = $found && is_array( $actual ) && ! array_diff( $expected, $actual ) && 'innodb' === strtolower( $state['Engine'] ?? '' );
		}
		if ( $found ) { update_option( self::SCHEMA, '1', false ); }
	}

	public static function begin( array $source ): ?array {
		global $wpdb;
		$id = self::opaque(); $token = self::opaque(); $now = self::now();
		$row = array( 'id' => $id, 'family' => $source['family'], 'source_key' => substr( $source['key'], 0, 96 ), 'source_attempt' => substr( $source['attempt'] ?? '', 0, 96 ), 'attempted_ms' => $now, 'expires_ms' => $now + self::DAYS * DAY_IN_SECONDS * 1000, 'token_hash' => self::hash( $token ) );
		return false === $wpdb->insert( self::table(), $row ) ? null : array( 'id' => $id, 'token' => $token );
	}
	public static function update( string $id, array $data ): bool {
		global $wpdb;
		// Source identity, send-time cohort, expiry and credentials are immutable after begin().
		$allowed = array( 'status', 'mime', 'coverage', 'sender', 'sender_key', 'recipient_count', 'open_eligible', 'click_eligible', 'woo_log_id' );
		if ( array_diff( array_keys( $data ), $allowed ) ) { return false; }
		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}
	public static function link( string $id, string $target ): ?string {
		global $wpdb;
		$token = self::opaque();
		if ( false === $wpdb->insert( self::table( 'links' ), array( 'id' => self::opaque(), 'message_id' => $id, 'token_hash' => self::hash( $token ), 'target' => $target ) ) ) { return null; }
		return add_query_arg( 'pc_mail_click', $token, home_url( '/' ) );
	}
	public static function recipients( string $id, array $addresses ): bool {
		global $wpdb;
		foreach ( array_unique( array_map( 'strtolower', $addresses ) ) as $email ) {
			if ( ! is_email( $email ) || false === $wpdb->insert( self::table( 'recipients' ), array( 'message_id' => $id, 'email_hash' => self::email_hash( $email ) ) ) ) { return false; }
		}
		return true;
	}
	public static function replace_recipients( string $id, array $addresses ): bool {
		global $wpdb; $t = self::table( 'recipients' );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
		try {
			if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE message_id=%s", $id ) ) || ! self::recipients( $id, $addresses ) ) { throw new \RuntimeException( 'Mail recipient snapshot did not complete.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Mail recipient snapshot did not commit.' ); }
			return true;
		} catch ( \Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return false; }
	}

	/** Only frozen database targets can be returned. Invalid/expired input never writes. */
	public static function observe( string $kind, string $token, bool $count = true ): ?string {
		global $wpdb;
		if ( ! self::installed() || ! preg_match( '/\A[a-f0-9]{64}\z/D', $token ) || ! in_array( $kind, array( 'open', 'click' ), true ) ) { return null; }
		$m = self::table(); $l = self::table( 'links' ); $now = self::now(); $hash = self::hash( $token );
		if ( 'open' === $kind ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $m WHERE token_hash=%s AND expires_ms>%d AND status='accepted' AND open_eligible=1", $hash, $now ), ARRAY_A );
			if ( ! $row ) { return null; }
			if ( $count ) { $wpdb->query( $wpdb->prepare( "UPDATE $m SET opened_ms=%d WHERE id=%s AND opened_ms=0", $now, $row['id'] ) ); }
			return '';
		}
		// Navigation survives a later native content change even when measurement is ineligible.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT l.id,l.message_id,l.target,m.click_eligible FROM $l l INNER JOIN $m m ON m.id=l.message_id WHERE l.token_hash=%s AND m.expires_ms>%d AND m.status='accepted'", $hash, $now ), ARRAY_A );
		if ( ! $row || null === Observation_Links::target( $row['target'] ) ) { return null; }
		if ( $count && 1 === (int) $row['click_eligible'] ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $l SET clicked_ms=%d WHERE id=%s AND clicked_ms=0", $now, $row['id'] ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $m SET clicked_ms=%d WHERE id=%s AND clicked_ms=0", $now, $row['message_id'] ) );
		}
		return $row['target'];
	}

	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( $ids, static fn( $id ) => is_string( $id ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $id ) ) );
		if ( ! $ids ) { return 0; }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'Mail observation cleanup could not start.' ); }
		try {
			foreach ( array( 'links', 'recipients', 'messages' ) as $kind ) {
				$t = self::table( $kind ); $column = 'messages' === $kind ? 'id' : 'message_id';
				$result = $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE $column IN ($placeholders)", ...$ids ) );
				if ( false === $result ) { throw new \RuntimeException( 'Mail observation cleanup did not complete.' ); }
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Mail observation cleanup did not commit.' ); }
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
		return count( $ids );
	}
	public static function prune(): void {
		if ( ! self::installed() ) { return; }
		global $wpdb; $t = self::table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE expires_ms<=%d ORDER BY expires_ms,id LIMIT %d", self::now(), self::BATCH ) );
		self::read_ok();
		self::delete( $ids );
	}

	/** Pure reduction used by the report and standalone contract tests. */
	public static function totals( array $rows ): array {
		$out = array_fill_keys( array( 'attempts', 'accepted', 'failed', 'uncertain', 'pending', 'open_eligible', 'click_eligible', 'opened', 'clicked' ), 0 );
		foreach ( $rows as $row ) { foreach ( $out as $key => $n ) { $out[ $key ] += (int) ( $row[ $key ] ?? 0 ); } }
		$out['open_rate'] = $out['open_eligible'] ? round( $out['opened'] / $out['open_eligible'] * 100, 2 ) : null;
		$out['click_rate'] = $out['click_eligible'] ? round( $out['clicked'] / $out['click_eligible'] * 100, 2 ) : null;
		if ( ! $out['open_eligible'] ) { $out['opened'] = null; }
		if ( ! $out['click_eligible'] ) { $out['clicked'] = null; }
		return $out;
	}
	public static function senders(): array {
		global $wpdb; $t = self::table();
		$rows = $wpdb->get_results( "SELECT sender_key,sender FROM $t WHERE sender_key<>'' GROUP BY sender_key,sender ORDER BY sender LIMIT 100", ARRAY_A );
		self::read_ok();
		return (array) $rows;
	}
	private static function read_ok(): void {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( 'Mail observation storage is unavailable.' ); }
	}
	public static function period( int $from, int $to, array $args = array() ): array {
		global $wpdb; $t = self::table(); $where = ''; $values = array( $from * 1000, $to * 1000 );
		if ( ! empty( $args['family'] ) ) { $where .= ' AND family=%s'; $values[] = $args['family']; }
		if ( ! empty( $args['sender'] ) ) { $where .= ' AND sender_key=%s'; $values[] = $args['sender']; }
		$sql = "SELECT family,source_key,sender,sender_key,mime,coverage,COUNT(*) attempts,SUM(status='accepted') accepted,SUM(status='failed') failed,SUM(status='uncertain') uncertain,SUM(status='pending') pending,SUM(status='accepted' AND open_eligible=1) open_eligible,SUM(status='accepted' AND click_eligible=1) click_eligible,SUM(status='accepted' AND open_eligible=1 AND opened_ms>0) opened,SUM(status='accepted' AND click_eligible=1 AND clicked_ms>0) clicked FROM $t WHERE attempted_ms>=%d AND attempted_ms<%d $where GROUP BY family,source_key,sender,sender_key,mime,coverage ORDER BY family,source_key,sender_key,mime,coverage";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
		self::read_ok();
		foreach ( $rows as &$row ) { $row = array_merge( $row, self::totals( array( $row ) ) ); } unset( $row );
		return array( 'totals' => self::totals( $rows ), 'rows' => $rows );
	}

	public static function export( string $email, int $page = 1 ): array {
		if ( ! self::installed() || ! is_email( $email ) ) { return array( 'data' => array(), 'done' => true ); }
		global $wpdb; $m = self::table(); $r = self::table( 'recipients' ); $offset = max( 0, $page - 1 ) * self::BATCH;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.id,m.family,m.source_key,m.attempted_ms,m.status,m.coverage,m.opened_ms,m.clicked_ms FROM $m m INNER JOIN $r r ON r.message_id=m.id WHERE r.email_hash=%s ORDER BY m.id LIMIT %d OFFSET %d", self::email_hash( $email ), self::BATCH, $offset ), ARRAY_A );
		self::read_ok();
		$data = array();
		foreach ( $rows as $row ) {
			$item = array(); foreach ( $row as $key => $value ) { if ( 'id' !== $key ) { $item[] = array( 'name' => $key, 'value' => (string) $value ); } }
			$data[] = array( 'group_id' => 'pc-mail-observations', 'group_label' => __( 'Email observations', 'peptide-core' ), 'item_id' => 'mail-' . $row['id'], 'data' => $item );
		}
		return array( 'data' => $data, 'done' => count( $rows ) < self::BATCH );
	}
	public static function erase( string $email, int $page = 1 ): array {
		$ids = array();
		if ( self::installed() && is_email( $email ) ) {
			global $wpdb; $r = self::table( 'recipients' );
			$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT message_id FROM $r WHERE email_hash=%s ORDER BY message_id LIMIT %d", self::email_hash( $email ), self::BATCH ) );
			self::read_ok();
			// A shared multi-recipient attempt is erased as a whole, revoking all its credentials.
			self::delete( $ids );
		}
		return array( 'items_removed' => (bool) $ids, 'items_retained' => false, 'messages' => array(), 'done' => count( $ids ) < self::BATCH );
	}
}
