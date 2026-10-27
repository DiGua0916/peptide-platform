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
			'status'     => (string) $r['status'],
			'fails'      => (int) $r['fails'],
			'error'      => (string) $r['error'],
			'info'       => is_array( $info ) ? $info : array(),
			'last_check' => (int) $r['last_check'],
			'last_ok'    => (int) $r['last_ok'],
			'last_full'  => (int) $r['last_full'],
			'created'    => (int) $r['created'],
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
	 * @return array{0:int,1:string}
	 */
	public static function register( string $url, string $key, string $name ): array {
		global $wpdb;
		$t    = Db::table( 'sites' );
		$url  = trailingslashit( $url );
		$name = mb_substr( '' !== trim( $name ) ? $name : (string) wp_parse_url( $url, PHP_URL_HOST ), 0, 190 );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE url = %s", $url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'name'    => $name,
					'url'     => $url,
					'key_enc' => Crypto::encrypt( $key ),
					'status'  => 'unknown',
					'created' => time(),
				)
			);
			return array( (int) $wpdb->insert_id, 'new' );
		}
		if ( hash_equals( self::key( $id ), $key ) ) {
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
