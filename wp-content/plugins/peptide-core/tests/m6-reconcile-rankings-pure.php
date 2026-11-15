<?php
/**
 * NF-02 对账用例（五）：排行。纯逻辑：php -n m6-reconcile-rankings-pure.php
 *
 *   用例 12  “已付款订单”的那几张表（下单支付方式、订单 / 顾客来源国家、优惠券、热卖 / 热门商品、商品类型、订单浏览器、订单来源）
 *   用例 13  支付成功率（按下单时间算尝试，开了之后“成功”用 Data\Metrics 的已付款判断）
 *   用例 14  选了入口域名
 *
 * 用的是真正的 Data\Rankings。排行里和订单无关的三块（分维度、搜索词、收藏）和入口域名筛选在这里是替身；
 * 关着时的完整输出由 data-native-search-pure.php 同冻结的旧结果逐字比对，这里不重复。
 */

namespace PeptideCore\Data {
	final class Dimension_Reports {
		public const TABLES = array( 'visitor_source', 'order_source', 'visitor_domain', 'order_domain', 'visitor_country' );
		/** 每次 period() 传进来的已付款订单（访客 / 访问），按调用顺序。 */
		public static array $seen = array();
		public static function rankings( int $from, int $to, array $orders, array &$cache ): array {
			self::$seen[] = $orders;
			foreach ( self::TABLES as $table ) { $cache['dimension_coverage'][ $table ][] = array(); }
			$cache['dimension_labels']['order_source']['fb'] = 'fb';
			return array( 'order_source' => $orders ? array( 'fb' => count( $orders ) ) : array() );
		}
	}
	final class Search_Observations {
		public static function period( int $from, int $to ): array { return array( 'rows' => array(), 'coverage' => array() ); }
	}
	final class Product_Actions {
		public static function period( int $from, int $to ): array { return array( 'products' => array(), 'kind_coverage' => array( 'favorite' => array() ) ); }
	}
	final class Entry_Domain {
		/** 归属所选域名的访问编号。 */
		public static array $visits = array();
		public static function orders( array $orders, string $host, int $cutoff_ms ): array {
			return array_values( array_filter( $orders, static fn( $o ) => in_array( (int) $o->get_meta( '_pc_track_visit' ), self::$visits, true ) ) );
		}
	}
	final class Realtime {
		public static function product_media( $product ): array { return array( 'image' => '', 'sku' => '' ); }
	}
}

namespace {
	require __DIR__ . '/m6-stubs.php';
	require dirname( __DIR__ ) . '/includes/data/class-metrics.php';
	require dirname( __DIR__ ) . '/includes/data/class-metrics-switch.php';
	require dirname( __DIR__ ) . '/includes/data/class-metrics-reconcile.php';
	require dirname( __DIR__ ) . '/includes/data/class-metrics-overlay.php';
	require dirname( __DIR__ ) . '/includes/data/class-range.php';
	require dirname( __DIR__ ) . '/includes/data/class-rankings.php';

	use PeptideCore\Data\Dimension_Reports;
	use PeptideCore\Data\Entry_Domain;
	use PeptideCore\Data\Metrics;
	use PeptideCore\Data\Metrics_Overlay as Overlay;
	use PeptideCore\Data\Metrics_Reconcile as Reconcile;
	use PeptideCore\Data\Metrics_Switch as Sw;
	use PeptideCore\Data\Rankings;
	use PeptideCore\Tracking\Schema;

	final class WC_Product {
		public function __construct( public int $id ) {}
		public function get_name() { return 'Product ' . $this->id; }
	}
	final class WP_Term {
		public function __construct( public int $term_id, public string $name ) {}
	}
	function wc_get_product( $id ) { return new WC_Product( (int) $id ); }
	function get_edit_post_link( $id, $context = 'display' ) { return '/edit/' . $id; }
	function wp_get_post_terms( $id, $taxonomy ) { return array( new WP_Term( 70, 'Peptides' ) ); }
	function wp_specialchars_decode( $text, $flags = 0 ) { return $text; }

	Schema::$installed = false; // 没有追踪表：浏览 / 加购 / 设备几张表是空的，订单浏览器全是 unknown。这个用例只看订单。

	// ---------- 数据：“今天” = [100, 200)，对比期“昨天” = [0, 100) ----------
	$item = static fn( int $id, int $product, int $quantity, float $subtotal ): WC_Order_Item_Product => new WC_Order_Item_Product( $id, $product, $quantity, $subtotal );

