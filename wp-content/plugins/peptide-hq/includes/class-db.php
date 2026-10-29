<?php
/**
 * 总后台的表：店铺、每天的数字、告警、服务器。
 * v2：店铺换密钥再注册时，新密钥先放在 key_pending，检查连得上才换。
 * v3：服务器表；店铺记录分配的服务器（server_id）和店铺的固定编号（uuid，换主域名后仍然认得）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Db {

	public const VERSION = '3';
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
		dbDelta(
			"CREATE TABLE {$s} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  url varchar(255) NOT NULL DEFAULT '',
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
) {$c};"
		);
		update_option( self::OPTION, self::VERSION, true );
		Monitor::schedule();
	}
}
