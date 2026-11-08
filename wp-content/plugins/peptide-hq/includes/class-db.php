<?php
/**
 * 总后台的表：店铺、每天的数字、告警、服务器。
 * v2：店铺换密钥再注册时，新密钥先放在 key_pending，检查连得上才换。
 * v3：服务器表；店铺记录分配的服务器（server_id）和店铺的固定编号（uuid，换主域名后仍然认得）。
 * v4：固定管理地址与可变顾客地址分开保存；旧 url 继续作为机器连接地址。
 * v5（2026-11-08）：插件管理——插件库（plugins）和每个店铺装了哪些插件（site_plugins）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Db {

	public const VERSION = '5';
	public const OPTION  = 'pchq_db';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'pchq_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$s = self::table( 'sites' );
		$d = self::table( 'daily' );
		$a = self::table( 'alerts' );
		$v = self::table( 'servers' );
		$p = self::table( 'plugins' );
		$sp = self::table( 'site_plugins' );
		dbDelta(
			"CREATE TABLE {$s} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  url varchar(255) NOT NULL DEFAULT '',
  management_url varchar(255) NOT NULL DEFAULT '',
  public_url varchar(255) NOT NULL DEFAULT '',
  key_enc text NOT NULL,
  key_pending text NULL,
  uuid varchar(64) NOT NULL DEFAULT '',
  server_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'unknown',
  fails smallint(5) unsigned NOT NULL DEFAULT 0,
  error varchar(255) NOT NULL DEFAULT '',
  info longtext NULL,
  last_check int(10) unsigned NOT NULL DEFAULT 0,
  last_ok int(10) unsigned NOT NULL DEFAULT 0,
  last_full int(10) unsigned NOT NULL DEFAULT 0,
  created int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY url (url),
  KEY uuid (uuid)
) {$c};
CREATE TABLE {$d} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  orders int(10) unsigned NOT NULL DEFAULT 0,
  revenue decimal(14,2) NOT NULL DEFAULT 0,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  currency varchar(8) NOT NULL DEFAULT '',
  updated int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day),
  KEY day (day)
) {$c};
CREATE TABLE {$a} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  type varchar(32) NOT NULL DEFAULT '',
  ref varchar(190) NOT NULL DEFAULT '',
  message varchar(255) NOT NULL DEFAULT '',
  created int(10) unsigned NOT NULL DEFAULT 0,
  resolved int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY open (resolved,site_id)
) {$c};
CREATE TABLE {$v} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  created int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) {$c};
CREATE TABLE {$p} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(64) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL DEFAULT '',
  version varchar(64) NOT NULL DEFAULT '',
  sha256 char(64) NOT NULL DEFAULT '',
  size int(10) unsigned NOT NULL DEFAULT 0,
  auto_new tinyint(1) unsigned NOT NULL DEFAULT 0,
  uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
  uploaded_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY sha256 (sha256),
  KEY slug (slug)
) {$c};
CREATE TABLE {$sp} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  slug varchar(64) NOT NULL DEFAULT '',
  plugin_id bigint(20) unsigned NOT NULL DEFAULT 0,
  state varchar(16) NOT NULL DEFAULT '',
  installed_version varchar(64) NOT NULL DEFAULT '',
  operation_id varchar(36) NOT NULL DEFAULT '',
  error varchar(255) NOT NULL DEFAULT '',
  updated int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY site_slug (site_id,slug)
) {$c};"
		);
		update_option( self::OPTION, self::VERSION, true );
		Monitor::schedule();
	}
}
