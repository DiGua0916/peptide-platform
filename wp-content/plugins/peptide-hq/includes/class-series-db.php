<?php
/**
 * M3 系列与商品目录的表结构（HQ-05、NF-12）。模块自己的升级类，版本选项 pchq_m3_db。
 *
 * 只加表、加列，不改 class-db.php 里的现有定义；可以重复执行，结果不变。
 * - pchq_series：系列（一套商品和价格、一套模板、一套活动、一套运费，各有版本号）。
 * - pchq_series_products：系列主商品库，一行一个商品，款式和价格在 payload 里。
 * - pchq_series_campaigns：系列营销活动（内容由 M4 定义，这里只负责存放和随同步下发）。
 * - pchq_sites 加列：series_id（索引）、buyer_name（投手）、onboarding_state（开店向导进度）、
 *   series_sync（这家店是否接受系列同步，默认不接受，差异确认后才打开）。
 * - 升级时建“系列 1”，把还没有系列的店铺全部归入。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Series_Db {

	public const VERSION = '1';
	public const OPTION  = 'pchq_m3_db';

	/** pchq_sites 上新增的列：列名 => 定义。 */
	public const SITE_COLUMNS = array(
		'series_id'        => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'buyer_name'       => "varchar(190) NOT NULL DEFAULT ''",
		'onboarding_state' => "varchar(32) NOT NULL DEFAULT ''",
		'series_sync'      => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
	);

	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	/** 表是否存在。 */
	public static function has_table( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 给已有的表补列（已有的列不动）。返回这次新加的列名。
	 *
	 * @param array<string,string> $columns 列名 => 定义
	 * @return string[]
	 */
	public static function add_columns( string $table, array $columns ): array {
		global $wpdb;
		$have  = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$added = array();
		foreach ( $columns as $name => $definition ) {
			if ( ! preg_match( '/^[a-z_]{1,40}$/D', $name ) || in_array( $name, $have, true ) ) {
				continue;
			}
			if ( false !== $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$added[] = $name;
			}
		}
		return $added;
	}

	/** 给已有的表补一个普通索引（已有同名索引时不动）。 */
	public static function add_index( string $table, string $name, string $columns ): void {
		global $wpdb;
		if ( ! preg_match( '/^[a-z_]{1,40}$/D', $name ) || ! preg_match( '/^[a-z_,]{1,120}$/D', $columns ) ) {
			return;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( null === $exists ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY {$name} ({$columns})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/** 建表、补列、建“系列 1”。任何一步没成功就不写版本号，下次再试。 */
	public static function install(): bool {
		global $wpdb;
		$sites = Db::table( 'sites' );
		if ( ! self::has_table( $sites ) ) {
			return false; // 总后台自己的表还没建好（刚启用插件），等下一次。
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$c        = $wpdb->get_charset_collate();
		$series   = Db::table( 'series' );
		$products = Db::table( 'series_products' );
		$camps    = Db::table( 'series_campaigns' );
		dbDelta(
			"CREATE TABLE {$series} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(64) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'active',
  master_site_id bigint(20) unsigned NOT NULL DEFAULT 0,
  catalog_ver int(10) unsigned NOT NULL DEFAULT 0,
  template_ver int(10) unsigned NOT NULL DEFAULT 0,
  campaign_ver int(10) unsigned NOT NULL DEFAULT 0,
  shipping_ver int(10) unsigned NOT NULL DEFAULT 0,
  shipping longtext NULL,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$c};
CREATE TABLE {$products} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  series_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sku varchar(100) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  payload longtext NULL,
  price_cents bigint(20) NOT NULL DEFAULT 0,
  compare_cents bigint(20) NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'active',
  position int(10) unsigned NOT NULL DEFAULT 0,
  ver int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY series_sku (series_id,sku)
) {$c};
CREATE TABLE {$camps} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  series_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(32) NOT NULL DEFAULT '',
  item_key varchar(64) NOT NULL DEFAULT '',
  config longtext NULL,
  active tinyint(1) unsigned NOT NULL DEFAULT 1,
  ver int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY series_item (series_id,kind,item_key)
) {$c};"
		);
		self::add_columns( $sites, self::SITE_COLUMNS );
		self::add_index( $sites, 'series_id', 'series_id' );

		$have = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$sites}", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! self::has_table( $series ) || ! self::has_table( $products ) || ! self::has_table( $camps ) || array_diff( array_keys( self::SITE_COLUMNS ), $have ) ) {
			return false;
		}
		if ( Series::ensure_default() < 1 ) {
			return false;
		}
		update_option( self::OPTION, self::VERSION, true );
		return true;
	}
}
