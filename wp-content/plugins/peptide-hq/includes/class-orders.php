<?php
/**
 * 订单汇总（HQ-13、OR-03 的数据底座）：各店把订单摘要报到总后台，供跨店报表和退款标记使用。
 *
 * - 表（模块自己的升级，版本选项 pchq_m10_db，可重复执行）：pchq_orders、pchq_refunds、pchq_ad_spend、pchq_abandoned；
 *   pchq_daily 加 gross_cents、refunded_cents 两列。
 *   第 2 版（2026-11-12）：pchq_orders 加 customer_id（店铺算出的客户编号，同一位顾客在各店相同）；
 *   新表 pchq_abandoned 存各店每天的弃单数（来自店铺每日汇总），报表的“弃单率”用它。
 * - 店铺 → 总后台 POST /peptide-hq/v1/orders/report：每批最多 50 条，按（店铺，订单号）覆盖写入，重复上报结果不变。
 *   签名校验用邮件中心同一个函数（店铺连接密钥 + X-PC-HQ-Site）。
 * - 报表口径：订单数 = 已付款且不是测试单；销售额 = 订单金额 − 已标记退款；时间按付款时间。
 *   三级（系列 → 投手 → 店铺）都由同一批店铺行往上加，所以合计相等。
 * - 广告花费先手填（pchq_ad_spend，每店每天一行）；CR 素材归因上线后由店铺导入 Meta 报表时自动推送（source = csv）。
 * - 顾客的姓名、邮箱、电话、地址不进总后台，只有邮箱的哈希。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Orders {

	public const VERSION   = '2';
	public const OPTION    = 'pchq_m10_db';

	/**
	 * 开关（默认关）：报表的“顾客”“弃单”“弃单率”三列、订单页的客户编号和店内订单编号、按客户编号搜索、收集各店每日汇总里的弃单数，
	 * 以及 hang- 单号的新显示（不带 #）和新搜法（去掉 #、不分大小写）。
	 * 平台所有者 2026-11-14 定的原则：和线上已有功能重叠的地方一律以线上为准，所以关着时这些都照 11-10 上线的写法。
	 * 这些是 2026-11-12 之后加的新东西；关着时页面和 11-10 上线的版本一样。打开：wp option update pchq_m10_extras 1。
	 */
	public const EXTRAS    = 'pchq_m10_extras';
	public const MAX_BATCH = 50;

	public static function register_hooks(): void {
		self::maybe_upgrade();
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'pchq_monitor_checks', array( self::class, 'monitor_check' ), 10, 4 );
		add_filter( 'pchq_alert_types', array( self::class, 'alert_types' ) );
		if ( self::extras() ) {
			add_action( 'pchq_summary_days', array( self::class, 'store_abandoned' ), 10, 2 );
			add_filter( 'http_response', array( self::class, 'watch_summary' ), 10, 3 );
		}
		Refunds::register_hooks();
		if ( is_admin() ) {
			foreach ( array( Admin\Orders_Page::class, Admin\Report_Page::class ) as $page ) {
				if ( class_exists( $page ) ) {
					$page::hooks();
				}
			}
		}
	}

	public static function table(): string {
		return Db::table( 'orders' );
	}

	public static function spend_table(): string {
		return Db::table( 'ad_spend' );
	}

	public static function abandoned_table(): string {
		return Db::table( 'abandoned' );
	}

	public static function ready(): bool {
		return self::VERSION === get_option( self::OPTION );
	}

	/** 新加的报表列和客户编号是不是打开了（默认关，见 EXTRAS）。 */
	public static function extras(): bool {
		return (bool) apply_filters( 'pchq_m10_extras', '1' === (string) get_option( self::EXTRAS, '0' ) );
	}

	// ---------- 表结构 ----------

	public static function maybe_upgrade(): void {
		if ( ! self::ready() ) {
			self::install();
		}
	}

	/** 建表、补列。没成功就不写版本号，下次再试。 */
	public static function install(): bool {
		global $wpdb;
		$daily = Db::table( 'daily' );
		if ( ! Series_Db::has_table( $daily ) ) {
			return false;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$c       = $wpdb->get_charset_collate();
		$orders  = self::table();
		$refunds = Refunds::table();
		$spend   = self::spend_table();
		$left    = self::abandoned_table();
		dbDelta(
			"CREATE TABLE {$orders} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL DEFAULT 0,
  store_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_number varchar(40) NOT NULL DEFAULT '',
  series_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT '',
  paid tinyint(1) unsigned NOT NULL DEFAULT 0,
  is_test tinyint(1) unsigned NOT NULL DEFAULT 0,
  currency varchar(8) NOT NULL DEFAULT '',
  total_cents bigint(20) NOT NULL DEFAULT 0,
  refunded_cents bigint(20) NOT NULL DEFAULT 0,
  refund_state varchar(8) NOT NULL DEFAULT 'none',
  refund_sync tinyint(1) unsigned NOT NULL DEFAULT 0,
  refund_error varchar(190) NOT NULL DEFAULT '',
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  paid_at int(10) unsigned NOT NULL DEFAULT 0,
  order_day varchar(10) NOT NULL DEFAULT '',
  email_hash varchar(64) NOT NULL DEFAULT '',
  utm_source varchar(120) NOT NULL DEFAULT '',
  ad_id varchar(64) NOT NULL DEFAULT '',
  customer_id varchar(16) NOT NULL DEFAULT '',
  reported_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY site_order (site_id,store_order_id),
  KEY paid_at (paid_at),
  KEY series_paid (series_id,paid_at),
  KEY customer (customer_id)
) {$c};
CREATE TABLE {$refunds} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL DEFAULT 0,
  store_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount_cents bigint(20) NOT NULL DEFAULT 0,
  refunded_on varchar(10) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  voided_at int(10) unsigned NOT NULL DEFAULT 0,
  voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY site_order (site_id,store_order_id)
) {$c};
CREATE TABLE {$spend} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  spend_cents bigint(20) NOT NULL DEFAULT 0,
  source varchar(16) NOT NULL DEFAULT 'manual',
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day)
) {$c};
CREATE TABLE {$left} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  orders int(10) unsigned NOT NULL DEFAULT 0,
  abandoned int(10) unsigned NOT NULL DEFAULT 0,
  abandoned_open int(10) unsigned NOT NULL DEFAULT 0,
  recall_emailed int(10) unsigned NOT NULL DEFAULT 0,
  recall_recovered int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day)
) {$c};"
		);
		// 从第 1 版升上来：dbDelta 会补列和索引；这里再保底补一次（已有就不动）。
		Series_Db::add_columns( $orders, array( 'customer_id' => "varchar(16) NOT NULL DEFAULT ''" ) );
		Series_Db::add_index( $orders, 'customer', 'customer_id' );
		$columns = array(
			'gross_cents'    => 'bigint(20) NOT NULL DEFAULT 0',
			'refunded_cents' => 'bigint(20) NOT NULL DEFAULT 0',
		);
		Series_Db::add_columns( $daily, $columns );
		$have = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$daily}", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$order_columns = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$orders}", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! Series_Db::has_table( $orders ) || ! Series_Db::has_table( $refunds ) || ! Series_Db::has_table( $spend ) || ! Series_Db::has_table( $left ) || array_diff( array_keys( $columns ), $have ) || ! in_array( 'customer_id', $order_columns, true ) ) {
			return false;
		}
		update_option( self::OPTION, self::VERSION, true );
		return true;
	}

	// ---------- 接收上报 ----------

	public static function routes(): void {
		register_rest_route(
			Register::NS,
			'/orders/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_report' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
	}

	/**
	 * 整理店铺报上来的一条订单（纯函数）。订单号或金额不对时返回 null。
	 */
	public static function clean_order( $o ): ?array {
		if ( ! is_array( $o ) ) {
			return null;
		}
		$id    = $o['store_order_id'] ?? 0;
		$total = $o['total_cents'] ?? null;
		if ( ! is_int( $id ) || $id < 1 || ! is_int( $total ) || $total < 0 ) {
			return null;
		}
		$day  = (string) ( $o['order_day'] ?? '' );
		$hash = strtolower( (string) ( $o['email_hash'] ?? '' ) );
		$utm  = trim( strip_tags( (string) ( $o['utm_source'] ?? '' ) ) );
		return array(
			'store_order_id' => $id,
			'order_number'   => substr( (string) preg_replace( '/[^A-Za-z0-9#_-]/', '', (string) ( $o['order_number'] ?? '' ) ), 0, 40 ),
			'status'         => substr( strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $o['status'] ?? '' ) ) ), 0, 20 ),
			'paid'           => empty( $o['paid'] ) ? 0 : 1,
			'is_test'        => empty( $o['is_test'] ) ? 0 : 1,
			'currency'       => substr( strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) ( $o['currency'] ?? '' ) ) ), 0, 8 ),
			'total_cents'    => $total,
			'created_at'     => max( 0, (int) ( $o['created_at'] ?? 0 ) ),
			'paid_at'        => max( 0, (int) ( $o['paid_at'] ?? 0 ) ),
			'order_day'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ? $day : '',
			'series_id'      => max( 0, (int) ( $o['series_id'] ?? 0 ) ),
			'email_hash'     => preg_match( '/^[a-f0-9]{64}$/D', $hash ) ? $hash : '',
			'utm_source'     => function_exists( 'mb_substr' ) ? mb_substr( $utm, 0, 120 ) : substr( $utm, 0, 120 ),
			'ad_id'          => substr( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) ( $o['ad_id'] ?? '' ) ), 0, 64 ),
			'customer_id'    => self::clean_customer_id( $o['customer_id'] ?? '' ),
		);
	}

	/** 客户编号（纯函数）：店铺算出的 “C” 加 7 位字母数字；不是这个样子的一律当作没有。 */
	public static function clean_customer_id( $id ): string {
		$id = is_string( $id ) ? strtoupper( trim( $id ) ) : '';
		return 1 === preg_match( '/^C[0-9A-Z]{7}$/D', $id ) ? $id : '';
	}

	/**
	 * @return array|\WP_Error { accepted: 订单号[], rejected: [{store_order_id, code}] }
	 */
	public static function handle_report( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$p       = $request->get_json_params();
		$list    = is_array( $p ) && is_array( $p['orders'] ?? null ) ? array_values( $p['orders'] ) : null;
		if ( $site_id < 1 || null === $list || ! $list || count( $list ) > self::MAX_BATCH ) {
			return new \WP_Error( 'pchq_orders_bad', 'Send between 1 and 50 orders per request.', array( 'status' => 400 ) );
		}
		if ( ! self::ready() ) {
			return new \WP_Error( 'pchq_orders_db', 'The order table is not ready yet.', array( 'status' => 503 ) );
		}
		$site_series = Series::site( $site_id )['series_id'];
		$known       = Series::all( true );
		$accepted    = array();
		$rejected    = array();
		foreach ( $list as $raw ) {
			$o = self::clean_order( $raw );
			if ( null === $o ) {
				$rejected[] = array( 'store_order_id' => is_array( $raw ) && is_int( $raw['store_order_id'] ?? null ) ? $raw['store_order_id'] : 0, 'code' => 'invalid' );
				continue;
			}
			// 订单带的系列是下单时店铺所属的系列；总后台不认识这个系列时用店铺现在的系列。
			if ( ! isset( $known[ $o['series_id'] ] ) ) {
				$o['series_id'] = $site_series;
			}
			if ( self::upsert( $site_id, $o ) ) {
				$accepted[] = $o['store_order_id'];
			} else {
				$rejected[] = array( 'store_order_id' => $o['store_order_id'], 'code' => 'storage' );
			}
		}
		if ( $accepted ) {
			Refunds::after_report( $site_id, $accepted );
		}
		return array( 'accepted' => $accepted, 'rejected' => $rejected );
	}

	/** 按（店铺，订单号）写入或覆盖。退款相关的列由 Refunds 维护，这里不动。 */
	public static function upsert( int $site_id, array $o ): bool {
		global $wpdb;
		$t   = self::table();
		$now = time();
		$sql = $wpdb->prepare(
			"INSERT INTO {$t} (site_id, store_order_id, order_number, series_id, status, paid, is_test, currency, total_cents, created_at, paid_at, order_day, email_hash, utm_source, ad_id, customer_id, reported_at, updated_at)
			VALUES (%d, %d, %s, %d, %s, %d, %d, %s, %d, %d, %d, %s, %s, %s, %s, %s, %d, %d)
			ON DUPLICATE KEY UPDATE order_number = VALUES(order_number), series_id = VALUES(series_id), status = VALUES(status), paid = VALUES(paid), is_test = VALUES(is_test), currency = VALUES(currency), total_cents = VALUES(total_cents), created_at = VALUES(created_at), paid_at = VALUES(paid_at), order_day = VALUES(order_day), email_hash = VALUES(email_hash), utm_source = VALUES(utm_source), ad_id = VALUES(ad_id), customer_id = VALUES(customer_id), reported_at = VALUES(reported_at), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$site_id,
			$o['store_order_id'],
			$o['order_number'],
			$o['series_id'],
			$o['status'],
			$o['paid'],
			$o['is_test'],
			$o['currency'],
			$o['total_cents'],
			$o['created_at'],
			$o['paid_at'],
			$o['order_day'],
			$o['email_hash'],
			$o['utm_source'],
			$o['ad_id'],
			$o['customer_id'],
			$now,
			$now
		);
		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ---------- 读取 ----------

	private static function row( ?array $r ): ?array {
		if ( ! $r ) {
			return null;
		}
		foreach ( array( 'id', 'site_id', 'store_order_id', 'series_id', 'paid', 'is_test', 'total_cents', 'refunded_cents', 'refund_sync', 'created_at', 'paid_at', 'reported_at', 'updated_at' ) as $k ) {
			$r[ $k ] = (int) $r[ $k ];
		}
		return $r;
	}

	public static function get( int $site_id, int $store_order_id ): ?array {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		$t = self::table();
		return self::row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE site_id = %d AND store_order_id = %d", $site_id, $store_order_id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function by_id( int $id ): ?array {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		$t = self::table();
		return self::row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 订单列表。筛选项：site_id、series_id、state（paid / unpaid / refunded / test / 空 = 全部）、q（订单号）、from / to（付款时间，UTC 秒）。
	 *
	 * @return array{rows:array,total:int}
	 */
	public static function search( array $f, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$t     = self::table();
		$where = array( '1=1' );
		if ( ! empty( $f['site_id'] ) ) {
			$where[] = $wpdb->prepare( 'site_id = %d', (int) $f['site_id'] );
		}
		if ( ! empty( $f['series_id'] ) ) {
			$where[] = $wpdb->prepare( 'series_id = %d', (int) $f['series_id'] );
		}
		switch ( (string) ( $f['state'] ?? '' ) ) {
			case 'paid':
				$where[] = 'paid = 1 AND is_test = 0';
				break;
			case 'unpaid':
				$where[] = 'paid = 0';
				break;
			case 'refunded':
				$where[] = 'refunded_cents > 0';
				break;
			case 'test':
				$where[] = 'is_test = 1';
				break;
		}
		// 开关关着：照线上（11-10）的写法，一个字不改。
		if ( ! self::extras() ) {
			$q = (string) preg_replace( '/[^A-Za-z0-9#_-]/', '', (string) ( $f['q'] ?? '' ) );
			if ( '' !== $q ) {
				$where[] = $wpdb->prepare( '(order_number = %s OR store_order_id = %d)', $q, (int) ltrim( $q, '#' ) );
			}
		}
		// 开关打开：顾客和平台所有者看到的号（新订单是支付方的 hang-数字，老订单是数字），或者店铺内部的订单编号，或者客户编号。
		$q = self::extras() ? self::clean_query( (string) ( $f['q'] ?? '' ) ) : '';
		if ( '' !== $q ) {
			$customer = self::extras() ? self::clean_customer_id( $q ) : '';
			$where[]  = '' !== $customer
				? $wpdb->prepare( '(order_number = %s OR store_order_id = %d OR customer_id = %s)', $q, ctype_digit( $q ) ? (int) $q : 0, $customer )
				: $wpdb->prepare( '(order_number = %s OR store_order_id = %d)', $q, ctype_digit( $q ) ? (int) $q : 0 );
		}
		if ( ! empty( $f['from'] ) ) {
			$where[] = $wpdb->prepare( 'paid_at >= %d', (int) $f['from'] );
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = $wpdb->prepare( 'paid_at <= %d', (int) $f['to'] );
		}
		$sql      = implode( ' AND ', $where );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql} ORDER BY GREATEST(paid_at, created_at) DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'rows' => array_values( array_filter( array_map( array( self::class, 'row' ), $rows ) ) ), 'total' => $total );
	}

	/** 整理搜索用的订单号（纯函数）：去掉 # 和空白，hang- 号不分大小写。 */
	public static function clean_query( string $q ): string {
		$q = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $q );
		return 0 === stripos( $q, 'hang-' ) ? strtolower( $q ) : $q;
	}

	/**
	 * 每家店在一段付款时间内的订单数和金额（已付款、非测试单）。$from / $to 为 0 表示不限。
	 *
	 * @return array<int,array{orders:int,gross_cents:int,refunded_cents:int,currency:string}>
	 */
	public static function site_totals( int $from = 0, int $to = 0 ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t     = self::table();
		$where = 'paid = 1 AND is_test = 0';
		if ( $from > 0 ) {
			$where .= $wpdb->prepare( ' AND paid_at >= %d', $from );
		}
		if ( $to > 0 ) {
			$where .= $wpdb->prepare( ' AND paid_at <= %d', $to );
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT site_id, COUNT(*) AS orders, COALESCE(SUM(total_cents),0) AS gross, COALESCE(SUM(refunded_cents),0) AS refunded, MAX(currency) AS currency FROM {$t} WHERE {$where} GROUP BY site_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $r['site_id'] ] = array(
				'orders'         => (int) $r['orders'],
				'gross_cents'    => (int) $r['gross'],
				'refunded_cents' => (int) $r['refunded'],
				'currency'       => (string) $r['currency'],
			);
		}
		return $out;
	}

	/**
	 * 核对一家店：总后台的合计和店铺自己的合计是否一致。
	 *
	 * @return array{match:bool,hq:array,store:array}|\WP_Error
	 */
	public static function verify_site( int $site_id ) {
		$store = Client::request( $site_id, 'GET', '/peptide/v1/hq/orders/digest', array(), null, 120 );
		if ( is_wp_error( $store ) ) {
			return $store;
		}
		$mine = self::site_totals()[ $site_id ] ?? array( 'orders' => 0, 'gross_cents' => 0, 'refunded_cents' => 0, 'currency' => '' );
		$hq   = array( 'count' => $mine['orders'], 'total_cents' => $mine['gross_cents'], 'refunded_cents' => $mine['refunded_cents'] );
		$shop = array( 'count' => (int) ( $store['count'] ?? -1 ), 'total_cents' => (int) ( $store['total_cents'] ?? -1 ), 'refunded_cents' => (int) ( $store['refunded_cents'] ?? -1 ) );
		return array( 'match' => $hq === $shop, 'hq' => $hq, 'store' => $shop );
	}

	// ---------- 巡检：订单上报是否落后 ----------

	public static function alert_types( $types ): array {
		return ( is_array( $types ) ? $types : array() ) + array( 'order_report_lag' => '订单上报落后' );
	}

	/** 近两天（店铺日期）里，店铺每日汇总的已付款订单数比已经报上来的多几笔。 */
	public static function missing_reports( int $site_id ): int {
		global $wpdb;
		$d       = Db::table( 'daily' );
		$o       = self::table();
		$missing = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, orders FROM {$d} WHERE site_id = %d ORDER BY day DESC LIMIT 2", $site_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$have     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$o} WHERE site_id = %d AND order_day = %s AND paid = 1", $site_id, (string) $r['day'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$missing += max( 0, (int) $r['orders'] - $have );
		}
		return $missing;
	}

	/**
	 * 落后多久了（纯函数）。$since 是第一次发现落后的时间（0 = 之前不落后）。
	 *
	 * @return array{since:int,late:bool} 新的起始时间，以及是否已经超过 2 小时
	 */
	public static function lag_state( int $missing, int $since, int $now ): array {
		if ( $missing <= 0 ) {
			return array( 'since' => 0, 'late' => false );
		}
		$since = $since > 0 ? $since : $now;
		return array( 'since' => $since, 'late' => $now - $since > 7200 );
	}

	/**
	 * 巡检项 order_report_lag：店铺有已付款订单超过 2 小时还没报到总后台。
	 * 只检查声明了 orders.report 的店；不适用时不报。巡检里不向店铺发请求。
	 */
	public static function monitor_check( $checks, $site_id, $status = array(), $now = 0 ): array {
		$checks  = is_array( $checks ) ? $checks : array();
		$site_id = (int) $site_id;
		$now     = (int) $now > 0 ? (int) $now : time();
		if ( ! self::ready() || ! is_callable( array( Client::class, 'supports' ) ) || ! Client::supports( $site_id, 'orders.report' ) ) {
			return $checks;
		}
		$missing = self::missing_reports( $site_id );
		$all     = get_option( 'pchq_order_lag_since', array() );
		$all     = is_array( $all ) ? $all : array();
		$state   = self::lag_state( $missing, (int) ( $all[ $site_id ] ?? 0 ), $now );
		if ( $state['since'] !== (int) ( $all[ $site_id ] ?? 0 ) ) {
			if ( $state['since'] > 0 ) {
				$all[ $site_id ] = $state['since'];
			} else {
				unset( $all[ $site_id ] );
			}
			update_option( 'pchq_order_lag_since', $all, false );
		}
		$checks[] = array(
			'type'    => 'order_report_lag',
			'ok'      => ! $state['late'],
			'message' => $state['late'] ? sprintf( '这家店有 %d 笔已付款订单超过 2 小时还没报到总后台，跨店报表会偏低。可以在店铺运行 wp peptide orders backfill 补报。', $missing ) : '订单上报正常。',
		);
		return $checks;
	}

	// ---------- 顾客（客户编号） ----------

	/**
	 * 一段付款时间内每家店有哪些顾客（已付款、非测试单）。顾客用店铺报上来的客户编号认；
	 * 没有编号的（店铺程序还没更新）退回用邮箱哈希。两样都没有的订单不计入顾客数。
	 *
	 * @return array<int,string[]> 店铺 ID => 顾客标识（不重复）
	 */
	public static function customers_by_site( int $from = 0, int $to = 0 ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t     = self::table();
		$where = "paid = 1 AND is_test = 0 AND (customer_id <> '' OR email_hash <> '')";
		if ( $from > 0 ) {
			$where .= $wpdb->prepare( ' AND paid_at >= %d', $from );
		}
		if ( $to > 0 ) {
			$where .= $wpdb->prepare( ' AND paid_at <= %d', $to );
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT DISTINCT site_id, customer_id, email_hash FROM {$t} WHERE {$where}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$key = '' !== (string) $r['customer_id'] ? (string) $r['customer_id'] : 'e:' . $r['email_hash'];
			$out[ (int) $r['site_id'] ][ $key ] = $key;
		}
		return array_map( 'array_values', $out );
	}

	// ---------- 弃单数（来自店铺每日汇总） ----------

	/**
	 * 存一家店若干天的弃单数。$days 是店铺 /hq/summary 返回的 days：每天一行，带 day 和
	 * abandoned / abandoned_open / recall_emailed / recall_recovered（指标模块 Metrics::daily 的同名项，按弃单创建那天）。
	 * 没带 abandoned 的行（店铺程序还没更新，不能当成 0）跳过。同一天覆盖。返回存了几天。
	 * 同一行里的 orders（店铺自己数的当天订单数）一起存下来，算弃单率时做分母：两个数来自同一家店的同一天。
	 */
	public static function store_abandoned( $site_id, $days ): int {
		global $wpdb;
		$site_id = (int) $site_id;
		if ( $site_id < 1 || ! is_array( $days ) || ! self::ready() ) {
			return 0;
		}
		$n = 0;
		foreach ( $days as $d ) {
			if ( ! is_array( $d ) || ! array_key_exists( 'abandoned', $d ) || ! self::valid_day( (string) ( $d['day'] ?? '' ) ) ) {
				continue;
			}
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::abandoned_table(),
				array(
					'site_id'          => $site_id,
					'day'              => (string) $d['day'],
					'orders'           => max( 0, (int) ( $d['orders'] ?? 0 ) ),
					'abandoned'        => max( 0, (int) $d['abandoned'] ),
					'abandoned_open'   => max( 0, (int) ( $d['abandoned_open'] ?? 0 ) ),
					'recall_emailed'   => max( 0, (int) ( $d['recall_emailed'] ?? 0 ) ),
					'recall_recovered' => max( 0, (int) ( $d['recall_recovered'] ?? 0 ) ),
					'updated_at'       => time(),
				)
			);
			++$n;
		}
		return $n;
	}

	/**
	 * 轮询拿到店铺的每日汇总时顺手把弃单数存下来。轮询本身（Monitor）不归本模块：
	 * 它如果触发了 pchq_summary_days 动作就走那条路；这里是保底，直接看 /hq/summary 的回应。
	 */
	public static function watch_summary( $response, $args = array(), $url = '' ) {
		if ( ! is_array( $response ) || ! is_string( $url ) || false === strpos( $url, 'hq%2Fsummary' ) ) {
			return $response;
		}
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( '/peptide/v1/hq/summary' !== ( $query['rest_route'] ?? '' ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! is_array( $data ) || ! is_array( $data['days'] ?? null ) || '' === $host ) {
			return $response;
		}
		// 只有一家店用这个地址时才认；两家店登记了同一个地址（测试环境）就分不清是谁的，宁可不存。
		$found = array();
		foreach ( Sites::all() as $id => $site ) {
			if ( strtolower( (string) wp_parse_url( Site_Urls::management( $site ), PHP_URL_HOST ) ) === $host ) {
				$found[] = (int) $id;
			}
		}
		if ( 1 === count( $found ) ) {
			self::store_abandoned( $found[0], $data['days'] );
		}
		return $response;
	}

	/**
	 * 这段日期（店铺时区的日期，含两端）里各店的弃单数，和这些天店铺自己数的订单数。
	 * 没有任何一天带弃单数的店不在结果里（“还不支持”和“0 个弃单”要分开）。
	 *
	 * @return array<int,array{abandoned:int,orders:int}>
	 */
	public static function abandoned_by_site( string $from_day, string $to_day ): array {
		global $wpdb;
		if ( ! self::ready() || ! self::valid_day( $from_day ) || ! self::valid_day( $to_day ) ) {
			return array();
		}
		$t   = self::abandoned_table();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT site_id, SUM(abandoned) AS abandoned, SUM(orders) AS orders FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY site_id", $from_day, $to_day ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $r['site_id'] ] = array( 'abandoned' => (int) $r['abandoned'], 'orders' => (int) $r['orders'] );
		}
		return $out;
	}

	// ---------- 广告花费（先手填） ----------

	/** 这一天这家店的花费是谁写的：manual / csv / api；没有这一行为空。 */
	public static function spend_source( int $site_id, string $day ): string {
		global $wpdb;
		if ( ! self::ready() || $site_id < 1 || ! self::valid_day( $day ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM ' . self::spend_table() . ' WHERE site_id = %d AND day = %s', $site_id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 记一家店某一天的广告花费（覆盖）。
	 *
	 * 2026-11-13 修（审查发现）：
	 * - 店铺推送的表格花费（csv）不覆盖手填的（manual）——“以手填为准还是以表格为准”店主还没定，先保住手填；
	 * - 表格推送的 0 记成 0，不删行；只有手填时清空才删行（管理员明确的操作）。
	 */
	public static function set_spend( int $site_id, string $day, int $cents, int $user_id = 0, string $source = 'manual' ): bool {
		global $wpdb;
		if ( ! self::ready() || $site_id < 1 || ! self::valid_day( $day ) || $cents < 0 ) {
			return false;
		}
		$t = self::spend_table();
		if ( 'csv' === $source && 'manual' === self::spend_source( $site_id, $day ) ) {
			return false;
		}
		if ( 0 === $cents && 'manual' === $source ) {
			return false !== $wpdb->delete( $t, array( 'site_id' => $site_id, 'day' => $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return false !== $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'site_id'     => $site_id,
				'day'         => $day,
				'spend_cents' => $cents,
				'source'      => in_array( $source, array( 'api', 'csv' ), true ) ? $source : 'manual', // csv = 店铺导入 Meta 报表后推送（CR 素材归因）。
				'updated_at'  => time(),
				'updated_by'  => $user_id,
			)
		);
	}

	/** @return array<int,int> 店铺 ID => 这段日期（含两端）的花费合计（美分） */
	public static function spend_by_site( string $from_day, string $to_day ): array {
		global $wpdb;
		if ( ! self::ready() || ! self::valid_day( $from_day ) || ! self::valid_day( $to_day ) ) {
			return array();
		}
		$t   = self::spend_table();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT site_id, SUM(spend_cents) AS spend FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY site_id", $from_day, $to_day ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $r['site_id'] ] = (int) $r['spend'];
		}
		return $out;
	}

	/** 是不是真实存在的 YYYY-MM-DD 日期（纯函数）。 */
	public static function valid_day( string $day ): bool {
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** 金额输入（"12.5"、"1,234.56"）转美分（纯函数）；不是有效金额时返回 -1。 */
	public static function parse_money( string $input ): int {
		$s = str_replace( array( ',', '$', ' ' ), '', trim( $input ) );
		if ( '' === $s || ! preg_match( '/^\d{1,9}(\.\d{1,2})?$/D', $s ) ) {
			return -1;
		}
		$parts = explode( '.', $s );
		return (int) $parts[0] * 100 + (int) str_pad( $parts[1] ?? '0', 2, '0' );
	}

	/** 美分显示成金额。 */
	public static function money( int $cents, string $currency = 'USD' ): string {
		$sign = $cents < 0 ? '-' : '';
		return $sign . ( 'USD' === $currency || '' === $currency ? '$' : $currency . ' ' ) . number_format( abs( $cents ) / 100, 2 );
	}

	// ---------- 三级汇总（纯函数） ----------

	/**
	 * 一组数字加上净额、ROAS、客单价、转化率、弃单率。
	 *
	 * 弃单率 = 弃单数 ÷（弃单数 + 订单数）：留下联系方式开始结账的人里，没付款的占多少。
	 * 分母里的订单数（$abandon_orders）是带了弃单数的那些店、那些天，店铺每日汇总里自己数的订单数——
	 * 和弃单数同一个来源、同一批日期，所以有的店还没带弃单数、或者日期跨过了功能上线那天，比率也不会被冲淡。
	 * null 表示这一级没有任何弃单数据，不算弃单率。
	 * 指标口径文档（docs/metrics.md）只定义了弃单数，没有定义“弃单率”，这是 F3 先用的保守算法；口径定了再改这一处。
	 */
	public static function metrics( int $orders, int $gross_cents, int $refunded_cents, int $spend_cents, int $visitors, int $abandoned = 0, int $customers = 0, ?int $abandon_orders = null ): array {
		$net = $gross_cents - $refunded_cents;
		return array(
			'orders'         => $orders,
			'gross_cents'    => $gross_cents,
			'refunded_cents' => $refunded_cents,
			'net_cents'      => $net,
			'spend_cents'    => $spend_cents,
			'visitors'       => $visitors,
			'abandoned'      => $abandoned,
			'abandon_orders' => $abandon_orders,
			'customers'      => $customers,
			'roas'           => $spend_cents > 0 ? round( $net / $spend_cents, 2 ) : null,
			'aov_cents'      => $orders > 0 ? (int) round( $net / $orders ) : null,
			'cvr'            => $visitors > 0 ? round( $orders / $visitors * 100, 2 ) : null,
			'abandon_rate'   => null !== $abandon_orders && $abandoned + $abandon_orders > 0 ? round( $abandoned / ( $abandoned + $abandon_orders ) * 100, 2 ) : null,
		);
	}

	/**
	 * 系列 → 投手 → 店铺 三级汇总。订单、金额、花费、访客、弃单数每一级都是下一级相加，所以三级合计一定相等。
	 * 顾客数例外：同一位顾客在几家店都买过，上一级只算一次（按客户编号去重），所以不是下一级相加。
	 *
	 * @param array[] $stores 每家店一行：site_id、name、series_id、series_name、buyer_name、orders、gross_cents、refunded_cents、spend_cents、visitors，
	 *                        可选 abandoned（弃单数）、abandon_orders（有弃单数的那些天店铺自己数的订单数；没有弃单数据时不带或为 null）
	 *                        和 customer_keys（这家店的顾客标识列表）
	 * @return array{total:array,series:array}
	 */
	public static function rollup( array $stores ): array {
		$sum  = static function ( array $rows, array $customers ): array {
			$t    = array( 0, 0, 0, 0, 0, 0 );
			$base = null;
			foreach ( $rows as $r ) {
				$t[0] += (int) $r['orders'];
				$t[1] += (int) $r['gross_cents'];
				$t[2] += (int) $r['refunded_cents'];
				$t[3] += (int) $r['spend_cents'];
				$t[4] += (int) $r['visitors'];
				$t[5] += (int) ( $r['abandoned'] ?? 0 );
				if ( isset( $r['abandon_orders'] ) ) {
					$base = (int) $base + (int) $r['abandon_orders'];
				}
			}
			return self::metrics( $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], count( $customers ), $base );
		};
		$tree = array();
		foreach ( $stores as $s ) {
			$sid   = (int) $s['series_id'];
			$buyer = '' !== trim( (string) $s['buyer_name'] ) ? trim( (string) $s['buyer_name'] ) : '';
			$keys  = array_fill_keys( array_map( 'strval', (array) ( $s['customer_keys'] ?? array() ) ), true );
			$tree[ $sid ]['name']                            = (string) ( $s['series_name'] ?? '' );
			$tree[ $sid ]['buyers'][ $buyer ]['customers'] = ( $tree[ $sid ]['buyers'][ $buyer ]['customers'] ?? array() ) + $keys;
			$tree[ $sid ]['buyers'][ $buyer ]['stores'][]  = array_merge(
				array( 'site_id' => (int) $s['site_id'], 'name' => (string) $s['name'] ),
				self::metrics( (int) $s['orders'], (int) $s['gross_cents'], (int) $s['refunded_cents'], (int) $s['spend_cents'], (int) $s['visitors'], (int) ( $s['abandoned'] ?? 0 ), count( $keys ), isset( $s['abandon_orders'] ) ? (int) $s['abandon_orders'] : null )
			);
		}
		ksort( $tree );
		$series_rows = array();
		$everyone    = array();
		foreach ( $tree as $sid => $series ) {
			ksort( $series['buyers'] );
			$buyer_rows = array();
			$in_series  = array();
			foreach ( $series['buyers'] as $buyer => $b ) {
				$buyer_rows[] = array( 'buyer_name' => (string) $buyer, 'metrics' => $sum( $b['stores'], $b['customers'] ), 'stores' => $b['stores'] );
				$in_series   += $b['customers'];
			}
			$everyone     += $in_series;
			$series_rows[] = array(
				'series_id' => (int) $sid,
				'name'      => $series['name'],
				'metrics'   => $sum( array_column( $buyer_rows, 'metrics' ), $in_series ),
				'buyers'    => $buyer_rows,
			);
		}
		return array( 'total' => $sum( array_column( $series_rows, 'metrics' ), $everyone ), 'series' => $series_rows );
	}

	/**
	 * 报表数据：一段日期（总后台时区，含两端）内每家店的订单、销售额、花费、访客、弃单数、顾客，再做三级汇总。
	 *
	 * @return array{total:array,series:array,from:int,to:int}
	 */
	public static function report( string $from_day, string $to_day ): array {
		$tz    = wp_timezone();
		$from  = ( new \DateTimeImmutable( $from_day . ' 00:00:00', $tz ) )->getTimestamp();
		$to    = ( new \DateTimeImmutable( $to_day . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp() - 1;
		$sales = self::site_totals( $from, $to );
		$spend = self::spend_by_site( $from_day, $to_day );
		$visit = Stats::per_site( $from_day, $to_day );
		$left  = self::abandoned_by_site( $from_day, $to_day );
		$who   = self::customers_by_site( $from, $to );
		$map   = Series::site_map();
		$names = Series::all( true );
		$rows  = array();
		foreach ( Sites::all() as $id => $site ) {
			$sid    = $map[ $id ]['series_id'] ?? 0;
			$rows[] = array(
				'site_id'        => (int) $id,
				'name'           => (string) $site['name'],
				'series_id'      => $sid,
				'series_name'    => (string) ( $names[ $sid ]['name'] ?? '未归入系列' ),
				'buyer_name'     => (string) ( $map[ $id ]['buyer_name'] ?? '' ),
				'orders'         => $sales[ $id ]['orders'] ?? 0,
				'gross_cents'    => $sales[ $id ]['gross_cents'] ?? 0,
				'refunded_cents' => $sales[ $id ]['refunded_cents'] ?? 0,
				'spend_cents'    => $spend[ $id ] ?? 0,
				'visitors'       => $visit[ $id ]['visitors'] ?? 0,
				'abandoned'      => $left[ $id ]['abandoned'] ?? 0,
				'abandon_orders' => isset( $left[ $id ] ) ? $left[ $id ]['orders'] : null,
				'customer_keys'  => $who[ $id ] ?? array(),
			);
		}
		return self::rollup( $rows ) + array( 'from' => $from, 'to' => $to );
	}
}