	// A：今天下单、今天付款。用了优惠券。
	$a          = m6_order( 1, 'processing', 40.0, 150, 150, 1, 11 );
	$a->coupons = array( 'save10' );
	$a->email   = 'a@buyer.test';
	$a->items   = array( $item( 101, 7, 2, 40.0 ) );
	// B：今天下单、明天才付款（加拿大）。
	$b          = m6_order( 2, 'processing', 20.0, 250, 150, 2, 12 );
	$b->country = 'CA';
	$b->items   = array( $item( 102, 7, 1, 20.0 ) );
	// C：昨天下单、今天付款，用的是另一种支付方式。
	$c               = m6_order( 3, 'completed', 30.0, 150, 50, 3, 13 );
	$c->method       = 'paypal';
	$c->method_title = 'PayPal';
	$c->email        = 'c@buyer.test';
	$c->items        = array( $item( 103, 8, 3, 30.0 ) );
	// D：今天下单，一直没付。
	m6_order( 4, 'pending', 20.0, null, 150 );
	// E：收件信息全 1 的测试单，今天下单今天付款。
	$e        = m6_order( 5, 'processing', 1.0, 160, 150, 5, 15 );
	$e->ones  = true;
	$e->items = array( $item( 105, 7, 5, 1.0 ) );
	// F：今天下单今天付款，后来状态改成了已退款（付过款）。
	$f        = m6_order( 6, 'refunded', 10.0, 150, 150, 6, 16 );
	$f->items = array( $item( 106, 9, 1, 10.0 ) );
	// G：今天下单，没付过款就被改成了已退款。
	m6_order( 7, 'refunded', 10.0, null, 150 );

	$range   = array( 'from' => 100, 'to' => 200, 'compare_from' => 0, 'compare_to' => 100 );
	$summary = static function ( ?string $entry_domain = null ) use ( $range ): array {
		Metrics::flush();
		Overlay::flush();
		Dimension_Reports::$seen = array();
		return Rankings::summary( $range, $entry_domain );
	};
	/** 一张表：key => [ 本期, 对比期 ]。 */
	$table = static function ( array $data, string $name ): array {
		$out = array();
		foreach ( $data['tables'][ $name ]['rows'] as $row ) { $out[ $row['key'] ] = array( $row['current'], $row['previous'] ); }
		return $out;
	};
	$visits = static fn( int $call ): array => array_column( Dimension_Reports::$seen[ $call ], 'visit' );

	// ---------- 关：旧口径（时间段内创建、已付款状态、旧的测试单规则） ----------
	$off = $summary();
	m6_check( 'off: nothing logged, no option written', array() === Sw::discard_pending() && array() === $GLOBALS['m6_options'] );
	m6_check( 'case 12 off: payment methods — A, B and the all-1 test order today; C yesterday', array( 'card' => array( 3, 0 ), 'paypal' => array( 0, 1 ) ) === $table( $off, 'payment_methods' ) );
	m6_check( 'case 12 off: order countries', array( 'US' => array( 2, 1 ), 'CA' => array( 1, 0 ) ) === $table( $off, 'order_countries' ) );
	m6_check( 'case 12 off: products by units and by sales', array( '7' => array( 8, 0 ), '8' => array( 0, 3 ) ) === $table( $off, 'products_units' ) && array( '7' => array( 61.0, 0 ), '8' => array( 0, 30.0 ) ) === $table( $off, 'products_sales' ) );
	m6_check( 'case 12 off: orders handed to the source / domain tables', array( 11, 12, 15 ) === $visits( 0 ) && array( 13 ) === $visits( 1 ) );
	m6_check( 'case 13 off: 5 of 6 attempts succeeded (a never-paid refunded order counts as a success)', array( 'card' => array( 83.33, null ), 'paypal' => array( null, 100.0 ) ) === $table( $off, 'payment_success' ) );

	// ---------- 影子：页面和关着时一模一样，两批订单号的差异进日志 ----------
	Sw::set( 'shadow' );
	$shadow = $summary();
	m6_check( 'shadow: the whole ranking payload is byte-for-byte what it was with the switch off', json_encode( $off ) === json_encode( $shadow ) );
	$logged = array_values( array_filter( Sw::discard_pending(), static fn( array $i ): bool => 'reports.paid_orders' === $i['metric'] ) );
	m6_check( 'shadow: one comparison per period', 2 === count( $logged ) );
	m6_check( 'shadow: today — B is counted by both rules now (version 2, order date); only F (refunded after paying) is new', array( '6' ) === array_column( $logged[0]['diffs'], 'metrics' ) && array( 'only_metrics' ) === array_column( $logged[0]['diffs'], 'path' ) );
	m6_check( 'shadow: yesterday — C is counted by both rules now, nothing differs', array() === $logged[1]['diffs'] );
	$why = Reconcile::orders( 100, 200 );
	ksort( $why['reasons'] );
	m6_check( 'shadow: every order that differs has a reason', $why['explained'] && array( 'refunded_status', 'test_order_rule' ) === array_keys( $why['reasons'] ) && array( 6 ) === $why['reasons']['refunded_status']['ids'] && array( 5 ) === $why['reasons']['test_order_rule']['ids'] );

