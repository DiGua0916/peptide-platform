<?php
/**
 * 运营数据页排行表（DATA-01 第 7b 步）冒烟测试：十四张排行表（热卖商品、热门商品、浏览商品、加购商品、商品类型件数和金额、订单来源国家、顾客来源国家、
 * 访客浏览器、订单浏览器、下单支付方式、支付成功率、优惠券使用、设备）的本期、对比期、变化和排序，测试订单和未付款订单不计入、名称和编辑链接、
 * 每张表的行格式、GET /peptide/v1/data/rankings 接口和权限、页面脚本的文字。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-data-rankings.php
 * 测试数据都放在固定的历史日期（店铺时区 2020-08-10 为本期，2020-08-09 为对比期），不受站点上其他数据影响；需要访客追踪的表已安装；
 * 测试创建的订单、优惠券、商品、商品分类、用户、访客、访问和事件在结束时彻底删除（订单状态变化会写追踪事件，所以用 Privacy::delete_visitors() 清理）。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Rankings;
use PeptideCore\Data\Range;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

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
$ms = static fn( string $local ) => $at( $local ) * 1000;

if ( ! Schema::installed() ) {
	echo "FAIL tracking tables not installed\n";
	return;
}

// 商品 P：第一个已发布、价格大于 0、不是演示商品的简单商品。
$product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate ) {
	if ( (float) $candidate->get_price() > 0 && '1' !== get_post_meta( $candidate->get_id(), \PeptideCore\Meta::DEMO_SEED, true ) ) {
		$product = $candidate;
		break;
	}
}
if ( ! $product ) {
	echo "FAIL fixture product\n";
	return;
}
$pid = (int) $product->get_id();
$pp  = (float) $product->get_price();

// 优惠券 save10 不能已经存在（不动站点上原有的优惠券）。
if ( wc_get_coupon_id_by_code( 'save10' ) ) {
	echo "FAIL fixture coupon save10 already exists\n";
	return;
}

$uniq = substr( md5( uniqid( '', true ) ), 0, 8 );

// 商品分类 TC 和商品 T（价格 10，在 TC 里）。
$term = wp_insert_term( 'Smoke TC ' . wp_rand(), 'product_cat' );
if ( is_wp_error( $term ) ) {
	echo "FAIL fixture category\n";
	return;
}
$tc   = (int) $term['term_id'];
$t    = new WC_Product_Simple();
$t->set_name( 'Smoke Rank T ' . $uniq );
$t->set_status( 'publish' );
$t->set_regular_price( '10' );
$t->set_category_ids( array( $tc ) );
$t->save();
$tid = (int) $t->get_id();

// 优惠券要在订单之前建好。
$coupon = new WC_Coupon();
$coupon->set_code( 'save10' );
$coupon->set_discount_type( 'fixed_cart' );
$coupon->set_amount( 1 );
$coupon->save();

// 访客：V1 电脑 Chrome、V2 手机 Safari（本期），V3 电脑 Chrome（对比期）；各一次访问。
$visitor_ids = array();
$visit_ids   = array();
$track       = static function ( int $n, string $device, string $browser, string $start ) use ( $wpdb, $ms, &$visitor_ids, &$visit_ids ): array {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visitors' ),
		array(
			'vid'      => md5( 'smoke-rank-' . $n . microtime() ),
			'first_ms' => $ms( $start ),
			'last_ms'  => $ms( $start ),
			'visits'   => 1,
			'device'   => $device,
			'browser'  => $browser,
			'is_test'  => 1,
		)
	);
	$visitor_id    = (int) $wpdb->insert_id;
	$visitor_ids[] = $visitor_id;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'visits' ),
		array(
			'visitor_id' => $visitor_id,
			'start_ms'   => $ms( $start ),
			'last_ms'    => $ms( $start ) + 60000,
			'device'     => $device,
			'pageviews'  => 1,
			'is_test'    => 1,
		)
	);
	$visit_id    = (int) $wpdb->insert_id;
	$visit_ids[] = $visit_id;
	return array( $visitor_id, $visit_id );
};
list( $v1, $vi1 ) = $track( 1, 'desktop', 'Chrome', '2020-08-10 09:00' );
list( $v2, $vi2 ) = $track( 2, 'mobile', 'Safari', '2020-08-10 09:30' );
list( $v3 )       = $track( 3, 'desktop', 'Chrome', '2020-08-09 09:00' );

// 事件：V1 查看 T 两次、查看 P 一次，V2 加购 T 一次（本期）。
$event_ids = array();
$event     = static function ( int $visitor_id, int $visit_id, string $type, string $local, int $product_id ) use ( $wpdb, $ms, &$event_ids ): void {
	$time = $ms( $local );
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Schema::table( 'events' ),
		array(
			'event_uid'   => 'smoke-rank-' . count( $event_ids ) . '-' . md5( microtime() . $local . $type . $product_id ),
			'visitor_id'  => $visitor_id,
			'visit_id'    => $visit_id,
			'type'        => $type,
			'source'      => 'client',
			'occurred_ms' => $time,
			'received_ms' => $time,
			'product_id'  => $product_id,
			'is_test'     => 1,
		)
	);
	$event_ids[] = (int) $wpdb->insert_id;
};
$event( $v1, $vi1, 'product_view', '2020-08-10 09:01', $tid );
$event( $v1, $vi1, 'product_view', '2020-08-10 09:02', $tid );
$event( $v1, $vi1, 'product_view', '2020-08-10 09:03', $pid );
$event( $v2, $vi2, 'add_to_cart', '2020-08-10 09:31', $tid );

// 订单：都是游客订单；优惠券和状态在最后设（状态变化会写追踪事件）。
$coupon_result = null;
$make_order    = static function ( string $status, string $local, string $country, string $method, string $title, string $email, array $lines, string $coupon_code = '', int $visitor = 0 ) use ( $at, &$coupon_result ): WC_Order {
	$order = wc_create_order();
	foreach ( $lines as $line ) {
		$order->add_product( $line[0], $line[1] );
	}
	$order->set_customer_id( 0 );
	$order->set_billing_email( $email );
	$order->set_billing_country( $country );
	$order->set_payment_method( $method );
	$order->set_payment_method_title( $title );
	if ( $visitor > 0 ) {
		$order->update_meta_data( Server_Events::ORDER_VISITOR_META, $visitor );
	}
	$order->calculate_totals();
	if ( '' !== $coupon_code ) {
		$coupon_result = $order->apply_coupon( $coupon_code );
		$order->calculate_totals();
	}
	$order->set_status( $status );
	$order->set_date_created( $at( $local ) );
	$order->save();
	return $order;
};
$mail = static fn( string $prefix ): string => $prefix . '-' . $uniq . '@example.com';
$bacs = 'Direct bank transfer';
$cod  = 'Cash on delivery';

$oa = $make_order( 'processing', '2020-08-10 10:00', 'US', 'bacs', $bacs, $mail( 'a' ), array( array( $t, 2 ), array( $product, 1 ) ), 'save10', $v1 );
$ob = $make_order( 'completed', '2020-08-10 11:00', 'CA', 'bacs', $bacs, $mail( 'b' ), array( array( $t, 1 ) ) );
$oc = $make_order( 'processing', '2020-08-10 12:00', 'US', 'cod', $cod, $mail( 'b' ), array( array( $product, 3 ) ) );
$od = $make_order( 'completed', '2020-08-09 10:00', 'US', 'bacs', $bacs, $mail( 'd' ), array( array( $t, 1 ) ) );
$oe = $make_order( 'pending', '2020-08-10 14:00', 'US', 'bacs', $bacs, $mail( 'e' ), array( array( $t, 1 ) ) );
$of = $make_order( 'failed', '2020-08-10 15:00', 'US', 'cod', $cod, $mail( 'f' ), array( array( $product, 1 ) ) );
$og = $make_order( 'processing', '2020-08-10 16:00', 'DE', 'cod', 'Test payment – no money is charged', $mail( 'g' ), array( array( $product, 5 ) ), '', $v2 );
$orders = array( $oa, $ob, $oc, $od, $oe, $of, $og );
$ok( 'fixture: coupon save10 applied to order A and A has one coupon code', true === $coupon_result && array( 'save10' ) === wc_get_order( $oa->get_id() )->get_coupon_codes() );

$range = Range::from_params( array( 'from' => '2020-08-10', 'to' => '2020-08-10' ) );
$r     = is_array( $range ) ? Rankings::summary( $range ) : array();
$tab   = $r['tables'] ?? array();
// 按 key 找一行（找不到是 null）。
$row = static function ( string $table, string $key ) use ( $tab ): ?array {
	foreach ( $tab[ $table ]['rows'] ?? array() as $candidate ) {
		if ( (string) $key === $candidate['key'] ) {
			return $candidate;
		}
	}
	return null;
};
$keys = static fn( string $table ): array => array_column( $tab[ $table ]['rows'] ?? array(), 'key' );
// 一行的本期 / 对比期 / 变化是否等于期望值（null 只等于 null；行不存在时 false）。
$is = static fn( ?array $r, $cur, $prev, $chg ): bool => null !== $r && $eq( $r['current'], $cur ) && $eq( $r['previous'], $prev ) && $eq( $r['change'], $chg );

// 1. 商品。
$ok( 'products_units: T 3 / 1 / +200, P 4 / 0 / null; order P, T (unpaid, failed and test orders left out)', $is( $row( 'products_units', (string) $tid ), 3, 1, 200.0 ) && $is( $row( 'products_units', (string) $pid ), 4, 0, null ) && array( (string) $pid, (string) $tid ) === $keys( 'products_units' ) );
$ok( 'products_sales: T 30 / 10 / +200 (line subtotals, the coupon changes nothing), P is 4 x price / 0 / null', $is( $row( 'products_sales', (string) $tid ), 30, 10, 200.0 ) && $is( $row( 'products_sales', (string) $pid ), 4 * $pp, 0, null ) );
$ok( 'products_viewed: T 2, P 1, both previous 0 and change null', $is( $row( 'products_viewed', (string) $tid ), 2, 0, null ) && $is( $row( 'products_viewed', (string) $pid ), 1, 0, null ) && array( (string) $tid, (string) $pid ) === $keys( 'products_viewed' ) );
$ok( 'products_carted: only T with 1', $is( $row( 'products_carted', (string) $tid ), 1, 0, null ) && array( (string) $tid ) === $keys( 'products_carted' ) );

// 2. 商品类型。
$ok( 'categories_units: TC 3 / 1 / +200', $is( $row( 'categories_units', (string) $tc ), 3, 1, 200.0 ) );
$ok( 'categories_sales: TC 30 / 10 / +200', $is( $row( 'categories_sales', (string) $tc ), 30, 10, 200.0 ) );

// 3. 国家。
$ok( 'order_countries: US 2 / 1 / +100, CA 1 / 0 / null, no DE row (test order); order US, CA', $is( $row( 'order_countries', 'US' ), 2, 1, 100.0 ) && $is( $row( 'order_countries', 'CA' ), 1, 0, null ) && null === $row( 'order_countries', 'DE' ) && array( 'US', 'CA' ) === $keys( 'order_countries' ) );
$ok( 'customer_countries: US 2 buyers (a and b) / 1 (d) / +100, CA 1 buyer (b)', $is( $row( 'customer_countries', 'US' ), 2, 1, 100.0 ) && $is( $row( 'customer_countries', 'CA' ), 1, 0, null ) );

// 4. 浏览器和设备。
$ok( 'visitor_browsers: Chrome 1 / 1 / 0, Safari 1 / 0 / null; order Chrome, Safari', $is( $row( 'visitor_browsers', 'Chrome' ), 1, 1, 0.0 ) && $is( $row( 'visitor_browsers', 'Safari' ), 1, 0, null ) && array( 'Chrome', 'Safari' ) === $keys( 'visitor_browsers' ) );
$ok( 'order_browsers: unknown 2 (B, C) / 1 (D) / +100, Chrome 1 (A) / 0; order unknown, Chrome; no Safari row (G is a test order)', $is( $row( 'order_browsers', 'unknown' ), 2, 1, 100.0 ) && $is( $row( 'order_browsers', 'Chrome' ), 1, 0, null ) && null === $row( 'order_browsers', 'Safari' ) && array( 'unknown', 'Chrome' ) === $keys( 'order_browsers' ) );
$ok( 'devices: desktop 1 / 1 / 0, mobile 1 / 0 / null', $is( $row( 'devices', 'desktop' ), 1, 1, 0.0 ) && $is( $row( 'devices', 'mobile' ), 1, 0, null ) );

// 5. 支付方式、支付成功率、优惠券。
$ok( 'payment_methods: bacs 2 / 1 / +100, cod 1 / 0 / null; order bacs, cod', $is( $row( 'payment_methods', 'bacs' ), 2, 1, 100.0 ) && $is( $row( 'payment_methods', 'cod' ), 1, 0, null ) && array( 'bacs', 'cod' ) === $keys( 'payment_methods' ) );
$ok( 'payment_methods: labels are the payment titles (the test order title is not used for cod)', $bacs === ( $row( 'payment_methods', 'bacs' )['label'] ?? null ) && $cod === ( $row( 'payment_methods', 'cod' )['label'] ?? null ) );
$ok( 'payment_success: bacs 66.67 (A, B of A, B, E) / 100 (D) / -33.33 points, cod 50 (C of C, F) / null / null; order bacs, cod', $is( $row( 'payment_success', 'bacs' ), 66.67, 100.0, -33.33 ) && $is( $row( 'payment_success', 'cod' ), 50.0, null, null ) && array( 'bacs', 'cod' ) === $keys( 'payment_success' ) );
$ok( 'coupons: save10 1 / 0 / null and no other coupon', $is( $row( 'coupons', 'save10' ), 1, 0, null ) && array( 'save10' ) === $keys( 'coupons' ) );

// 6. 每张表的格式。
$shape = array_keys( $tab ) === Rankings::TABLES && 14 === count( Rankings::TABLES );
foreach ( Rankings::TABLES as $name ) {
	$shape = $shape && isset( $tab[ $name ]['rows'], $tab[ $name ]['total_rows'] ) && is_array( $tab[ $name ]['rows'] ) && array_is_list( $tab[ $name ]['rows'] );
}
$ok( 'every Rankings::TABLES key is in the tables, in order, with rows as a list and total_rows', $shape && true === ( $r['tracking'] ?? null ) );
$strings = true;
$fields  = true;
$totals  = true;
foreach ( $tab as $name => $table ) {
	$totals = $totals && count( $table['rows'] ) === $table['total_rows'] && count( $table['rows'] ) <= Rankings::MAX_ROWS;
	foreach ( $table['rows'] as $one ) {
		$strings = $strings && is_string( $one['key'] ) && is_string( $one['label'] ) && is_string( $one['edit_url'] );
		$fields  = $fields && array( 'key', 'label', 'edit_url', 'current', 'previous', 'change' ) === array_keys( $one );
	}
}
$ok( 'every row key, label and edit_url is a string, and every row has the six fields in order', $strings && $fields );
$ok( 'total_rows equals the number of rows in every table here (all under the limit) and MAX_ROWS is 50', $totals && 50 === Rankings::MAX_ROWS );

// 7. 名称和链接。
$trow = $row( 'products_units', (string) $tid );
$crow = $row( 'categories_units', (string) $tc );
$us   = $row( 'order_countries', 'US' );
$wc   = WC()->countries->get_countries();
$ok( 'products_units: the T row is named after the product and has an edit link', $t->get_name() === ( $trow['label'] ?? null ) && '' !== ( $trow['edit_url'] ?? '' ) );
$ok( 'categories_units: the TC row is named after the term; country, category, payment and coupon rows have no edit link', get_term( $tc )->name === ( $crow['label'] ?? null ) && '' === ( $crow['edit_url'] ?? 'x' ) && '' === ( $us['edit_url'] ?? 'x' ) && '' === ( $row( 'payment_methods', 'bacs' )['edit_url'] ?? 'x' ) && '' === ( $row( 'coupons', 'save10' )['edit_url'] ?? 'x' ) );
$ok( 'order_countries: the US row is labelled with the WooCommerce name for US', isset( $wc['US'] ) && html_entity_decode( $wc['US'], ENT_QUOTES, 'UTF-8' ) === ( $us['label'] ?? null ) );

// 8. 接口（管理员）。
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/rankings' );
$req->set_param( 'from', '2020-08-10' );
$req->set_param( 'to', '2020-08-10' );
$res = rest_do_request( $req );
$rd  = $res->get_data();
$ok( 'admin can load the rankings (200, all 14 tables, US has 2 paid orders) and the response is not cached', 200 === $res->get_status() && Rankings::TABLES === array_keys( $rd['tables'] ?? array() ) && 2 === ( $rd['tables']['order_countries']['rows'][0]['current'] ?? null ) && 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/rankings' );
$req->set_param( 'from', 'bad' );
$req->set_param( 'to', '2020-08-10' );
$ok( 'from=bad returns 400', 400 === rest_do_request( $req )->get_status() );

// 9. 接口（顾客不能看）。
$customer = wp_insert_user(
	array(
		'user_login' => 'smoke_rank_cust_' . $uniq,
		'user_email' => 'smoke-rank-cust-' . $uniq . '@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$customer = is_wp_error( $customer ) ? 0 : (int) $customer;
wp_set_current_user( $customer );
$req = new WP_REST_Request( 'GET', '/peptide/v1/data/rankings' );
$req->set_param( 'from', '2020-08-10' );
$req->set_param( 'to', '2020-08-10' );
$ok( 'customers cannot load the rankings', $customer > 0 && in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );

// 10. 页面脚本拿到排行表的文字（中文翻译在 JSON 里是 \uXXXX，所以按解码后的值比）。
$_GET['page'] = 'pc-data-operations';
Data_Pages::enqueue();
$before = (array) wp_scripts()->get_data( 'peptide-core-data-operations', 'before' );
$last   = (string) end( $before );
$json   = json_decode( substr( $last, (int) strpos( $last, '{' ), (int) strrpos( $last, '}' ) - (int) strpos( $last, '{' ) + 1 ), true );
$ok( 'operations page strings include the ranking titles (Best sellers), the table headings, the unknown label and the error text', false !== strpos( $last, 'window.pcDataOperations' ) && false !== strpos( $last, '"labelUnknown"' ) && __( 'Best sellers', 'peptide-core' ) === ( $json['strings']['t_products_units'] ?? null ) && __( 'Rankings', 'peptide-core' ) === ( $json['strings']['rankings'] ?? null ) && isset( $json['strings']['rankingsError'], $json['strings']['rankMore'], $json['strings']['rankLess'], $json['strings']['t_payment_success'], $json['strings']['t_devices'] ) );
wp_dequeue_script( 'peptide-core-data-operations' );
wp_dequeue_style( 'peptide-core-data-operations' );
wp_dequeue_script( Data_Pages::SCRIPT );
wp_dequeue_style( 'peptide-core-data' );

// 11. 清理：先删订单（订单状态变化会写追踪事件），再删优惠券、商品和分类、临时用户，最后用 Privacy::delete_visitors() 删访客和他们的事件、访问。
$order_ids = array();
foreach ( $orders as $order ) {
	$order_ids[] = $order->get_id();
	$saved       = wc_get_order( $order->get_id() );
	if ( $saved ) {
		$saved->delete( true );
	}
}
$coupon_id = (int) $coupon->get_id();
$coupon->delete( true );
$t->delete( true );
wp_delete_term( $tc, 'product_cat' );
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $customer > 0 ) {
	wp_delete_user( $customer );
}
Privacy::delete_visitors( $visitor_ids );
$_GET = $get_backup;
wp_set_current_user( $admin->ID );

$left = 0;
foreach ( $order_ids as $id ) {
	$left += wc_get_order( $id ) ? 1 : 0;
}
$left += wc_get_coupon_id_by_code( 'save10' ) ? 1 : 0;
$left += get_post( $coupon_id ) ? 1 : 0;
$left += get_post( $tid ) ? 1 : 0;
$left += term_exists( $tc, 'product_cat' ) ? 1 : 0;
$in    = implode( ',', array_map( 'intval', $visitor_ids ) );
if ( '' !== $in ) {
	$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'visits', 'events' ) as $name ) {
		$left += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) . " WHERE visitor_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
$ok( 'test data removed (7 orders, coupon, product T, category TC, temporary user, 3 visitors with their 3 visits and 4 events)', 0 === $left && ( 0 === $customer || ! get_userdata( $customer ) ) && 7 === count( $order_ids ) && 3 === count( $visitor_ids ) && 3 === count( $visit_ids ) && 4 === count( $event_ids ) );
