<?php
/**
 * 接进来的店铺。店铺自己来注册（见 Register）：地址、名称、店铺生成的连接密钥（加密保存）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Sites {

	private static function row( ?array $r ): ?array {
		if ( ! $r ) {
			return null;
		}
		$info = json_decode( (string) ( $r['info'] ?? '' ), true );
		return array(
			'id'         => (int) $r['id'],
			'name'       => (string) $r['name'],
			'url'        => (string) $r['url'],
			'management_url' => (string) ( $r['management_url'] ?? '' ) ?: (string) $r['url'],
			'public_url' => (string) ( $r['public_url'] ?? '' ) ?: (string) $r['url'],
			'dual_domain' => '' !== (string) ( $r['management_url'] ?? '' ),
			'status'     => (string) $r['status'],
			'fails'      => (int) $r['fails'],
			'error'      => (string) $r['error'],
			'info'       => is_array( $info ) ? $info : array(),
			'last_check' => (int) $r['last_check'],
			'last_ok'    => (int) $r['last_ok'],
			'last_full'  => (int) $r['last_full'],
			'created'    => (int) $r['created'],
			'uuid'       => (string) ( $r['uuid'] ?? '' ),
			'server_id'  => (int) ( $r['server_id'] ?? 0 ),
		);
	}

	/** @return array<int,array> */
	public static function all(): array {
		global $wpdb;
		$t   = Db::table( 'sites' );
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name, id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $r['id'] ] = self::row( $r );
		}
		return $out;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$t = Db::table( 'sites' );
		return self::row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 店铺的连接密钥（解密后）；$pending 为 true 时取待确认的新密钥。 */
	public static function key( int $id, bool $pending = false ): string {
		global $wpdb;
		$t   = Db::table( 'sites' );
		$col = $pending ? 'key_pending' : 'key_enc';
		return Crypto::decrypt( (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$col} FROM {$t} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 店铺来注册。返回 [店铺 ID, 情况]：new = 新店铺，same = 已有且密钥没变，pending = 已有、换了密钥（待确认）。
	 *
	 * @return array{0:int,1:string}|\WP_Error
	 */
	public static function register( string $url, string $key, string $name, string $uuid = '', ?string $management_url = null, ?string $public_url = null ): array|\WP_Error {
		global $wpdb;
		$t    = Db::table( 'sites' );
		$url  = trailingslashit( $url );
		$explicit = null !== $management_url || null !== $public_url;
		if ( $explicit ) {
			$management_url = Site_Urls::https_root( (string) $management_url );
			$public_url = Site_Urls::https_root( null === $public_url ? $management_url : $public_url );
			if ( '' === $management_url || '' === $public_url || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $uuid ) ) {
				return new \WP_Error( 'pchq_management_identity', 'Explicit management registration needs HTTPS root addresses and a complete store UUID.', array( 'status' => 400 ) );
			}
			$url = $management_url;
		}
		$uuid = substr( sanitize_key( $uuid ), 0, 64 );
		$name = mb_substr( '' !== trim( $name ) ? $name : (string) wp_parse_url( $url, PHP_URL_HOST ), 0, 190 );
		// 先按店铺的固定编号找（店铺换了主域名，网址会变），再按网址找。
		$matches = '' !== $uuid ? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE uuid = %s LIMIT 2", $uuid ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( count( $matches ) > 1 ) { return new \WP_Error( 'pchq_store_ambiguous', 'The store UUID is registered more than once; registration was not changed.', array( 'status' => 409 ) ); }
		$id = (int) ( $matches[0] ?? 0 );
		if ( ! $id ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE url = %s", $url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		if ( ! $id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'name'    => $name,
					'url'     => $url,
					'management_url' => $explicit ? $management_url : '',
					'public_url' => $explicit ? $public_url : '',
					'uuid'    => $uuid,
					'key_enc' => Crypto::encrypt( $key ),
					'status'  => 'unknown',
					'created' => time(),
				)
			);
			return array( (int) $wpdb->insert_id, 'new' );
		}
		$site = self::get( $id );
		if ( $site && ( $explicit || $site['dual_domain'] ) && '' !== $site['uuid'] && ! hash_equals( $site['uuid'], $uuid ) ) {
			return new \WP_Error( 'pchq_store_identity', 'The registered store UUID does not match.', array( 'status' => 409 ) );
		}
		if ( hash_equals( self::key( $id ), $key ) ) {
			// 密钥对得上，才接受新的网址和编号（例如店铺换了主域名）。
			// An old client must not replace a pinned management origin with its current public home.
			if ( $site && $site['dual_domain'] && ! $explicit ) { return array( $id, 'same' ); }
			$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE url = %s AND id <> %d", $url, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $explicit && $taken ) { return new \WP_Error( 'pchq_management_taken', 'The management address is already registered to another store.', array( 'status' => 409 ) ); }
			if ( $site && ( ( $site['url'] !== $url && ! $taken ) || ( '' !== $uuid && $site['uuid'] !== $uuid ) || $explicit && ( ! $site['dual_domain'] || $site['management_url'] !== $management_url || $site['public_url'] !== $public_url ) ) ) {
				$fields = array( 'url' => $taken ? $site['url'] : $url, 'uuid' => '' !== $uuid ? $uuid : $site['uuid'] );
				if ( $explicit ) { $fields += array( 'management_url' => $management_url, 'public_url' => $public_url ); }
				self::update(
					$id,
					$fields
				);
				return array( $id, 'moved' );
			}
			return array( $id, 'same' );
		}
		self::update( $id, array( 'key_pending' => Crypto::encrypt( $key ) ) );
		return array( $id, 'pending' );
	}

	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'sites' ), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function rename( int $id, string $name ): void {
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 190 );
		if ( '' !== $name ) {
			self::update( $id, array( 'name' => $name ) );
		}
	}

	/** 从总后台移除店铺：它的每日数字和告警一起删掉（店铺本身不受影响；店铺下次注册时会重新出现）。 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Db::table( 'sites' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Db::table( 'daily' ), array( 'site_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Db::table( 'alerts' ), array( 'site_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
