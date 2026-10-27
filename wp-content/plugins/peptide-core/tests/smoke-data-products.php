<?php
/**
 * 商品详情数据页（DATA-01 第 3 步）冒烟测试：每个商品（和款式）的查看、加购、付款数字、和对比期的变化、
 * 排序、分页、搜索、参数校验、GET /peptide/v1/data/products 接口和权限、页面脚本的加载。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-products.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-03-10 为本期，2020-03-09 为对比期），不受站点上其他数据影响；
 * 需要访客追踪的表已安装；测试创建的订单、退款、用户、访客和事件在结束时彻底删除。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Products;
use PeptideCore\Data\Range;
use PeptideCore\Tracking\Schema;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
// 金额和比例：差不到 0.01 就算相等；null 只等于 null。
$eq = static function ( $got, $want ): bool {
	if ( null === $want || null === $got ) {
		return null === $want && null === $got;
	}
	return abs( (float) $got - (float) $want ) < 0.01;
};

global $wpdb;
$get_backup = $_GET;
$admin      = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

// 店铺时区的某个时刻 => Unix 秒。
$at = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();

if ( ! Schema::installed() ) {
	echo "FAIL tracking tables not installed\n";
	return;
}

// 商品 S：第一个已发布、价格大于 0、不是演示商品的简单商品。
$is_demo = static fn( int $id ): bool => '1' === get_post_meta( $id, \PeptideCore\Meta::DEMO_SEED, true );
$simple  = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate ) {
	if ( (float) $candidate->get_price() > 0 && ! $is_demo( $candidate->get_id() ) ) {
		$simple = $candidate;
		break;
	}
}
// 商品 V：第一个已发布、不是演示商品、至少两个能买且价格大于 0 的款式的可变商品。
$variable = null;
$va       = null;
$vb       = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'variable', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate ) {
	if ( $is_demo( $candidate->get_id() ) ) {
		continue;
	}
	$usable = array();
	foreach ( $candidate->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( $child instanceof WC_Product_Variation && $child->is_purchasable() && (float) $child->get_price() > 0 ) {
			$usable[] = $child;
		}
	}
	if ( count( $usable ) >= 2 ) {
		$variable = $candidate;
		$va       = $usable[0];
		$vb       = $usable[1];
		break;
	}
}
if ( ! $simple || ! $variable || ! $va || ! $vb ) {
	echo "FAIL fixture products\n";
	return;
}
$s_id = (int) $simple->get_id();
$v_id = (int) $variable->get_id();
$ps   = (float) $simple->get_price();
$pa   = (float) $va->get_price();
$pb   = (float) $vb->get_price();

// 订单。O1、O2 是游客订单。
$make_order = static function ( array $lines, string $status, string $local, string $email, ?callable $setup = null ) use ( $at ): WC_Order {
	$order = wc_create_order();
	foreach ( $lines as $line ) {
		$order->add_product( $line[0], $line[1] );
	}
	$order->set_customer_id( 0 );
	$order->set_billing_email( $email );
	if ( $setup ) {
		$setup( $order );
	}
	$order->calculate_totals();
	$order->set_status( $status );
	$order->set_date_created( $at( $local ) );
	$order->save();
	return $order;
};
// O1：S × 2，退 1 件。
$o1 = $make_order( array( array( $simple, 2 ) ), 'processing', '2020-03-10 10:00', 'o1@example.com' );
// O2：S × 1 和 VA × 1。
$o2 = $make_order( array( array( $simple, 1 ), array( $va, 1 ) ), 'completed', '2020-03-10 12:00', 'o2@example.com' );
// O3：测试付款方式下的单（测试订单，不计入）。
$o3 = $make_order(
	array( array( $simple, 5 ) ),
	'processing',
	'2020-03-10 13:00',
	'o3@example.com',
	static function ( WC_Order $o ): void {
		$o->set_payment_method( 'cod' );
		$o->set_payment_method_title( 'Test payment – no money is charged' );
	}
);
// O4：未付款（不计入）。
$o4 = $make_order( array( array( $simple, 1 ) ), 'pending', '2020-03-10 14:00', 'o4@example.com' );

$o1_items   = $o1->get_items();
$o1_item_id = (int) array_key_first( $o1_items );
$refund     = wc_create_refund(
	array(
		'order_id'   => $o1->get_id(),
		'amount'     => $ps,
		'line_items' => array(
			$o1_item_id => array(
				'qty'          => 1,
				'refund_total' => $ps,
			),
		),
	)
);
$refund_id  = 0;
if ( $refund instanceof WC_Order_Refund ) {
	$refund->set_date_created( $at( '2020-03-10 11:00' ) );
	$refund->save();
	$refund_id = (int) $refund->get_id();
}
$ok( 'fixture refund created', $refund_id > 0 );

// 访客和事件（visitor_id 是 visitors 表的数字 id）。
$visitor_ids = array();
$event_ids   = array();
$visitor     = static function ( int $n ) use ( $wpdb, $at, &$visitor_ids ): int {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visitors' ),
		array(
			'vid'      => md5( 'smoke-prod-' . $n . microtime() ),
			'first_ms' => $at( '2020-03-09 08:00' ) * 1000,
			'last_ms'  => $at( '2020-03-10 20:00' ) * 1000,
			'visits'   => 1,
			'is_test'  => 1,
		)
	);
	$visitor_ids[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$event       = static function ( int $n, int $visitor_id, string $type, string $local, int $product_id, int $variation_id, ?array $data ) use ( $wpdb, $at, &$event_ids ): void {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'events' ),
		array(
			'event_uid'    => 'smoke-prod-' . $n . '-' . uniqid(),
			'visitor_id'   => $visitor_id,
			'type'         => $type,
			'source'       => 'product_view' === $type ? 'client' : 'server',
			'occurred_ms'  => $at( $local ) * 1000,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'data'         => null === $data ? null : wp_json_encode( $data ),
			'is_test'      => 1,
		)
	);
	$event_ids[] = (int) $wpdb->insert_id;
};
$cart        = static fn( int $product_id, int $variation_id, int $quantity ): array => array(
	'product_id'   => $product_id,
	'variation_id' => $variation_id,
	'quantity'     => $quantity,
	'product_name' => 'smoke',
);
$w1          = $visitor( 1 );
$w2          = $visitor( 2 );
$w3          = $visitor( 3 );
// W1：看了 S 两次，加购 S 两件。
$event( 1, $w1, 'product_view', '2020-03-10 09:00', $s_id, 0, null );
$event( 2, $w1, 'product_view', '2020-03-10 09:05', $s_id, 0, null );
$event( 3, $w1, 'add_to_cart', '2020-03-10 09:06', $s_id, 0, $cart( $s_id, 0, 2 ) );
// W2：看了 S 和 V，加购 VA 一件、VB 三件。
$event( 4, $w2, 'product_view', '2020-03-10 09:10', $s_id, 0, null );
$event( 5, $w2, 'product_view', '2020-03-10 09:12', $v_id, 0, null );
$event( 6, $w2, 'add_to_cart', '2020-03-10 09:13', $v_id, (int) $va->get_id(), $cart( $v_id, (int) $va->get_id(), 1 ) );
$event( 7, $w2, 'add_to_cart', '2020-03-10 09:14', $v_id, (int) $vb->get_id(), $cart( $v_id, (int) $vb->get_id(), 3 ) );
// W3：对比期看了 S。
$event( 8, $w3, 'product_view', '2020-03-09 09:00', $s_id, 0, null );

$range = Range::from_params( array( 'from' => '2020-03-10', 'to' => '2020-03-10' ) );
$args  = Products::params( array() );
$t     = is_array( $range ) && is_array( $args ) ? Products::table( $range, $args ) : array( 'rows' => array() );

$find = static function ( array $table, int $id ): ?array {
	foreach ( $table['rows'] ?? array() as $row ) {
		if ( $id === $row['product']['id'] ) {
			return $row;
		}
	}
	return null;
};
$vmap = static function ( ?array $row ): array {
	$out = array();
	foreach ( $row['variations'] ?? array() as $v ) {
		$out[ $v['variation']['id'] ] = $v;
	}
	return $out;
};
$s    = $find( $t, $s_id );
$v    = $find( $t, $v_id );
$sc   = $s['current'] ?? array();
$sp   = $s['previous'] ?? array();
$vc   = $v['current'] ?? array();
$vars = $vmap( $v );
$vaid = (int) $va->get_id();
$vbid = (int) $vb->get_id();

// 1. S 的订单数字。
$ok( 'S paid orders 2 and paying users 2', 2 === ( $sc['paid_orders'] ?? null ) && 2 === ( $sc['paid_users'] ?? null ) );
$ok( 'S units paid 2 (2 minus 1 refunded plus 1)', 2 === ( $sc['paid_units'] ?? null ) );
$ok( 'S paid amount and net sales are 2 x price', $eq( $sc['sales'] ?? null, 2 * $ps ) && $eq( $sc['net_sales'] ?? null, 2 * $ps ) );

// 2. V 的订单数字和款式行。
$ok( 'V paid orders 1, units paid 1, paid amount = VA price', 1 === ( $vc['paid_orders'] ?? null ) && 1 === ( $vc['paid_units'] ?? null ) && $eq( $vc['sales'] ?? null, $pa ) );
$ok( 'V variation VA: units paid 1, paid amount = VA price', 1 === ( $vars[ $vaid ]['current']['paid_units'] ?? null ) && $eq( $vars[ $vaid ]['current']['sales'] ?? null, $pa ) );
$ok( 'V variation VB: units paid 0', 0 === ( $vars[ $vbid ]['current']['paid_units'] ?? null ) );
$var_ids = array_keys( $vars );
$sorted  = $var_ids;
sort( $sorted );
$ok( 'variation rows have a name and come ordered by variation id', '' !== (string) ( $vars[ $vaid ]['variation']['name'] ?? '' ) && 2 === count( $var_ids ) && $sorted === $var_ids );

// 3. 测试订单和未付款订单不计入。
$ok( 'the test-payment order and the unpaid order are not counted', ! in_array( $sc['paid_units'] ?? null, array( 7, 8 ), true ) && 2 === ( $sc['paid_orders'] ?? null ) );

// 4. 指标的顺序。
$ok( 'current, previous and change keys are exactly Products::METRICS in order', array_keys( $sc ) === Products::METRICS && array_keys( $sp ) === Products::METRICS && array_keys( $s['change'] ?? array() ) === Products::METRICS );
$ok( 'defaults equal Products::DEFAULTS and the response lists the metrics', Products::DEFAULTS === ( $t['defaults'] ?? null ) && Products::METRICS === ( $t['metrics'] ?? null ) && true === ( $t['tracking'] ?? null ) );

// 5. S 的查看和加购。
$ok( 'S views 3 and viewing users 2', 3 === ( $sc['views'] ?? null ) && 2 === ( $sc['view_users'] ?? null ) );
$ok( 'S add-to-cart: 1 action, 1 user, 2 units', 1 === ( $sc['carts'] ?? null ) && 1 === ( $sc['cart_users'] ?? null ) && 2 === ( $sc['cart_units'] ?? null ) );
$ok( 'S add-to-cart rate 33.33, conversion rate 66.67', $eq( $sc['cart_rate'] ?? null, 33.33 ) && $eq( $sc['conversion'] ?? null, 66.67 ) );
$ok( 'S previous views 1, views change +200%', 1 === ( $sp['views'] ?? null ) && $eq( $s['change']['views'] ?? null, 200.0 ) );

// 6. V 的查看和加购。
$ok( 'V views 1, add-to-cart actions 2, units 4', 1 === ( $vc['views'] ?? null ) && 2 === ( $vc['carts'] ?? null ) && 4 === ( $vc['cart_units'] ?? null ) );
$ok( 'V add-to-cart rate 200, conversion rate 100', $eq( $vc['cart_rate'] ?? null, 200.0 ) && $eq( $vc['conversion'] ?? null, 100.0 ) );

// 7. V 款式行的加购，没有查看数字。
$va_cur = $vars[ $vaid ]['current'] ?? array();
$vb_cur = $vars[ $vbid ]['current'] ?? array();
$ok( 'VA: 1 add-to-cart action, 1 unit, views null', 1 === ( $va_cur['carts'] ?? null ) && 1 === ( $va_cur['cart_units'] ?? null ) && array_key_exists( 'views', $va_cur ) && null === $va_cur['views'] );
$ok( 'VB: 1 add-to-cart action, 3 units, views null', 1 === ( $vb_cur['carts'] ?? null ) && 3 === ( $vb_cur['cart_units'] ?? null ) && array_key_exists( 'views', $vb_cur ) && null === $vb_cur['views'] );
$ok( 'variation rows have no rates and no viewing users (null)', array_key_exists( 'cart_rate', $va_cur ) && null === $va_cur['cart_rate'] && array_key_exists( 'conversion', $va_cur ) && null === $va_cur['conversion'] && array_key_exists( 'view_users', $va_cur ) && null === $va_cur['view_users'] );

// 8. 比率的变化是百分点差；对比期没有查看时比率是 null，变化也是 null。
$ok( 'S previous add-to-cart rate is 0 and its change is +33.33 points', $eq( $sp['cart_rate'] ?? null, 0.0 ) && null !== ( $sp['cart_rate'] ?? null ) && $eq( $s['change']['cart_rate'] ?? null, 33.33 ) );
$ok( 'V previous add-to-cart rate and its change are null (no previous views)', array_key_exists( 'cart_rate', $v['previous'] ?? array() ) && null === $v['previous']['cart_rate'] && array_key_exists( 'cart_rate', $v['change'] ?? array() ) && null === $v['change']['cart_rate'] );

// 9. 排序。
$ok( 'default sort (views, desc) puts S before V', array( $s_id, $v_id ) === array_slice( array_map( static fn( $r ) => $r['product']['id'], $t['rows'] ?? array() ), 0, 2 ) );
$asc = Products::table( $range, Products::params( array( 'sort' => 'paid_units', 'order' => 'asc' ) ) );
$ok( 'sort=paid_units&order=asc puts V before S', array( $v_id, $s_id ) === array_map( static fn( $r ) => $r['product']['id'], $asc['rows'] ) && 'paid_units' === $asc['sort'] && 'asc' === $asc['order'] );

// 10. 分页。
$p1 = Products::table( $range, Products::params( array( 'per_page' => 1 ) ) );
$p2 = Products::table( $range, Products::params( array( 'per_page' => 1, 'page' => 2 ) ) );
$p3 = Products::table( $range, Products::params( array( 'per_page' => 1, 'page' => 3 ) ) );
$ok( 'per_page=1: total is 2, page 1 is S', 2 === $p1['total'] && 1 === count( $p1['rows'] ) && $s_id === $p1['rows'][0]['product']['id'] );
$ok( 'per_page=1&page=2 returns only V, a page past the end is empty without an error', 1 === count( $p2['rows'] ) && $v_id === $p2['rows'][0]['product']['id'] && array() === $p3['rows'] && 2 === $p3['total'] );

// 11. 搜索。
$v_name = $variable->get_name();
$s_name = $simple->get_name();
$q      = Products::table( $range, Products::params( array( 'q' => '  ' . mb_strtoupper( $v_name ) . '  ' ) ) );
$q_ids  = array_map( static fn( $r ) => $r['product']['id'], $q['rows'] );
$ok( 'search by the upper-cased name of V finds V', in_array( $v_id, $q_ids, true ) );
$ok( 'search narrows the total (1) when the name of S does not contain the name of V', false !== mb_stripos( $s_name, $v_name ) || ( 1 === $q['total'] && array( $v_id ) === $q_ids ) );

// 12. 参数校验。
$bad_sort  = Products::params( array( 'sort' => 'bogus' ) );
$bad_per   = Products::params( array( 'per_page' => 500 ) );
$bad_order = Products::params( array( 'order' => 'up' ) );
$bad_page  = Products::params( array( 'page' => 0 ) );
$good      = Products::params( array( 'sort' => 'sales', 'order' => 'asc', 'page' => '3', 'per_page' => '50', 'q' => '  ab  ' ) );
$ok( 'sort=bogus is pc_invalid_param with fields.sort invalid_format', is_wp_error( $bad_sort ) && 'pc_invalid_param' === $bad_sort->get_error_code() && 'invalid_format' === ( $bad_sort->get_error_data()['fields']['sort'] ?? '' ) );
$ok( 'per_page=500 is invalid_format', is_wp_error( $bad_per ) && 'invalid_format' === ( $bad_per->get_error_data()['fields']['per_page'] ?? '' ) );
$ok( 'order=up is invalid_format', is_wp_error( $bad_order ) && 'invalid_format' === ( $bad_order->get_error_data()['fields']['order'] ?? '' ) );
$ok( 'page=0 is invalid_format', is_wp_error( $bad_page ) && 'invalid_format' === ( $bad_page->get_error_data()['fields']['page'] ?? '' ) );
$ok( 'valid params are cleaned (numbers as ints, q trimmed) and the defaults are views / desc / 1 / 20', array( 'sort' => 'sales', 'order' => 'asc', 'page' => 3, 'per_page' => 50, 'q' => 'ab' ) === $good && array( 'sort' => 'views', 'order' => 'desc', 'page' => 1, 'per_page' => 20, 'q' => '' ) === $args );

// 13–14. 接口。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/products' );
$req->set_param( 'from', '2020-03-10' );
$req->set_param( 'to', '2020-03-10' );
$res  = rest_do_request( $req );
$rows = $res->get_data()['rows'] ?? array();
$ok( 'admin can load the products data (200, S is in the rows)', 200 === $res->get_status() && in_array( $s_id, array_map( static fn( $r ) => $r['product']['id'], $rows ), true ) );
$ok( 'products response is not cached', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/products' );
$req->set_param( 'from', '2020-03-10' );
$req->set_param( 'to', '2020-03-10' );
$req->set_param( 'sort', 'bogus' );
$ok( 'sort=bogus returns 400', 400 === rest_do_request( $req )->get_status() );

$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_prod_customer',
		'user_email' => 'smoke-prod-customer@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = is_wp_error( $customer ) ? 0 : (int) $customer;
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/products' );
$req->set_param( 'from', '2020-03-10' );
$req->set_param( 'to', '2020-03-10' );
$ok( 'customers cannot load the products data', $customer > 0 && in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );

// 15. 商品页的脚本只在商品详情数据页加载。
$handles      = array( 'peptide-core-data-products', 'peptide-core-data-operations', Data_Pages::SCRIPT );
$_GET['page'] = 'pc-data-products';
Data_Pages::enqueue();
$before = implode( '', (array) wp_scripts()->get_data( 'peptide-core-data-products', 'before' ) );
$ok( 'products script is enqueued on the products page with its strings', wp_script_is( 'peptide-core-data-products', 'enqueued' ) && false !== strpos( $before, 'window.pcDataProducts' ) && false !== strpos( $before, 'Product views' ) );
foreach ( array( 'peptide-core-data-products', Data_Pages::SCRIPT ) as $handle ) {
	wp_dequeue_script( $handle );
}
$_GET['page'] = 'pc-data-operations';
Data_Pages::enqueue();
$ok( 'products script is not enqueued on the operations page', ! wp_script_is( 'peptide-core-data-products', 'enqueued' ) && wp_script_is( 'peptide-core-data-operations', 'enqueued' ) );
foreach ( array( 'peptide-core-data-products', 'peptide-core-data-operations', Data_Pages::SCRIPT ) as $handle ) {
	wp_dequeue_script( $handle );
	wp_dequeue_style( $handle );
}

// 清理。
$order_ids = array( $o1->get_id(), $o2->get_id(), $o3->get_id(), $o4->get_id() );
if ( $refund_id ) {
	$saved_refund = wc_get_order( $refund_id );
	if ( $saved_refund ) {
		$saved_refund->delete( true );
	}
}
foreach ( $order_ids as $id ) {
	$order = wc_get_order( $id );
	if ( $order ) {
		$order->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $customer > 0 ) {
	wp_delete_user( $customer );
}
foreach ( $event_ids as $id ) {
	$wpdb->delete( Schema::table( 'events' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
foreach ( $visitor_ids as $id ) {
	$wpdb->delete( Schema::table( 'visitors' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$_GET = $get_backup;

// 16. 测试数据都删掉了。
$left = 0;
foreach ( $order_ids as $id ) {
	$left += wc_get_order( $id ) ? 1 : 0;
}
$left += ( $refund_id && wc_get_order( $refund_id ) ) ? 1 : 0;
foreach ( $event_ids as $id ) {
	$left += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
}
foreach ( $visitor_ids as $id ) {
	$left += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
}
$ok( 'test data removed (orders, refund, events, visitors, temporary user)', 0 === $left && ( 0 === $customer || ! get_userdata( $customer ) ) && 8 === count( $event_ids ) && 3 === count( $visitor_ids ) );
