<?php
/**
 * 素材归因（CR）店铺端的数据表。和访客追踪的表分开升级（版本选项 peptide_core_creatives_db）。
 *
 * - pc_ad_creatives：素材（编号、图片 / 视频封面、可选的视频文件、属性），在“广告设置”页上传（2026-11-12 起）；
 * - pc_ad_ads：广告（Meta 的 ad_id → 素材编号），访客第一次带着某个 ad_id 进站时由线 1 / 线 2 写入；
 * - pc_ad_daily_spend：每条广告每天的花费、展示、链接点击，线 2 从 Meta 导出的 CSV 导入；
 * - pc_ad_spend_imports：每次导入的记录；
 * - pc_ad_attribute_vocab：素材属性词表，由总后台下发，没拉到之前用 Contract::DEFAULT_VOCAB。
 *
 * 金额都是美分整数；日期是店铺时区的 YYYY-MM-DD。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** 2 = 素材表加 video_id（2026-11-12）。 */
	public const VERSION = '2';

	/** 这些版本的表都能用（新列缺了不影响读写），只是要升级。 */
	private const USABLE = array( '1', '2' );
	public const OPTION  = 'peptide_core_creatives_db';
	public const TABLES  = array( 'creatives', 'ads', 'daily_spend', 'spend_imports', 'attribute_vocab' );

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_ad_' . $name;
	}

	public static function installed(): bool {
		return in_array( (string) get_option( self::OPTION ), self::USABLE, true );
	}

	/** 后台打开时检查一次；没装好或版本旧就装（可重复执行，dbDelta 只补缺的列）。 */
	public static function maybe_install(): void {
		if ( self::VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	/** 建表、写入初版词表。表都在了才写版本号，没成功下次再试。 */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::definition() );
		foreach ( self::TABLES as $name ) {
			$t = self::table( $name );
			if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
		}
		self::seed_vocab();
		update_option( self::OPTION, self::VERSION, false );
		return true;
	}

	public static function definition(): string {
		global $wpdb;
		$c  = $wpdb->get_charset_collate();
		$cr = self::table( 'creatives' );
		$ad = self::table( 'ads' );
		$sp = self::table( 'daily_spend' );
		$im = self::table( 'spend_imports' );
		$vo = self::table( 'attribute_vocab' );
		return "CREATE TABLE {$cr} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(16) NOT NULL,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  video_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attrs text NULL,
  custom text NULL,
  lang varchar(8) NOT NULL DEFAULT 'en',
  note varchar(500) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY status (status)
) {$c};
CREATE TABLE {$ad} (
  ad_id varchar(32) NOT NULL,
  code varchar(16) NOT NULL DEFAULT '',
  ad_name varchar(255) NOT NULL DEFAULT '',
  adset_id varchar(32) NOT NULL DEFAULT '',
  campaign_id varchar(32) NOT NULL DEFAULT '',
  first_seen_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  last_seen_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ad_id),
  KEY code (code)
) {$c};
CREATE TABLE {$sp} (
  ad_id varchar(32) NOT NULL,
  day date NOT NULL,
  spend_cents bigint(20) NOT NULL DEFAULT 0,
  impressions bigint(20) unsigned NOT NULL DEFAULT 0,
  link_clicks bigint(20) unsigned NOT NULL DEFAULT 0,
  ad_name varchar(255) NOT NULL DEFAULT '',
  import_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ad_id,day),
  KEY day (day)
) {$c};
CREATE TABLE {$im} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  filename varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  day_from date NULL,
  day_to date NULL,
  rows_total int(10) unsigned NOT NULL DEFAULT 0,
  rows_saved int(10) unsigned NOT NULL DEFAULT 0,
  errors text NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$c};
CREATE TABLE {$vo} (
  attr varchar(16) NOT NULL,
  value varchar(24) NOT NULL,
  label varchar(60) NOT NULL DEFAULT '',
  required tinyint(1) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) unsigned NOT NULL DEFAULT 1,
  position int(10) unsigned NOT NULL DEFAULT 0,
  ver int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (attr,value)
) {$c};";
	}

	/** 词表为空时写入初版（ver = 0，总后台下发的版本从 1 开始，会整体覆盖）。 */
	public static function seed_vocab(): void {
		global $wpdb;
		$t = self::table( 'attribute_vocab' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			return;
		}
		foreach ( Contract::DEFAULT_VOCAB as $i => $item ) {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'attr'     => $item['attr'],
					'value'    => $item['value'],
					'label'    => $item['label'],
					'required' => in_array( $item['attr'], Contract::REQUIRED_ATTRS, true ) ? 1 : 0,
					'active'   => 1,
					'position' => $i,
					'ver'      => 0,
				)
			);
		}
	}

	/**
	 * 启用中的词表（按位置排序）。
	 *
	 * @return array<int,array{attr:string,value:string,label:string,required:bool}>
	 */
	public static function vocab(): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array_map( static fn( array $i ): array => $i + array( 'required' => in_array( $i['attr'], Contract::REQUIRED_ATTRS, true ) ), Contract::DEFAULT_VOCAB );
		}
		$t    = self::table( 'attribute_vocab' );
		$rows = (array) $wpdb->get_results( "SELECT attr, value, label, required FROM {$t} WHERE active = 1 ORDER BY position, attr, value", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_map( static fn( array $r ): array => array( 'attr' => (string) $r['attr'], 'value' => (string) $r['value'], 'label' => (string) $r['label'], 'required' => '1' === (string) $r['required'] ), $rows );
	}
}
