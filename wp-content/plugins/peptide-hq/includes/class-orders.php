<?php
/**
 * 订单汇总（HQ-13、OR-03 的数据底座）：各店把订单摘要报到总后台，供跨店报表和退款标记使用。
 *
 * - 表（模块自己的升级，版本选项 pchq_m10_db，可重复执行）：pchq_orders、pchq_refunds、pchq_ad_spend；
 *   pchq_daily 加 gross_cents、refunded_cents 两列。
 * - 店铺 → 总后台 POST /peptide-hq/v1/orders/report：每批最多 50 条，按（店铺，订单号）覆盖写入，重复上报结果不变。
 *   签名校验用邮件中心同一个函数（店铺连接密钥 + X-PC-HQ-Site）。
 * - 报表口径：订单数 = 已付款且不是测试单；销售额 = 订单金额 − 已标记退款；时间按付款时间。
 *   三级（系列 → 投手 → 店铺）都由同一批店铺行往上加，所以合计相等。
 * - 广告花费先手填（pchq_ad_spend，每店每天一行）。
 * - 顾客的姓名、邮箱、电话、地址不进总后台，只有邮箱的哈希。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Orders {

	public const VERSION   = '1';
	public const OPTION    = 'pchq_m10_db';
	public const MAX_BATCH = 50;

	public static function register_hooks(): void {
		self::maybe_upgrade();
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
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

	public static function ready(): bool {
		return self::VERSION === get_option( self::OPTION );
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
  reported_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY site_order (site_id,store_order_id),
  KEY paid_at (paid_at),
  KEY series_paid (series_id,paid_at)
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
) {$c};"
		);
		$columns = array(
			'gross_cents'    => 'bigint(20) NOT NULL DEFAULT 0',
			'refunded_cents' => 'bigint(20) NOT NULL DEFAULT 0',
		);
		Series_Db::add_columns( $daily, $columns );
		$have = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$daily}", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! Series_Db::has_table( $orders ) || ! Series_Db::has_table( $refunds ) || ! Series_Db::has_table( $spend ) || array_diff( array_keys( $columns ), $have ) ) {
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
		);
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
			"INSERT INTO {$t} (site_id, store_order_id, order_number, series_id, status, paid, is_test, currency, total_cents, created_at, paid_at, order_day, email_hash, utm_source, ad_id, reported_at, updated_at)
			VALUES (%d, %d, %s, %d, %s, %d, %d, %s, %d, %d, %d, %s, %s, %s, %s, %d, %d)
			ON DUPLICATE KEY UPDATE order_number = VALUES(order_number), series_id = VALUES(series_id), status = VALUES(status), paid = VALUES(paid), is_test = VALUES(is_test), currency = VALUES(currency), total_cents = VALUES(total_cents), created_at = VALUES(created_at), paid_at = VALUES(paid_at), order_day = VALUES(order_day), email_hash = VALUES(email_hash), utm_source = VALUES(utm_source), ad_id = VALUES(ad_id), reported_at = VALUES(reported_at), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		$q = (string) preg_replace( '/[^A-Za-z0-9#_-]/', '', (string) ( $f['q'] ?? '' ) );
		if ( '' !== $q ) {
			$where[] = $wpdb->prepare( '(order_number = %s OR store_order_id = %d)', $q, (int) ltrim( $q, '#' ) );
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

	// ---------- 广告花费（先手填） ----------

	/** 记一家店某一天的广告花费（覆盖）。金额为 0 时删掉这一行。 */
	public static function set_spend( int $site_id, string $day, int $cents, int $user_id = 0, string $source = 'manual' ): bool {
		global $wpdb;
		if ( ! self::ready() || $site_id < 1 || ! self::valid_day( $day ) || $cents < 0 ) {
			return false;
		}
		$t = self::spend_table();
		if ( 0 === $cents ) {
			return false !== $wpdb->delete( $t, array( 'site_id' => $site_id, 'day' => $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return false !== $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'site_id'     => $site_id,
				'day'         => $day,
				'spend_cents' => $cents,
				'source'      => 'api' === $source ? 'api' : 'manual',
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
	 * 一组数字加上净额、ROAS、客单价、转化率。
	 */
	public static function metrics( int $orders, int $gross_cents, int $refunded_cents, int $spend_cents, int $visitors ): array {
		$net = $gross_cents - $refunded_cents;
		return array(
			'orders'         => $orders,
			'gross_cents'    => $gross_cents,
			'refunded_cents' => $refunded_cents,
			'net_cents'      => $net,
			'spend_cents'    => $spend_cents,
			'visitors'       => $visitors,
			'roas'           => $spend_cents > 0 ? round( $net / $spend_cents, 2 ) : null,
			'aov_cents'      => $orders > 0 ? (int) round( $net / $orders ) : null,
			'cvr'            => $visitors > 0 ? round( $orders / $visitors * 100, 2 ) : null,
		);
	}

	/**
	 * 系列 → 投手 → 店铺 三级汇总。每一级的数字都是下一级相加，所以三级合计一定相等。
	 *
	 * @param array[] $stores 每家店一行：site_id、name、series_id、series_name、buyer_name、orders、gross_cents、refunded_cents、spend_cents、visitors
	 * @return array{total:array,series:array}
	 */
	public static function rollup( array $stores ): array {
		$sum  = static function ( array $rows ): array {
			$t = array( 0, 0, 0, 0, 0 );
			foreach ( $rows as $r ) {
				$t[0] += (int) $r['orders'];
				$t[1] += (int) $r['gross_cents'];
				$t[2] += (int) $r['refunded_cents'];
				$t[3] += (int) $r['spend_cents'];
				$t[4] += (int) $r['visitors'];
			}
			return self::metrics( $t[0], $t[1], $t[2], $t[3], $t[4] );
		};
		$tree = array();
		foreach ( $stores as $s ) {
			$sid   = (int) $s['series_id'];
			$buyer = '' !== trim( (string) $s['buyer_name'] ) ? trim( (string) $s['buyer_name'] ) : '';
			$tree[ $sid ]['name']                       = (string) ( $s['series_name'] ?? '' );
			$tree[ $sid ]['buyers'][ $buyer ]['stores'][] = array_merge(
				array( 'site_id' => (int) $s['site_id'], 'name' => (string) $s['name'] ),
				self::metrics( (int) $s['orders'], (int) $s['gross_cents'], (int) $s['refunded_cents'], (int) $s['spend_cents'], (int) $s['visitors'] )
			);
		}
		ksort( $tree );
		$series_rows = array();
		foreach ( $tree as $sid => $series ) {
			ksort( $series['buyers'] );
			$buyer_rows = array();
			foreach ( $series['buyers'] as $buyer => $b ) {
				$buyer_rows[] = array( 'buyer_name' => (string) $buyer, 'metrics' => $sum( $b['stores'] ), 'stores' => $b['stores'] );
			}
			$series_rows[] = array(
				'series_id' => (int) $sid,
				'name'      => $series['name'],
				'metrics'   => $sum( array_column( $buyer_rows, 'metrics' ) ),
				'buyers'    => $buyer_rows,
			);
		}
		return array( 'total' => $sum( array_column( $series_rows, 'metrics' ) ), 'series' => $series_rows );
	}

	/**
	 * 报表数据：一段日期（总后台时区，含两端）内每家店的订单、销售额、花费、访客，再做三级汇总。
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
			);
		}
		return self::rollup( $rows ) + array( 'from' => $from, 'to' => $to );
	}
}
