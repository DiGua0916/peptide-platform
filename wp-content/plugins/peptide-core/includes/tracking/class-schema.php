<?php
/**
 * 访客追踪的数据表（VISITOR-ANALYTICS-TEST-01）。
 *
 * - pc_track_visitors：访客（浏览器里的匿名编号 vid，可关联登录账号）；
 * - pc_track_visits：访问（同一访客 30 分钟没有动静后，下一次活动算新的访问）；
 * - pc_track_events：事件（前端采集 source=client，WooCommerce 服务端事实 source=server）。
 *   同一访客的 event_uid 唯一，重复上报只记一次；
 * - pc_track_tabs：每个标签页的当前状态（当前页面、可见 / 空闲、视频），多标签页互不覆盖。
 *
 * 时间都存成毫秒整数（UTC）。测试阶段所有行 is_test = 1。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Schema {

	// v2（VISITOR-CART-01）：访客当前购物车快照；标签页记录最后事件的页面实例和序号（并发 / 乱序时单调更新）。
	// v3（VISITOR-ARCHIVE-01）：访问按最后活动时间排序的索引（访客归档分页）。
	// v4: immutable, first-successfully-captured optional visit attribution.
	public const DB_VERSION = '4';
	public const OPTION     = 'peptide_core_tracking_db';
	/** Successful checks only, scoped to this PHP request and physical table identity. */
	private static array $ready_cache = array();

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_track_' . $name;
	}

	public static function installed(): bool {
		global $wpdb;
		if ( self::DB_VERSION !== get_option( self::OPTION ) ) { self::$ready_cache = array(); return false; }
		$key = spl_object_id( $wpdb ) . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . self::DB_VERSION . '|' . implode( '|', array_map( array( self::class, 'table' ), array( 'visitors', 'visits', 'events', 'tabs' ) ) );
		if ( isset( self::$ready_cache[ $key ] ) ) { return true; }
		if ( ! self::ready() ) { return false; }
		self::$ready_cache[ $key ] = true;
		return true;
	}

	public static function install(): void {
		self::$ready_cache = array();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::definition() );
		// Never publish a version just because dbDelta returned: verify actual storage.
		if ( self::ready() ) { update_option( self::OPTION, self::DB_VERSION, false ); }
		self::$ready_cache = array();
	}

	private static function definition(): string {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$v       = self::table( 'visitors' );
		$s       = self::table( 'visits' );
		$e       = self::table( 'events' );
		$t       = self::table( 'tabs' );
		return "CREATE TABLE {$v} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  vid char(32) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  first_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  last_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  visits int(10) unsigned NOT NULL DEFAULT 0,
  device varchar(10) NOT NULL DEFAULT '',
  browser varchar(20) NOT NULL DEFAULT '',
  is_test tinyint(1) NOT NULL DEFAULT 1,
  cart longtext NULL,
  cart_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  cart_key varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY vid (vid),
  KEY user_id (user_id),
  KEY last_ms (last_ms)
) {$charset};
CREATE TABLE {$s} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visitor_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  start_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  last_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  landing_path varchar(500) NOT NULL DEFAULT '',
  attribution_version tinyint unsigned NOT NULL DEFAULT 0,
  attribution_event_uid varchar(64) NOT NULL DEFAULT '',
  attribution_ms bigint unsigned NOT NULL DEFAULT 0,
  attribution_path varchar(500) NOT NULL DEFAULT '',
  attribution_host varchar(253) NOT NULL DEFAULT '',
  attribution_query text NULL,
  utm_source varchar(120) NULL,
  utm_medium varchar(120) NULL,
  referrer_host varchar(253) NULL,
  device varchar(10) NOT NULL DEFAULT '',
  events int(10) unsigned NOT NULL DEFAULT 0,
  pageviews int(10) unsigned NOT NULL DEFAULT 0,
  stage tinyint(3) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_test tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY visitor (visitor_id,start_ms),
  KEY start_ms (start_ms),
  KEY last_ms (last_ms,id)
) {$charset};
CREATE TABLE {$e} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_uid varchar(64) NOT NULL,
  visitor_id bigint(20) unsigned NOT NULL,
  visit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tab_id varchar(64) NOT NULL DEFAULT '',
  page_instance_id varchar(64) NOT NULL DEFAULT '',
  seq int(10) unsigned NOT NULL DEFAULT 0,
  type varchar(32) NOT NULL,
  source varchar(8) NOT NULL DEFAULT 'client',
  occurred_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  received_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  page_type varchar(20) NOT NULL DEFAULT '',
  path varchar(500) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  data text NULL,
  is_test tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY visitor_uid (visitor_id,event_uid),
  KEY visit_time (visit_id,occurred_ms),
  KEY type_time (type,occurred_ms)
) {$charset};
CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visitor_id bigint(20) unsigned NOT NULL,
  visit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tab_id varchar(64) NOT NULL,
  page_instance_id varchar(64) NOT NULL DEFAULT '',
  page_type varchar(20) NOT NULL DEFAULT '',
  path varchar(500) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_name varchar(120) NOT NULL DEFAULT '',
  visibility varchar(8) NOT NULL DEFAULT '',
  activity varchar(8) NOT NULL DEFAULT '',
  last_type varchar(32) NOT NULL DEFAULT '',
  last_page_instance varchar(64) NOT NULL DEFAULT '',
  last_seq int(10) unsigned NOT NULL DEFAULT 0,
  last_occurred_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  last_signal_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  video text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY visitor_tab (visitor_id,tab_id),
  KEY last_signal (last_signal_ms)
) {$charset};";
	}

	/** Required tables, columns/types/defaults/nullability and indexes must all exist. */
	public static function ready(): bool {
		global $wpdb;
		// Explicit verification is also the invalidation boundary for deliberate DDL.
		self::$ready_cache = array();
		preg_match_all( '/CREATE TABLE (\S+) \(\n(.*?)\n\) [^;]*;/s', self::definition(), $tables, PREG_SET_ORDER );
		if ( 4 !== count( $tables ) ) { return false; }
		$normalize = static function ( string $type ): string { return preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower( $type ) ); };
		foreach ( $tables as $definition ) {
			$table = $definition[1]; // Internal prefix + constant table name only.
			$fields = $wpdb->get_results( "SHOW FULL COLUMNS FROM {$table}", ARRAY_A );
			if ( ! $fields || $wpdb->last_error ) { return false; }
			$columns = array_column( $fields, null, 'Field' );
			$indexes = array();
			foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) as $index ) {
				$indexes[ $index['Key_name'] ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				$indexes[ $index['Key_name'] ]['unique'] = 0 === (int) $index['Non_unique'];
				$indexes[ $index['Key_name'] ]['full_columns'] = ( $indexes[ $index['Key_name'] ]['full_columns'] ?? true ) && null === $index['Sub_part'];
			}
			if ( $wpdb->last_error ) { return false; }
			foreach ( explode( "\n", $definition[2] ) as $line ) {
				$line = rtrim( trim( $line ), ',' );
				if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:(\w+) )?\(([^)]+)\)$/', $line, $key ) ) {
					$name = 'PRIMARY KEY' === $key[1] ? 'PRIMARY' : $key[2];
					if ( ! isset( $indexes[ $name ] ) ) { return false; }
					ksort( $indexes[ $name ]['columns'] );
					if ( ! $indexes[ $name ]['full_columns'] || implode( ',', $indexes[ $name ]['columns'] ) !== $key[3] || ( 'KEY' !== $key[1] ) !== $indexes[ $name ]['unique'] ) { return false; }
					continue;
				}
				if ( ! preg_match( '/^(\w+) ([a-z]+(?:\(\d+\))?(?: unsigned)?) (.*)$/', $line, $field ) || ! isset( $columns[ $field[1] ] ) ) { return false; }
				$actual = $columns[ $field[1] ];
				$default = null;
				if ( preg_match( "/DEFAULT ('[^']*'|[0-9]+)/", $field[3], $match ) ) { $default = trim( $match[1], "'" ); }
				if ( $normalize( $actual['Type'] ) !== $normalize( $field[2] ) || ( str_contains( $field[3], 'NOT NULL' ) ? 'NO' : 'YES' ) !== $actual['Null'] || $default !== $actual['Default'] || str_contains( $field[3], 'AUTO_INCREMENT' ) !== str_contains( strtolower( $actual['Extra'] ), 'auto_increment' ) ) { return false; }
			}
		}
		return true;
	}

	/** 测试版留下的访客数（is_test = 1）。 */
	public static function test_visitors(): int {
		global $wpdb;
		return self::installed() ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'visitors' ) . ' WHERE is_test = 1' ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 只删除测试版留下的数据（is_test = 1），正式数据不动。 */
	public static function purge_test(): void {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT id FROM ' . self::table( 'visitors' ) . ' WHERE is_test = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		Privacy::delete_visitors( array_map( 'intval', (array) $ids ) );
		foreach ( array( 'events', 'visits' ) as $name ) {
			$wpdb->query( 'DELETE FROM ' . self::table( $name ) . ' WHERE is_test = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/** 删除全部追踪数据（保留表结构）。 */
	public static function purge(): void {
		global $wpdb;
		foreach ( array( 'events', 'tabs', 'visits', 'visitors' ) as $name ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}
}
