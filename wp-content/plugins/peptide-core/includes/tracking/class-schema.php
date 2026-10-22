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
	public const DB_VERSION = '3';
	public const OPTION     = 'peptide_core_tracking_db';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_track_' . $name;
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::OPTION );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$v       = self::table( 'visitors' );
		$s       = self::table( 'visits' );
		$e       = self::table( 'events' );
		$t       = self::table( 'tabs' );
		dbDelta(
			"CREATE TABLE {$v} (
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
) {$charset};"
		);
		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/** 删除全部测试数据（保留表结构）。 */
	public static function purge(): void {
		global $wpdb;
		foreach ( array( 'events', 'tabs', 'visits', 'visitors' ) as $name ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}
}
