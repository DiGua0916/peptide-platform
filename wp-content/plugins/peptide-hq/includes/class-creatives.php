<?php
/**
 * 素材归因（CR）总后台端：接收各店每天的“按素材”汇总，维护素材属性词表并下发给店铺。
 *
 * - 表（模块自己的升级，版本选项 pchq_creatives_db，可重复执行）：
 *   pchq_creative_daily（店铺 + 日期 + 素材编号一行）、pchq_attribute_vocab（词表主版本）。
 * - 店铺 → 总后台：POST /peptide-hq/v1/creatives/report（签名同订单上报，Mail::verify）。格式见 clean_report()，
 *   店铺端按 PeptideCore\Creatives\Contract::report_payload() 生成。约定全文见 docs/CONTRACT.md 的“CR 素材归因”。
 * - 店铺 → 总后台：POST /peptide-hq/v1/creatives/spend，每日全店花费（导入 Meta 报表后推送），见 clean_spend()。
 * - 店铺 ← 总后台：GET /peptide-hq/v1/creatives/vocab，返回 {ver, items[]}。
 * - 每日全店花费仍写 Orders 的 pchq_ad_spend（source = csv），跨店报表的 ROAS 不用改。
 *
 * 打地基阶段：建表、校验、词表接口已完成；写入 pchq_creative_daily（store_rows()）和“素材对比”页面由线 3 实现。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Creatives {

	public const VERSION   = '1';
	public const OPTION    = 'pchq_creatives_db';
	/** 词表版本号：每次改词表加一，店铺按这个判断要不要更新。 */
	public const VOCAB_VER = 'pchq_attribute_vocab_ver';
	public const MAX_ROWS  = 500;

	/** 和店铺端 Contract 一致。 */
	public const COUNTS       = array( 'visitors', 'valid_visitors', 'add_to_cart', 'checkout', 'cashier', 'orders', 'sales_cents', 'spend_cents', 'impressions', 'link_clicks' );
	public const INTENT_TIERS = array( 'high', 'mid', 'low', 'invalid' );
	public const UNREGISTERED = 'UNREG';
	public const REQUIRED     = array( 'hook', 'fmt', 'angle' );

	/** 词表初版（和店铺端 Contract::DEFAULT_VOCAB 一致）。 */
	public const DEFAULT_VOCAB = array(
		array( 'hook', 'lab', '检测报告' ), array( 'hook', 'price', '价格' ), array( 'hook', 'ship', '发货速度' ), array( 'hook', 'q', '提问式' ),
		array( 'fmt', 'vid', '视频' ), array( 'fmt', 'img', '单图' ), array( 'fmt', 'car', '轮播' ), array( 'fmt', 'ugc', '用户视角' ),
		array( 'angle', 'purity', '纯度' ), array( 'angle', 'price', '价格' ), array( 'angle', 'trust', '信任' ), array( 'angle', 'range', '品类齐全' ),
		array( 'lang', 'en', '英文' ), array( 'lang', 'es', '西班牙文' ),
	);

	public static function register_hooks(): void {
		self::maybe_upgrade();
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function daily_table(): string {
		return Db::table( 'creative_daily' );
	}

	public static function vocab_table(): string {
		return Db::table( 'attribute_vocab' );
	}

	public static function ready(): bool {
		return self::VERSION === get_option( self::OPTION );
	}

	// ---------- 表结构 ----------

	public static function maybe_upgrade(): void {
		if ( ! self::ready() ) {
			self::install();
		}
	}

	/** 建表、写入初版词表。没成功就不写版本号，下次再试。 */
	public static function install(): bool {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$c     = $wpdb->get_charset_collate();
		$daily = self::daily_table();
		$vocab = self::vocab_table();
		dbDelta(
			"CREATE TABLE {$daily} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  code varchar(16) NOT NULL,
  attrs text NULL,
  custom text NULL,
  thumb varchar(500) NOT NULL DEFAULT '',
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  valid_visitors int(10) unsigned NOT NULL DEFAULT 0,
  intent_high int(10) unsigned NOT NULL DEFAULT 0,
  intent_mid int(10) unsigned NOT NULL DEFAULT 0,
  intent_low int(10) unsigned NOT NULL DEFAULT 0,
  intent_invalid int(10) unsigned NOT NULL DEFAULT 0,
  add_to_cart int(10) unsigned NOT NULL DEFAULT 0,
  checkout int(10) unsigned NOT NULL DEFAULT 0,
  cashier int(10) unsigned NOT NULL DEFAULT 0,
  orders int(10) unsigned NOT NULL DEFAULT 0,
  sales_cents bigint(20) NOT NULL DEFAULT 0,
  spend_cents bigint(20) NOT NULL DEFAULT 0,
  impressions bigint(20) unsigned NOT NULL DEFAULT 0,
  link_clicks bigint(20) unsigned NOT NULL DEFAULT 0,
  reported_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day,code),
  KEY day (day)
) {$c};
CREATE TABLE {$vocab} (
  attr varchar(16) NOT NULL,
  value varchar(24) NOT NULL,
  label varchar(60) NOT NULL DEFAULT '',
  required tinyint(1) unsigned NOT NULL DEFAULT 0,
  active tinyint(1) unsigned NOT NULL DEFAULT 1,
  position int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (attr,value)
) {$c};"
		);
		if ( ! Series_Db::has_table( $daily ) || ! Series_Db::has_table( $vocab ) ) {
			return false;
		}
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vocab}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( self::DEFAULT_VOCAB as $i => $item ) {
				$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$vocab,
					array( 'attr' => $item[0], 'value' => $item[1], 'label' => $item[2], 'required' => in_array( $item[0], self::REQUIRED, true ) ? 1 : 0, 'active' => 1, 'position' => $i, 'updated_at' => time() )
				);
			}
			update_option( self::VOCAB_VER, 1, false );
		}
		update_option( self::OPTION, self::VERSION, true );
		return true;
	}

	// ---------- 接口 ----------

	public static function routes(): void {
		register_rest_route(
			Register::NS,
			'/creatives/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_report' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
		register_rest_route(
			Register::NS,
			'/creatives/spend',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_spend' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
		register_rest_route(
			Register::NS,
			'/creatives/vocab',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'vocab_route' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
	}

	/**
	 * 整理一行（纯函数，规则和店铺端 Contract::report_row() 相同）。编号不合规时返回 null。
	 *
	 * @param mixed $row
	 * @return array<string,mixed>|null
	 */
	public static function clean_row( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$code = (string) ( $row['code'] ?? '' );
		if ( self::UNREGISTERED !== $code && ! preg_match( '/^C\d{4,9}$/', $code ) ) {
			return null;
		}
		$text = static function ( $map ): array {
			$out = array();
			foreach ( is_array( $map ) ? $map : array() as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) && preg_match( '/^[a-z][a-z0-9]{0,15}$/', $k ) && preg_match( '/^[a-z0-9]{1,24}$/', (string) $v ) ) {
					$out[ $k ] = (string) $v;
				}
			}
			ksort( $out );
			return $out;
		};
		$int = static fn( $v ): int => is_int( $v ) && $v > 0 ? $v : 0;
		$out = array(
			'code'   => $code,
			'attrs'  => $text( $row['attrs'] ?? array() ),
			'custom' => $text( $row['custom'] ?? array() ),
			'thumb'  => is_string( $row['thumb'] ?? null ) && preg_match( '#^https://#', $row['thumb'] ) ? substr( $row['thumb'], 0, 500 ) : '',
			'intent' => array(),
		);
		foreach ( self::INTENT_TIERS as $tier ) {
			$out['intent'][ $tier ] = $int( $row['intent'][ $tier ] ?? 0 );
		}
		foreach ( self::COUNTS as $key ) {
			$out[ $key ] = $int( $row[ $key ] ?? 0 );
		}
		return $out;
	}

	/**
	 * 整理一次上报（纯函数）。
	 *
	 * @param mixed $p 请求体
	 * @return array{day:string,tz:string,rows:array,untagged:array{visitors:int},rejected:int}|null 格式不对时为 null
	 */
	public static function clean_report( $p ): ?array {
		if ( ! is_array( $p ) || ! is_string( $p['day'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p['day'] ) || ! is_array( $p['rows'] ?? null ) || count( $p['rows'] ) > self::MAX_ROWS ) {
			return null;
		}
		$rows     = array();
		$rejected = 0;
		foreach ( $p['rows'] as $raw ) {
			$r = self::clean_row( $raw );
			if ( null === $r ) {
				++$rejected;
				continue;
			}
			$rows[ $r['code'] ] = $r;
		}
		ksort( $rows );
		$untagged = $p['untagged']['visitors'] ?? 0;
		return array(
			'day'      => $p['day'],
			'tz'       => is_string( $p['tz'] ?? null ) ? substr( $p['tz'], 0, 64 ) : '',
			'rows'     => array_values( $rows ),
			'untagged' => array( 'visitors' => is_int( $untagged ) && $untagged > 0 ? $untagged : 0 ),
			'rejected' => $rejected,
		);
	}

	/**
	 * @return array|\WP_Error { day, stored: 行数, rejected: 行数 }
	 */
	public static function handle_report( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$clean   = self::clean_report( $request->get_json_params() );
		if ( $site_id < 1 || null === $clean ) {
			return new \WP_Error( 'pchq_creatives_bad', 'Send one day (YYYY-MM-DD) with at most 500 rows.', array( 'status' => 400 ) );
		}
		if ( ! self::ready() ) {
			return new \WP_Error( 'pchq_creatives_db', 'The creative tables are not ready yet.', array( 'status' => 503 ) );
		}
		$stored = self::store_rows( $site_id, $clean );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		return array( 'day' => $clean['day'], 'stored' => $stored, 'rejected' => $clean['rejected'] );
	}

	/**
	 * 写入一家店一天的素材汇总：先删掉这一天原来的行，再写入（补传时整天覆盖）。线 3 实现。
	 *
	 * @param array{day:string,rows:array} $clean clean_report() 的结果
	 * @return int|\WP_Error 写入的行数
	 */
	public static function store_rows( int $site_id, array $clean ) {
		unset( $site_id, $clean );
		return new \WP_Error( 'pchq_creatives_pending', 'Creative reports are not stored yet (line 3).', array( 'status' => 501 ) );
	}

	/**
	 * 整理每日全店花费（纯函数）：{"days":[{"day":"2026-11-11","spend_cents":12345}]}，最多 62 天。
	 * 同一天出现多次时取最后一个；金额为 0 表示这一天没花费（总后台删掉这一行）。
	 *
	 * @param mixed $p
	 * @return array<string,int>|null 日期 => 美分；格式不对时为 null
	 */
	public static function clean_spend( $p ): ?array {
		if ( ! is_array( $p ) || ! is_array( $p['days'] ?? null ) || ! $p['days'] || count( $p['days'] ) > 62 ) {
			return null;
		}
		$out = array();
		foreach ( $p['days'] as $d ) {
			if ( ! is_array( $d ) || ! is_string( $d['day'] ?? null ) || ! Orders::valid_day( $d['day'] ) || ! is_int( $d['spend_cents'] ?? null ) || $d['spend_cents'] < 0 ) {
				return null;
			}
			$out[ $d['day'] ] = $d['spend_cents'];
		}
		ksort( $out );
		return $out;
	}

	/** 店铺导入 Meta 报表后推送每日全店花费，写进 Orders 的 pchq_ad_spend（source = csv）。 */
	public static function handle_spend( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$days    = self::clean_spend( $request->get_json_params() );
		if ( $site_id < 1 || null === $days ) {
			return new \WP_Error( 'pchq_creatives_spend_bad', 'Send 1 to 62 days of {day, spend_cents}.', array( 'status' => 400 ) );
		}
		if ( ! Orders::ready() ) {
			return new \WP_Error( 'pchq_orders_db', 'The spend table is not ready yet.', array( 'status' => 503 ) );
		}
		$saved = array();
		foreach ( $days as $day => $cents ) {
			if ( Orders::set_spend( $site_id, $day, $cents, 0, 'csv' ) ) {
				$saved[] = $day;
			}
		}
		return array( 'saved' => $saved );
	}

	/**
	 * 启用中的词表和版本号。
	 *
	 * @return array{ver:int,items:array<int,array{attr:string,value:string,label:string,required:bool,position:int}>}
	 */
	public static function vocab(): array {
		global $wpdb;
		$items = array();
		if ( self::ready() ) {
			$t    = self::vocab_table();
			$rows = (array) $wpdb->get_results( "SELECT attr, value, label, required, position FROM {$t} WHERE active = 1 ORDER BY position, attr, value", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $r ) {
				$items[] = array( 'attr' => (string) $r['attr'], 'value' => (string) $r['value'], 'label' => (string) $r['label'], 'required' => '1' === (string) $r['required'], 'position' => (int) $r['position'] );
			}
		}
		return array( 'ver' => (int) get_option( self::VOCAB_VER, 0 ), 'items' => $items );
	}

	public static function vocab_route(): array {
		return self::vocab();
	}
}
