<?php
/**
 * FE-03“看过 / 已加购”：给前台商品弹窗用的公开只读接口（主题那一半是 F5 的 inc/product-activity.php）。
 *
 *   GET /wp-json/peptide/v1/product-activity?product_id=<商品 ID>
 *   200 { product_id, viewing, added, window: { viewing_minutes, added_hours }, generated_at, ttl }
 *
 * - viewing：最近 VIEWING_MINUTES 分钟里看过这件商品（商品页或商品弹窗，事件 product_view）的不同访客数；
 *   added：最近 ADDED_HOURS 小时里把它加进购物车（服务端事件 add_to_cart）的不同访客数。各规格都记在父商品上。
 *   add_to_cart 这个类型浏览器报不上来（接收接口的类型清单里没有它），只能由服务端在真正加购成功时记。
 * - 只有真实数字。访客的口径和 Data\Metrics 一样：员工和已识别的爬虫在写入时就不记；测试版留下的数据（is_test = 1）
 *   和访客行已经被清掉的不算。另外多排除一项：窗口内的访问上挂着测试单的访客（公开显示的数字宁可少算）。
 * - 低于店铺下限（选项 pc_product_activity_min，默认 5，主题用的是同一个）的数字一律给 0：
 *   公开接口不暴露比页面上会显示的更多的东西，也不会出现“此刻正好有 1 个人在看”这种能对上某一位顾客的数。
 * - 响应里只有上面这几个字段，没有任何访客资料。
 *
 * 开关：和主题同一个按店开关 pc_product_activity（值 on 才生效，默认关）。关着时接口一律 404，不查库。
 * 过滤器 peptide_core_product_activity_enabled 可以改写；pc_product_activity_available 告诉主题“这家店有这个接口并且追踪开着”。
 *
 * 轻：全店的数字一次算好（两条走 type_time 索引的聚合查询，外加一条取“挂着订单的访问”），放进一个瞬态缓存 TTL 秒，
 * 所以不管多少个商品弹窗、多少个不同的商品，每家店每分钟最多算一次。不存在或没发布的商品只多一次主键查询就返回 404。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

use PeptideCore\Api\Errors;
use PeptideCore\Data\Metrics;

defined( 'ABSPATH' ) || exit;

final class Product_Activity {

	/** “看过”的统计窗口（分钟，约定范围 1–60）。 */
	public const VIEWING_MINUTES = 10;

	/** “已加购”的统计窗口（小时，约定范围 1–168）。 */
	public const ADDED_HOURS = 24;

	/** 服务端缓存和响应头 max-age 的秒数。 */
	public const TTL = 60;

	/** 主题的两个选项（F5 定义，这里只读）。 */
	public const SWITCH_OPTION = 'pc_product_activity';
	public const MIN_OPTION    = 'pc_product_activity_min';

	private const CACHE = 'pc_product_activity_v1';

	/** 判断测试单时最多看多少张订单（窗口内最近的访问上挂的）。 */
	private const MAX_ORDERS = 200;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_filter( 'pc_product_activity_available', array( self::class, 'available' ) );
	}

	/**
	 * 按店开关关着时不注册这条路由：和线上一样，请求得到 WordPress 自己的 404（rest_no_route），接口目录里也没有它。
	 * 开关在请求中途打开（用例用过滤器）时，rest() 里还会再判断一次。
	 */
	public static function register_route(): void {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			'peptide/v1',
			'/product-activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => '__return_true', // 公开只读：只有两个计数。
			)
		);
	}

	/** 这家店有数据可给：追踪表在，访客追踪开着。给主题的过滤器 pc_product_activity_available 用。 */
	public static function available( $already = false ): bool {
		return true === $already || ( Schema::installed() && Tracker::enabled() );
	}

	/** 按店开关（和主题同一个选项），默认关。 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'peptide_core_product_activity_enabled', 'on' === (string) get_option( self::SWITCH_OPTION, 'off' ) );
	}

	/** 店铺下限：1–1000 的整数，其他值一律按 5（和主题的 vc_product_activity_min() 同一条规则）。 */
	public static function minimum( $raw = null ): int {
		$raw = null === $raw ? get_option( self::MIN_OPTION, 5 ) : $raw;
		if ( is_string( $raw ) && preg_match( '/\A[0-9]{1,6}\z/D', $raw ) ) {
			$raw = (int) $raw;
		}
		if ( ! is_int( $raw ) || $raw < 1 ) {
			return 5;
		}
		return min( 1000, $raw );
	}

	/** product_id 参数：只接受十进制正整数（字符串或整数），其他返回 0。 */
	public static function product_id( $raw ): int {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}
		if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9][0-9]{0,17}\z/D', $raw ) ) {
			return 0;
		}
		return (int) $raw;
	}

	/**
	 * 接口。
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest( \WP_REST_Request $request ) {
		$id = self::product_id( $request->get_param( 'product_id' ) );
		if ( $id <= 0 ) {
			return Errors::invalid( array( 'product_id' => 'invalid_format' ) );
		}
		if ( ! self::enabled() || ! self::available() ) {
			return self::not_found( 'pc_product_activity_off' );
		}
		$post = get_post( $id );
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return self::not_found( 'pc_product_not_found' );
		}
		$counts = self::counts();
		if ( null === $counts ) {
			return self::not_found( 'pc_product_activity_unavailable' );
		}
		$minimum  = self::minimum();
		$response = new \WP_REST_Response(
			array(
				'product_id'   => $id,
				'viewing'      => self::shown( (int) ( $counts['viewing'][ $id ] ?? 0 ), $minimum ),
				'added'        => self::shown( (int) ( $counts['added'][ $id ] ?? 0 ), $minimum ),
				'window'       => array(
					'viewing_minutes' => self::VIEWING_MINUTES,
					'added_hours'     => self::ADDED_HOURS,
				),
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $counts['at'] ),
				'ttl'          => self::TTL,
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=' . self::TTL );
		return $response;
	}

	/** 低于下限的数不对外给：一律 0。 */
	public static function shown( int $count, int $minimum ): int {
		return $count >= $minimum ? $count : 0;
	}

	/** 404 也带缓存头，免得同一个地址被反复问。正文里没有任何数字。 */
	private static function not_found( string $code ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => __( 'Not available.', 'peptide-core' ),
				'data'    => array( 'status' => 404 ),
			),
			404
		);
		$response->header( 'Cache-Control', 'public, max-age=' . self::TTL );
		return $response;
	}

	/**
	 * 全店的两个计数表，缓存 TTL 秒。算不出来（数据库报错）返回 null，这次不缓存。
	 *
	 * @return array{at:int,viewing:array<int,int>,added:array<int,int>}|null
	 */
	public static function counts( ?int $now_ms = null ): ?array {
		$now_ms = $now_ms ?? Tracker::now_ms();
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['at'], $cached['viewing'], $cached['added'] ) && is_array( $cached['viewing'] ) && is_array( $cached['added'] ) ) {
			$age = (int) floor( $now_ms / 1000 ) - (int) $cached['at'];
			if ( $age >= 0 && $age < self::TTL ) {
				return $cached;
			}
		}
		$fresh = self::compute( $now_ms );
		if ( null !== $fresh ) {
			set_transient( self::CACHE, $fresh, self::TTL );
		}
		return $fresh;
	}

	/** 丢掉缓存（测试和冒烟用例用）。 */
	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * 现算一次。两条聚合查询都按 ( type, occurred_ms ) 取窗口内的事件（索引 type_time），再按商品数不同访客。
	 *
	 * @return array{at:int,viewing:array<int,int>,added:array<int,int>}|null
	 */
	public static function compute( int $now_ms ): ?array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return null;
		}
		$events   = Schema::table( 'events' );
		$visitors = Schema::table( 'visitors' );
		$added_ms = self::ADDED_HOURS * HOUR_IN_SECONDS * 1000;
		$skip     = self::test_visitors( $now_ms - $added_ms, $now_ms );
		if ( null === $skip ) {
			return null;
		}
		$not = $skip ? ' AND e.visitor_id NOT IN (' . implode( ',', array_map( 'intval', $skip ) ) . ')' : '';
		$out = array(
			'at'      => (int) floor( $now_ms / 1000 ),
			'viewing' => array(),
			'added'   => array(),
		);
		$windows = array(
			'viewing' => array( 'product_view', self::VIEWING_MINUTES * MINUTE_IN_SECONDS * 1000 ),
			'added'   => array( 'add_to_cart', $added_ms ),
		);
		$hidden = $wpdb->suppress_errors( true );
		foreach ( $windows as $key => $window ) {
			$wpdb->last_error = '';
			$rows             = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT e.product_id, COUNT(DISTINCT e.visitor_id) AS n FROM {$events} e INNER JOIN {$visitors} v ON v.id = e.visitor_id WHERE e.type = %s AND e.occurred_ms > %d AND e.occurred_ms <= %d AND e.is_test = 0 AND e.product_id > 0 AND v.is_test = 0{$not} GROUP BY e.product_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$window[0],
					$now_ms - $window[1],
					$now_ms
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				$wpdb->suppress_errors( $hidden );
				return null;
			}
			foreach ( (array) $rows as $row ) {
				$out[ $key ][ (int) $row['product_id'] ] = (int) $row['n'];
			}
		}
		$wpdb->suppress_errors( $hidden );
		return $out;
	}

	/**
	 * 窗口内的访问上挂着测试单的访客（下过全“1”测试单、用测试付款方式、买演示商品的人）：不算进两个数。
	 * 只看窗口内最近 MAX_ORDERS 个挂着订单的访问；查不了返回 null。
	 *
	 * @return int[]|null
	 */
	private static function test_visitors( int $from_ms, int $now_ms ): ?array {
		global $wpdb;
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$visits           = Schema::table( 'visits' );
		$hidden           = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT visitor_id, order_id FROM {$visits} WHERE last_ms >= %d AND last_ms <= %d AND order_id > 0 ORDER BY last_ms DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from_ms,
				$now_ms,
				self::MAX_ORDERS
			),
			ARRAY_A
		);
		$failed           = '' !== (string) $wpdb->last_error;
		$wpdb->suppress_errors( $hidden );
		if ( $failed ) {
			return null;
		}
		$skip   = array();
		$tested = array();
		foreach ( (array) $rows as $row ) {
			$order_id = (int) $row['order_id'];
			if ( ! isset( $tested[ $order_id ] ) ) {
				$order               = wc_get_order( $order_id );
				$tested[ $order_id ] = $order instanceof \WC_Order && Metrics::is_test_order( $order );
			}
			if ( $tested[ $order_id ] ) {
				$skip[ (int) $row['visitor_id'] ] = true;
			}
		}
		return array_keys( $skip );
	}
}
