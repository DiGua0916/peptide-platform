<?php
/** Durable HQ intent and verified root-worker receipts. No tenant resources are deleted by PHP. */
namespace PeptideHq;
defined( 'ABSPATH' ) || exit;

final class Site_Lifecycle {
	public const OPTION = 'pchq_site_lifecycle_v1';
	public const RETENTION = 259200;
	private const STATES = array( 'active', 'offlining', 'retained', 'restoring', 'purging', 'purged', 'failed' );
	private static bool $syncing = false;

	public static function register_hooks(): void { add_action( 'admin_init', array( self::class, 'sync' ), 0 ); }
	private static function uuid( string $value ): bool { return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value ); }
	private static function store_uuid( string $value ): bool { return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value ); }
	private static function book( $raw ): ?array {
		if ( null === $raw || false === $raw ) { return array( 'version' => 1, 'revision' => 0, 'records' => array() ); }
		if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), array( 'version', 'revision', 'records' ) ) || 1 !== ( $raw['version'] ?? null ) || ! is_int( $raw['revision'] ?? null ) || $raw['revision'] < 0 || ! is_array( $raw['records'] ?? null ) ) { return null; }
		foreach ( $raw['records'] as $id => $r ) {
			if ( ! is_array( $r ) || (string) $id !== (string) ( $r['site_id'] ?? '' ) || ! is_int( $r['site_id'] ?? null ) || $r['site_id'] < 1 ) { return null; }
			foreach ( array( 'uuid', 'state', 'operation_id', 'retirement_id', 'action', 'management_url', 'public_url' ) as $field ) { if ( ! is_string( $r[ $field ] ?? null ) ) { return null; } }
			foreach ( array( 'requested_at', 'retired_at', 'purge_at' ) as $field ) { if ( ! is_int( $r[ $field ] ?? null ) || $r[ $field ] < 0 ) { return null; } }
			foreach ( array( 'retry_requested_at', 'retry_actor_id' ) as $field ) { if ( isset( $r[ $field ] ) && ( ! is_int( $r[ $field ] ) || $r[ $field ] < 1 ) ) { return null; } }
			if ( ! is_int( $r['actor_id'] ?? null ) || $r['actor_id'] < 1 || ! is_string( $r['name'] ?? null ) || strlen( $r['name'] ) > 1000 || ! is_string( $r['message'] ?? null ) || strlen( $r['message'] ) > 3000 || ! is_string( $r['error_code'] ?? null ) || ! preg_match( '/^[a-z0-9_]{0,80}$/D', $r['error_code'] ) || isset( $r['summary_cleaned'] ) && ! is_bool( $r['summary_cleaned'] ) ) { return null; }
			if ( ! self::store_uuid( $r['uuid'] ) || ! in_array( $r['state'], self::STATES, true ) || ! self::uuid( $r['operation_id'] ) || ! self::uuid( $r['retirement_id'] ) || ! in_array( $r['action'], array( 'retire', 'restore' ), true ) || '' === Site_Urls::https_root( $r['management_url'] ) || '' === Site_Urls::https_root( $r['public_url'] ) ) { return null; }
		}
		return $raw;
	}
	private static function snapshot(): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPTION ) );
		$book = self::book( null === $value ? null : maybe_unserialize( $value ) );
		if ( null === $book ) { throw new \RuntimeException( 'lifecycle_storage' ); }
		return array( 'raw' => $value, 'book' => $book );
	}
	private static function commit( array $before, array $book ): bool {
		global $wpdb;
		$book['revision'] = $before['book']['revision'] + 1;
		if ( null === $before['raw'] ) { $ok = add_option( self::OPTION, $book, '', false ); }
		else { $ok = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize( $book ), self::OPTION, $before['raw'] ) ); }
		if ( $ok ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
		return (bool) $ok;
	}
	/** Unknown/corrupt lifecycle storage is closed, never treated as an active tenant. */
	public static function active( int $site ): bool {
		if ( $site < 1 ) { return false; }
		$book = self::book( get_option( self::OPTION, null ) );
		return null !== $book && ( ! isset( $book['records'][ $site ] ) || 'active' === $book['records'][ $site ]['state'] );
	}
	/** Read-only lifecycle metadata for delivery/reconciliation decisions; contains no credentials. */
	public static function record( int $site ): ?array {
		if ( $site < 1 ) { return null; }
		$book = self::book( get_option( self::OPTION, null ) );
		return null === $book ? null : ( $book['records'][ $site ] ?? null );
	}
	/** The original operation may be retried; this never starts a fresh retention clock. */
	public static function retry_action( int $site ): string {
		$r = self::record( $site );
		return $r && self::retryable( $r, $r['action'] ) ? $r['action'] : '';
	}
	private static function retryable( array $r, string $action ): bool {
		if ( $r['action'] !== $action ) { return false; }
		if ( 'retire' === $action ) { return in_array( $r['state'], array( 'offlining', 'failed' ), true ); }
		return 'restore' === $action && in_array( $r['state'], array( 'restoring', 'failed' ), true ) && $r['retired_at'] > 0 && $r['purge_at'] > time();
	}
	/** Minimal identity tombstones survive purging and prevent an old client from joining again. */
	public static function retired_uuid( string $uuid, string $url = '' ): bool {
		$book = self::book( get_option( self::OPTION, null ) );
		if ( null === $book ) { return true; }
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( $book['records'] as $r ) {
			if ( 'active' === $r['state'] ) { continue; }
			if ( '' !== $uuid && hash_equals( $r['uuid'], strtolower( $uuid ) ) ) { return true; }
			if ( '' !== $host && in_array( $host, array( strtolower( (string) wp_parse_url( $r['management_url'], PHP_URL_HOST ) ), strtolower( (string) wp_parse_url( $r['public_url'], PHP_URL_HOST ) ) ), true ) ) { return true; }
		}
		return false;
	}
	public static function all(): array {
		$book = self::book( get_option( self::OPTION, null ) );
		if ( null === $book ) { return array(); }
		return array_filter( $book['records'], static fn( array $r ): bool => 'active' !== $r['state'] );
	}
	private static function root(): string { return defined( 'PEPTIDE_HQ_PROVISION_QUEUE' ) ? rtrim( (string) PEPTIDE_HQ_PROVISION_QUEUE, '/\\' ) . '/lifecycle' : ''; }
	private static function error( string $code, string $message ): \WP_Error { return new \WP_Error( 'pchq_lifecycle_' . $code, $message ); }
	/** Called only by explicit administrator POST handlers. Enqueues identity, never commands or paths. */
	public static function request( int $site, string $action ) {
		if ( ! current_user_can( Admin\Menu::CAP ) || get_current_user_id() < 1 ) { return self::error( 'permission', '无权管理店铺下线。' ); }
		if ( ! in_array( $action, array( 'retire', 'restore' ), true ) || $site < 1 ) { return self::error( 'action', '店铺操作无效。' ); }
		self::sync();
		$target = Sites::get( $site );
		if ( ! $target || ! self::store_uuid( $target['uuid'] ) ) { return self::error( 'identity', '店铺身份不完整，未提交下线操作。' ); }
		$root = self::root();
		if ( '' === $root || ! is_dir( $root . '/requests' ) || is_link( $root ) || is_link( $root . '/requests' ) || ! is_writable( $root . '/requests' ) ) { return self::error( 'queue', '下线服务尚未准备好，未提交操作。' ); }
		try {
			for ( $try = 0; $try < 3; ++$try ) {
				$before = self::snapshot(); $book = $before['book']; $prior = $book['records'][ $site ] ?? null;
				if ( $prior && ( $prior['uuid'] !== $target['uuid'] || $prior['management_url'] !== Site_Urls::https_root( Site_Urls::management( $target ) ) || $prior['public_url'] !== Site_Urls::https_root( Site_Urls::public_url( $target ) ) ) ) { return self::error( 'changed', '店铺身份已改变，已停止操作。' ); }
				if ( $prior && self::retryable( $prior, $action ) ) {
					// Actor, identity and operation DTO remain immutable for the root journal.
					$prior['state'] = 'retire' === $action ? 'offlining' : 'restoring';
					$prior['retry_requested_at'] = time(); $prior['retry_actor_id'] = get_current_user_id();
					$prior['error_code'] = ''; $prior['message'] = '';
					$book['records'][ $site ] = $prior;
					if ( self::commit( $before, $book ) ) { return self::enqueue( $prior ); }
					continue;
				}
				if ( 'retire' === $action && $prior && 'active' !== $prior['state'] ) {
					return self::error( 'state', '店铺已在下线流程中，请查看回收站。' );
				}
				if ( 'restore' === $action && ( ! $prior || 'retained' !== $prior['state'] || (int) ( $prior['purge_at'] ?? 0 ) <= time() ) ) { return self::error( 'expired', '店铺当前不可恢复；恢复期限已过或下线尚未完成。' ); }
				$id = wp_generate_uuid4();
				$r = array( 'site_id' => $site, 'uuid' => $target['uuid'], 'management_url' => Site_Urls::https_root( Site_Urls::management( $target ) ), 'public_url' => Site_Urls::https_root( Site_Urls::public_url( $target ) ), 'name' => $target['name'], 'action' => $action, 'operation_id' => $id, 'retirement_id' => 'retire' === $action ? $id : $prior['retirement_id'], 'actor_id' => get_current_user_id(), 'state' => 'retire' === $action ? 'offlining' : 'restoring', 'requested_at' => time(), 'retired_at' => 'retire' === $action ? 0 : $prior['retired_at'], 'purge_at' => 'retire' === $action ? 0 : $prior['purge_at'], 'error_code' => '', 'message' => '' );
				if ( '' === $r['management_url'] || '' === $r['public_url'] ) { return self::error( 'identity', '店铺网址无效，未提交操作。' ); }
				$book['records'][ $site ] = $r;
				if ( self::commit( $before, $book ) ) { return self::enqueue( $r ); }
			}
			return self::error( 'conflict', '其他窗口已更新店铺状态，请刷新后重试。' );
		} catch ( \Throwable $e ) { return self::error( 'storage', '下线状态暂时无法确认，未继续执行。' ); }
	}
	private static function enqueue( array $r ) {
		$body = wp_json_encode( array( 'version' => 1, 'operation_id' => $r['operation_id'], 'actor_id' => $r['actor_id'], 'site_id' => $r['site_id'], 'uuid' => $r['uuid'], 'management_url' => $r['management_url'], 'public_url' => $r['public_url'], 'action' => $r['action'], 'retirement_id' => $r['retirement_id'] ), JSON_UNESCAPED_SLASHES );
		$path = self::root() . '/requests/' . $r['operation_id'] . '.json';
		if ( is_file( $path ) && ! is_link( $path ) ) { return hash_equals( (string) file_get_contents( $path ), $body ) ? true : self::error( 'collision', '操作编号已被占用，状态保持关闭。' ); }
		$handle = @fopen( $path, 'x' );
		if ( ! $handle ) { return self::error( 'queue', '操作已暂停，任务暂时未能入队；请刷新后重试。' ); }
		chmod( $path, 0600 ); $written = fwrite( $handle, $body ); fflush( $handle ); fclose( $handle );
		return strlen( $body ) === $written ? true : self::error( 'queue', '操作已暂停，任务未能完整提交，请联系管理员。' );
	}
	/** Read exactly one bounded regular root-owned receipt; reject symlinks and replacement races. */
	private static function receipt( array $r ): ?array {
		$root = self::root(); $path = $root . '/results/' . $r['operation_id'] . '.json';
		if ( '' === $root || is_link( $root ) || is_link( $root . '/results' ) || is_link( $path ) || ! is_file( $path ) ) { return null; }
		$before = lstat( $path );
		if ( ! $before || 0 !== $before['uid'] || 1 !== $before['nlink'] || $before['size'] > 8192 || ( $before['mode'] & 0022 ) ) { return null; }
		$handle = @fopen( $path, 'rb' ); if ( ! $handle ) { return null; }
		$stat = fstat( $handle ); $text = fread( $handle, 8193 ); fclose( $handle );
		if ( ! $stat || $stat['ino'] !== $before['ino'] || $stat['dev'] !== $before['dev'] || $stat['size'] !== $before['size'] || strlen( $text ) !== $stat['size'] ) { return null; }
		$p = json_decode( $text, true );
		$allowed = array( 'state', 'site_id', 'uuid', 'retirement_id', 'retired_at', 'purge_at', 'error_code', 'message' );
		if ( ! is_array( $p ) || array_diff( array_keys( $p ), $allowed ) || ! is_int( $p['site_id'] ?? null ) || $p['site_id'] !== $r['site_id'] || ( $p['uuid'] ?? '' ) !== $r['uuid'] || ( $p['retirement_id'] ?? '' ) !== $r['retirement_id'] || ! in_array( $p['state'] ?? '', self::STATES, true ) || ( 'active' === $p['state'] && 'restore' !== $r['action'] ) ) { return null; }
		foreach ( array( 'retired_at', 'purge_at' ) as $key ) { if ( ! is_int( $p[ $key ] ?? null ) || $p[ $key ] < 0 ) { return null; } }
		if ( in_array( $p['state'], array( 'retained', 'restoring', 'purging', 'purged', 'active' ), true ) && ( $p['retired_at'] < 1 || $p['purge_at'] !== $p['retired_at'] + self::RETENTION ) ) { return null; }
		if ( $p['retired_at'] > time() + 300 || in_array( $p['state'], array( 'purging', 'purged' ), true ) && $p['purge_at'] > time() ) { return null; }
		if ( $r['retired_at'] > 0 && ( $p['retired_at'] !== $r['retired_at'] || $p['purge_at'] !== $r['purge_at'] ) ) { return null; }
		if ( isset( $p['error_code'] ) && ( ! is_string( $p['error_code'] ) || ! preg_match( '/^[a-z0-9_]{0,80}$/D', $p['error_code'] ) ) || isset( $p['message'] ) && ( ! is_string( $p['message'] ) || strlen( $p['message'] ) > 3000 ) ) { return null; }
		return $p;
	}
	/** Only trusted root receipts can advance state or make a restored store active. */
	public static function sync(): void {
		if ( self::$syncing || '' === self::root() ) { return; }
		self::$syncing = true;
		try {
			for ( $try = 0; $try < 3; ++$try ) {
				$before = self::snapshot(); $book = $before['book']; $changed = false;
				foreach ( $book['records'] as $id => $r ) {
					if ( in_array( $r['state'], array( 'active', 'purged' ), true ) ) { continue; }
					$p = self::receipt( $r );
					if ( 'restore' === $r['action'] && $r['operation_id'] !== $r['retirement_id'] ) {
						// Expiry belongs to the original retirement. Never let an old retained
						// receipt overwrite a newer restore; only a due root purge may advance it.
						$original = $r; $original['operation_id'] = $r['retirement_id'];
						$expiry = self::receipt( $original );
						if ( $expiry && in_array( $expiry['state'], array( 'purging', 'purged' ), true ) ) { $p = $expiry; }
					}
					if ( ! $p ) { continue; }
					$transitions = array( 'offlining' => array( 'offlining', 'retained', 'purging', 'purged', 'failed' ), 'retained' => array( 'retained', 'purging', 'purged' ), 'restoring' => array( 'restoring', 'active', 'retained', 'purged', 'failed' ), 'purging' => array( 'purging', 'purged', 'failed' ), 'failed' => array( 'failed', 'retained', 'purging', 'purged' ) );
					if ( 'restore' === $r['action'] ) { $transitions['failed'][] = 'active'; $transitions['restoring'][] = 'purging'; }
					if ( ! in_array( $p['state'], $transitions[ $r['state'] ] ?? array(), true ) ) { continue; }
					$next = array_merge( $r, $p ); $next['message'] = sanitize_text_field( $p['message'] ?? '' ); $next['error_code'] = $p['error_code'] ?? '';
					if ( $next !== $r ) { $book['records'][ $id ] = $next; $changed = true; }
				}
				if ( ! $changed ) { break; }
				if ( self::commit( $before, $book ) ) {
					break;
				}
			}
			self::clean_purged();
		} catch ( \Throwable $e ) { /* Keep the prior closed state when receipts/storage cannot be trusted. */ }
		finally { self::$syncing = false; }
	}
	private static function clean_purged(): void {
		for ( $try = 0; $try < 3; ++$try ) {
			$before = self::snapshot(); $book = $before['book']; $changed = false;
			foreach ( $book['records'] as $id => $r ) {
				if ( 'purged' !== $r['state'] || ! empty( $r['summary_cleaned'] ) || ! self::purged_summary( (int) $id, $r['uuid'] ) ) { continue; }
				$book['records'][ $id ]['summary_cleaned'] = true; $book['records'][ $id ]['name'] = ''; $changed = true;
			}
			if ( ! $changed || self::commit( $before, $book ) ) { return; }
		}
	}
	private static function purged_summary( int $site, string $uuid ): bool {
		global $wpdb;
		$target = Sites::get( $site ); if ( ! $target || ! hash_equals( $uuid, $target['uuid'] ) ) { return false; }
		if ( false === $wpdb->delete( Db::table( 'daily' ), array( 'site_id' => $site ) ) || false === $wpdb->delete( Db::table( 'alerts' ), array( 'site_id' => $site ) ) ) { return false; }
		return false !== $wpdb->update( Db::table( 'sites' ), array( 'info' => null, 'name' => '', 'error' => '', 'key_enc' => '', 'key_pending' => null, 'server_id' => 0 ), array( 'id' => $site ) );
	}
}