	// ---------- 开：时间段内下单、收到过支付成功、算数的订单（口径版本 2） ----------
	Sw::set( 'on' );
	$on     = $summary();
	$orders = Metrics::orders( 100, 200 );
	m6_check( 'on: Data\Metrics on this data — A, B and F (placed today; C was placed yesterday)', 3 === $orders['orders'] && 7000 === $orders['sales_cents'] && 1 === $orders['test_orders'] );
	m6_check( 'case 12 on: payment methods add up to the order count of Data\Metrics', array( 'card' => array( 3, 0 ), 'paypal' => array( 0, 1 ) ) === $table( $on, 'payment_methods' ) && $orders['orders'] === array_sum( array_column( $table( $on, 'payment_methods' ), 0 ) ) );
	m6_check( 'case 12 on: the method of an order created earlier still gets its title', 'PayPal' === array_column( $on['tables']['payment_methods']['rows'], 'label', 'key' )['paypal'] );
	m6_check( 'case 12 on: order countries — B (paid tomorrow) stays today, C stays yesterday', array( 'US' => array( 2, 1 ), 'CA' => array( 1, 0 ) ) === $table( $on, 'order_countries' ) );
	m6_check( 'case 12 on: buyers by country', array( 'US' => array( 1, 1 ) ) === $table( $on, 'customer_countries' ) );
	m6_check( 'case 12 on: coupons', array( 'save10' => array( 1, 0 ) ) === $table( $on, 'coupons' ) );
	m6_check( 'case 12 on: products by units — no test order, no unpaid order', array( '7' => array( 3, 0 ), '9' => array( 1, 0 ), '8' => array( 0, 3 ) ) === $table( $on, 'products_units' ) );
	m6_check( 'case 12 on: products by sales', array( '7' => array( 60.0, 0 ), '9' => array( 10.0, 0 ), '8' => array( 0, 30.0 ) ) === $table( $on, 'products_sales' ) );
	m6_check( 'case 12 on: categories', array( '70' => array( 4, 3 ) ) === $table( $on, 'categories_units' ) && array( '70' => array( 70.0, 30.0 ) ) === $table( $on, 'categories_sales' ) );
	m6_check( 'case 12 on: order browsers and order source count the same three orders', array( 'unknown' => array( 3, 1 ) ) === $table( $on, 'order_browsers' ) && array( 'fb' => array( 3, 1 ) ) === $table( $on, 'order_source' ) && array( 11, 12, 16 ) === $visits( 0 ) && array( 13 ) === $visits( 1 ) );
	m6_check( 'case 13 on: attempts stay with the day the order was placed; 3 of 5 succeeded', array( 'card' => array( 60.0, null ), 'paypal' => array( null, 100.0 ) ) === $table( $on, 'payment_success' ) );

	// ---------- 用例 14：选了入口域名（只有访问 11、13 属于这个域名） ----------
	Entry_Domain::$visits = array( 11, 13 );
	$domain_on            = $summary( 'shop.test' );
	m6_check( 'case 14 on: paid tables are filtered by the entry domain', array( 'card' => array( 1, 0 ), 'paypal' => array( 0, 1 ) ) === $table( $domain_on, 'payment_methods' ) && array( 'US' => array( 1, 1 ) ) === $table( $domain_on, 'order_countries' ) && 'shop.test' === $domain_on['entry_domain'] );
	m6_check( 'case 14 on: the success rate only sees attempts of that domain', array( 'card' => array( 100.0, null ), 'paypal' => array( null, 100.0 ) ) === $table( $domain_on, 'payment_success' ) );
	Sw::set( 'off' );
	Sw::discard_pending();
	$domain_off = $summary( 'shop.test' );
	m6_check( 'case 14 off: the old rule with the same filter', array( 'card' => array( 1, 0 ), 'paypal' => array( 0, 1 ) ) === $table( $domain_off, 'payment_methods' ) );
	Sw::set( 'shadow' );
	m6_check( 'case 14 shadow: identical to off, and the comparison is still store-wide', json_encode( $domain_off ) === json_encode( $summary( 'shop.test' ) ) && array( '6' ) === array_column( array_values( array_filter( Sw::discard_pending(), static fn( array $i ): bool => 'reports.paid_orders' === $i['metric'] ) )[0]['diffs'], 'metrics' ) );

	// ---------- 换回关：和最开始一模一样 ----------
	Sw::set( 'off' );
	Sw::discard_pending();
	Entry_Domain::$visits = array();
	m6_check( 'back to off: the legacy ranking payload again', json_encode( $off ) === json_encode( $summary() ) && array() === Sw::discard_pending() );

	echo 'PASS ', $GLOBALS['m6_passed'], " ranking reconciliation assertions (paid-order tables / payment success / entry domain)\n";
}
