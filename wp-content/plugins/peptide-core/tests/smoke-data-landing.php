<?php
/**
 * 落地页转化页（DATA-01 第 9 步）冒烟测试：路径归因、十项指标、搜索排序分页、接口权限、页面资源和退款回归。
 * 固定店铺时区 2020-11-12，前一天的访问只通过本期订单归因；测试订单和未付款订单不计入。
 * 仅供开发环境；所有追踪行标为 is_test，订单、退款、用户、访客、访问和事件在 finally 中清理。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Landing;
use PeptideCore\Data\Range;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};
// 金额、比例的 null 只等于 null，不能当作 0。
$eq = static function ( $got, $want ): bool {
	if ( null === $want || null === $got ) {
		return null === $want && null === $got;
	}
	return abs( (float) $got - (float) $want ) < 0.01;
};
$at = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();

if ( ! Schema::installed() ) {
	echo "FAIL tracking tables not installed\n";
	return;
}
$simple = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate ) {
	if ( (float) $candidate->get_price() > 0 && '1' !== get_post_meta( $candidate->get_id(), \PeptideCore\Meta::DEMO_SEED, true ) ) {
		$simple = $candidate;
		break;
	}
}
if ( ! $simple ) {
	echo "FAIL fixture product\n";
	return;
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) {
	echo "FAIL fixture administrator\n";
	return;
}

global $wpdb;
$get_backup  = $_GET;
$user_backup = get_current_user_id();
$customer    = 0;
$visitor_ids = array();
$visit_ids   = array();
$order_ids   = array();
$refund_ids  = array();
$handles     = array( 'peptide-core-data', 'peptide-core-data-realtime', 'peptide-core-data-operations', 'peptide-core-data-products', 'peptide-core-data-pages', 'peptide-core-data-conversion', 'peptide-core-data-apps', 'peptide-core-data-emails', 'peptide-core-data-landing' );
$clear_assets = static function () use ( $handles ): void {
	foreach ( $handles as $handle ) {
		wp_dequeue_script( $handle );
		wp_dequeue_style( $handle );
	}
};
// 每次 enqueue 的最后一个 before 才是这一页的配置，不能拼接旧配置。
$last_config = static function ( string $handle, string $name ): array {
	$before = (array) wp_scripts()->get_data( $handle, 'before' );
	$last   = $before ? (string) end( $before ) : '';
	$prefix = 'window.' . $name . ' = ';
	return 0 === strpos( $last, $prefix ) ? (array) json_decode( rtrim( substr( $last, strlen( $prefix ) ), ';' ), true ) : array();
};

try {
	wp_set_current_user( $admins[0]->ID );
	$unique = str_replace( '.', '', uniqid( 'landing', true ) );
	$made   = wp_insert_user( array( 'user_login' => 'smoke_' . $unique, 'user_email' => $unique . '@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
	if ( is_wp_error( $made ) ) {
		throw new RuntimeException( 'temporary customer could not be created' );
	}
	$customer = (int) $made;

	// 访客和访问都按 W1/V1 至 W5/V5 的顺序插入，名称的最小 id 规则可直接验证。
	$visit = static function ( int $n, string $local, string $path, int $user, int $stage ) use ( $wpdb, $at, &$visitor_ids, &$visit_ids ): array {
		$start = $at( $local ) * 1000;
		$done  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'visitors' ),
			array( 'vid' => md5( 'smoke-landing-' . $n . microtime() ), 'first_ms' => $start, 'last_ms' => $start + 60000, 'visits' => 1, 'device' => 'desktop', 'browser' => 'Chrome', 'is_test' => 1 )
		);
		if ( false === $done ) {
			throw new RuntimeException( 'visitor fixture insert failed' );
		}
		$visitor_ids[] = (int) $wpdb->insert_id;
		$visitor_id    = (int) $wpdb->insert_id;
		$done          = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'visits' ),
			array( 'visitor_id' => $visitor_id, 'user_id' => $user, 'start_ms' => $start, 'last_ms' => $start + 60000, 'landing_path' => $path, 'device' => 'desktop', 'pageviews' => 1, 'stage' => $stage, 'is_test' => 1 )
		);
		if ( false === $done ) {
			throw new RuntimeException( 'visit fixture insert failed' );
		}
		$visit_ids[] = (int) $wpdb->insert_id;
		return array( $visitor_id, (int) $wpdb->insert_id );
	};
	list( $w1, $v1 ) = $visit( 1, '2020-11-12 10:00', '/lp-a/', 0, 5 );
	list( $w2, $v2 ) = $visit( 2, '2020-11-12 10:30', '/LP-A/', 0, 2 );
	list( $w3, $v3 ) = $visit( 3, '2020-11-12 11:00', '/LP-B/', $customer, 1 );
	list( $w4, $v4 ) = $visit( 4, '2020-11-12 12:00', '', 0, 5 );
	list( $w5, $v5 ) = $visit( 5, '2020-11-11 23:00', '/lp-old/', 0, 5 );

	// 追踪 meta 和付款方式在保存已付款状态前设好；无邮箱游客也允许测试。
	$make_order = static function ( string $status, string $local, string $email, array $meta = array(), bool $test = false ) use ( $simple, $at, &$order_ids ): WC_Order {
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			throw new RuntimeException( 'order fixture create failed' );
		}
		$order_ids[] = $order->get_id();
		$order->add_product( $simple, 1 );
		$order->set_customer_id( 0 );
		$order->set_billing_email( $email );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		if ( $test ) {
			$order->set_payment_method( 'cod' );
			$order->set_payment_method_title( 'Test payment – no money is charged' );
		}
		$order->calculate_totals();
		$order->set_status( $status );
		$order->set_date_created( $at( $local ) );
		$order->save();
		return $order;
	};
	$o1 = $make_order( 'processing', '2020-11-12 10:20', $unique . '-o1@example.com', array( Server_Events::ORDER_VISITOR_META => $w1, Server_Events::ORDER_VISIT_META => $v1 ) );
	$o2 = $make_order( 'completed', '2020-11-12 12:10', $unique . '-o2@example.com', array( Server_Events::ORDER_VISITOR_META => $w4, Server_Events::ORDER_VISIT_META => $v4 ) );
	$o3 = $make_order( 'processing', '2020-11-12 13:00', $unique . '-o3@example.com' );
	$o4 = $make_order( 'processing', '2020-11-12 13:30', $unique . '-o4@example.com', array( Server_Events::ORDER_VISITOR_META => $w1, Server_Events::ORDER_VISIT_META => $v1 ), true );
	$o5 = $make_order( 'pending', '2020-11-12 13:40', $unique . '-o5@example.com', array( Server_Events::ORDER_VISITOR_META => $w2, Server_Events::ORDER_VISIT_META => $v2 ) );
	$o6 = $make_order( 'processing', '2020-11-12 09:00', $unique . '-o6@example.com', array( Server_Events::ORDER_VISITOR_META => $w5, Server_Events::ORDER_VISIT_META => $v5 ) );
	$t1 = (float) $o1->get_total();
	$t2 = (float) $o2->get_total();
	$t3 = (float) $o3->get_total();
	$t6 = (float) $o6->get_total();

	$range = Range::from_params( array( 'from' => '2020-11-12', 'to' => '2020-11-12' ) );
	if ( ! is_array( $range ) ) {
		throw new RuntimeException( 'fixture range invalid' );
	}
	$table = static function ( array $extra = array() ) use ( $range ): array {
		$args = Landing::params( $extra );
		return is_array( $args ) ? Landing::table( $range, $args ) : array();
	};
	$pick = static function ( array $table, string $path ): array {
		foreach ( $table['rows'] ?? array() as $row ) {
			if ( strtolower( $path ) === strtolower( $row['path'] ) ) {
				return $row;
			}
		}
		return array();
	};
	$paths = static fn( array $table ): array => array_column( $table['rows'] ?? array(), 'path' );
	$t     = $table();
	$a     = $pick( $t, '/lp-a/' );
	$b     = $pick( $t, '/lp-b/' );
	$unk   = $pick( $t, '' );
	$old   = $pick( $t, '/lp-old/' );

	$ok( 'A merges case and uses lowest-id period visit label; UV 2 and customer 1', '/lp-a/' === ( $a['path'] ?? null ) && 2 === ( $a['uv'] ?? null ) && 1 === ( $a['customers'] ?? null ) );
	$ok( 'A viewed/carted/paid UV are 2/2/1; test and unpaid orders excluded', array( 2, 2, 1, 1 ) === array( $a['viewed_uv'] ?? null, $a['carted_uv'] ?? null, $a['paid_uv'] ?? null, $a['orders'] ?? null ) );
	$ok( 'A conversion 50, sales and AOV O1 total, UV value half O1 total', $eq( $a['conversion'] ?? null, 50 ) && $eq( $a['sales'] ?? null, $t1 ) && $eq( $a['aov'] ?? null, $t1 ) && $eq( $a['uv_value'] ?? null, round( $t1 / 2, 2 ) ) );
	$ok( 'B signed-in visitor: UV/customer/viewed 1, carted/paid/orders 0', array( 1, 1, 1, 0, 0, 0 ) === array( $b['uv'] ?? null, $b['customers'] ?? null, $b['viewed_uv'] ?? null, $b['carted_uv'] ?? null, $b['paid_uv'] ?? null, $b['orders'] ?? null ) );
	$ok( 'B conversion/sales/AOV/UV value are zero rather than null', $eq( $b['conversion'] ?? null, 0 ) && $eq( $b['sales'] ?? null, 0 ) && $eq( $b['aov'] ?? null, 0 ) && $eq( $b['uv_value'] ?? null, 0 ) );
	$ok( 'B keeps stored uppercase path and URL ends in /LP-B/', '/LP-B/' === ( $b['path'] ?? null ) && '/LP-B/' === substr( (string) ( $b['url'] ?? '' ), -strlen( '/LP-B/' ) ) );
	$ok( 'unknown groups empty visit and missing meta: UV 1, customers 2, viewed/carted/paid 1, orders 2', array( 1, 2, 1, 1, 1, 2 ) === array( $unk['uv'] ?? null, $unk['customers'] ?? null, $unk['viewed_uv'] ?? null, $unk['carted_uv'] ?? null, $unk['paid_uv'] ?? null, $unk['orders'] ?? null ) );
	$ok( 'unknown conversion 200, sales/UV value O2+O3, AOV half, URL empty', $eq( $unk['conversion'] ?? null, 200 ) && $eq( $unk['sales'] ?? null, $t2 + $t3 ) && $eq( $unk['aov'] ?? null, round( ( $t2 + $t3 ) / 2, 2 ) ) && $eq( $unk['uv_value'] ?? null, $t2 + $t3 ) && '' === ( $unk['url'] ?? null ) );
	$ok( 'old visit still attributes current order: UV/viewed/carted 0, customer/paid/orders 1', array( 0, 1, 0, 0, 1, 1 ) === array( $old['uv'] ?? null, $old['customers'] ?? null, $old['viewed_uv'] ?? null, $old['carted_uv'] ?? null, $old['paid_uv'] ?? null, $old['orders'] ?? null ) );
	$ok( 'old row has null conversion/UV value and sales/AOV O6 total', array_key_exists( 'conversion', $old ) && null === $old['conversion'] && array_key_exists( 'uv_value', $old ) && null === $old['uv_value'] && $eq( $old['sales'] ?? null, $t6 ) && $eq( $old['aov'] ?? null, $t6 ) );
	$ok( 'total 4; default UV desc ties by path: A, unknown, B, old', 4 === ( $t['total'] ?? null ) && array( '/lp-a/', '', '/LP-B/', '/lp-old/' ) === $paths( $t ) && true === ( $t['tracking'] ?? null ) );
	$ok( 'orders desc puts unknown first', '' === ( $paths( $table( array( 'sort' => 'orders' ) ) )[0] ?? null ) );
	$ok( 'conversion asc puts null old row first', '/lp-old/' === ( $paths( $table( array( 'sort' => 'conversion', 'order' => 'asc' ) ) )[0] ?? null ) );
	$q = $table( array( 'q' => 'lp-b' ) );
	$ok( 'case-insensitive search lp-b returns B only, total 1', array( '/LP-B/' ) === $paths( $q ) && 1 === ( $q['total'] ?? null ) );
	$q = $table( array( 'q' => 'zzz' ) );
	$ok( 'nonmatching search excludes unknown and returns total 0', array() === ( $q['rows'] ?? null ) && 0 === ( $q['total'] ?? null ) );
	$page = $table( array( 'per_page' => 1, 'page' => 2 ) );
	$ok( 'per_page 1 page 2 is unknown and total remains 4', array( '' ) === $paths( $page ) && 4 === ( $page['total'] ?? null ) && 2 === ( $page['page'] ?? null ) && 1 === ( $page['per_page'] ?? null ) );
	$page = $table( array( 'page' => 999999999 ) );
	$ok( 'huge page returns no rows and correct total without integer overflow', array() === ( $page['rows'] ?? null ) && 4 === ( $page['total'] ?? null ) );
	$ok( 'row keys are exactly path, url and METRICS in order', array_merge( array( 'path', 'url' ), Landing::METRICS ) === array_keys( $a ) );
	$ok( 'params defaults are UV/desc/page 1/20 per page/empty search', array( 'sort' => 'uv', 'order' => 'desc', 'page' => 1, 'per_page' => 20, 'q' => '' ) === Landing::params( array() ) );
	foreach ( array( 'sort' => 'bogus', 'per_page' => 0, 'page' => 'abc' ) as $key => $value ) {
		$bad = Landing::params( array( $key => $value ) );
		$ok( 'params invalid ' . $key . ' gives pc_invalid_param/invalid_format', is_wp_error( $bad ) && 'pc_invalid_param' === $bad->get_error_code() && 'invalid_format' === ( $bad->get_error_data()['fields'][ $key ] ?? '' ) );
	}

	// 退款后净销售额变化；订单数、UV 和转化率不能变化。
	$amount = round( $t1 / 4, 2 );
	if ( $amount <= 0 || $amount >= $t1 ) {
		throw new RuntimeException( 'fixture price does not permit a partial refund' );
	}
	$refund = wc_create_refund( array( 'order_id' => $o1->get_id(), 'amount' => $amount, 'reason' => 'Landing smoke partial refund', 'refund_payment' => false, 'restock_items' => false ) );
	if ( is_wp_error( $refund ) ) {
		throw new RuntimeException( 'partial refund fixture failed' );
	}
	$refund_ids[] = $refund->get_id();
	$net         = round( $t1 - $amount, 2 );
	$refunded    = $pick( $table(), '/lp-a/' );
	$ok( 'partial refund reduces sales/AOV/UV value independently by Woo refund amount', $eq( $refunded['sales'] ?? null, $net ) && $eq( $refunded['aov'] ?? null, $net ) && $eq( $refunded['uv_value'] ?? null, round( $net / 2, 2 ) ) );
	$ok( 'partial refund leaves UV/orders/conversion unchanged', 2 === ( $refunded['uv'] ?? null ) && 1 === ( $refunded['orders'] ?? null ) && $eq( $refunded['conversion'] ?? null, 50 ) );
	$refund->delete( true );

	// 访问 id 已不存在的无邮箱游客订单归未知；不能增加顾客数或付款 UV。
	$missing = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Schema::table( 'visits' ) ) + 1000000; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$extra   = $make_order( 'processing', '2020-11-12 15:00', '', array( Server_Events::ORDER_VISIT_META => $missing ) );
	$missing_row = $pick( $table(), '' );
	$ok( 'nonexistent visit goes to unknown; email-less guest adds no customer key', 3 === ( $missing_row['orders'] ?? null ) && 2 === ( $missing_row['customers'] ?? null ) && 1 === ( $missing_row['paid_uv'] ?? null ) && $eq( $missing_row['sales'] ?? null, $t2 + $t3 + (float) $extra->get_total() ) );
	$extra->delete( true );
	$ok( 'temporary missing-visit order removed and unknown returns to two orders', 2 === ( $pick( $table(), '' )['orders'] ?? null ) );

	$request = static function ( array $extra = array() ): WP_REST_Response {
		$req = new WP_REST_Request( 'GET', '/peptide/v1/data/landing' );
		foreach ( array_merge( array( 'from' => '2020-11-12', 'to' => '2020-11-12' ), $extra ) as $key => $value ) {
			$req->set_param( $key, $value );
		}
		return rest_do_request( $req );
	};
	$res = $request();
	$ok( 'REST admin 200 with four landing rows', 200 === $res->get_status() && 4 === ( $res->get_data()['total'] ?? null ) );
	$ok( 'REST success sends Cache-Control no-store', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
	$ok( 'REST invalid sort returns 400', 400 === $request( array( 'sort' => 'bogus' ) )->get_status() );
	$ok( 'REST invalid date returns 400', 400 === $request( array( 'from' => 'bad-date' ) )->get_status() );
	foreach ( array( 'sort', 'order', 'page', 'per_page' ) as $key ) {
		$bad = $request( array( $key => array( '1' ) ) );
		$ok( 'REST array-valued ' . $key . ' safely returns 400/invalid_format', 400 === $bad->get_status() && 'invalid_format' === ( $bad->get_data()['data']['fields'][ $key ] ?? '' ) );
	}
	wp_set_current_user( 0 );
	$ok( 'REST unauthenticated visitor is denied', in_array( $request()->get_status(), array( 401, 403 ), true ) );
	wp_set_current_user( $customer );
	$ok( 'REST customer is denied', in_array( $request()->get_status(), array( 401, 403 ), true ) );

	wp_set_current_user( $admins[0]->ID );
	$clear_assets();
	$_GET['page'] = 'pc-data-landing';
	Data_Pages::enqueue();
	$cfg = $last_config( Data_Pages::SCRIPT, 'pcData' );
	$lc  = $last_config( 'peptide-core-data-landing', 'pcDataLanding' );
	$ok( 'landing script and style are both enqueued on landing page', wp_script_is( 'peptide-core-data-landing', 'enqueued' ) && wp_style_is( 'peptide-core-data-landing', 'enqueued' ) );
	$ok( 'landing last inline config has path/unknown strings', ! empty( $lc['strings']['path'] ) && ! empty( $lc['strings']['unknown'] ) && ! empty( $lc['strings']['note'] ) );
	$ok( 'landing shell has range and explicitly disables comparison', true === ( $cfg['showRange'] ?? null ) && false === ( $cfg['showCompare'] ?? null ) && 'landing' === ( $cfg['page'] ?? null ) );
	foreach ( array( 'pages', 'operations', 'realtime' ) as $key ) {
		$clear_assets();
		$_GET['page'] = Data_Pages::PAGES[ $key ];
		Data_Pages::enqueue();
		$cfg = $last_config( Data_Pages::SCRIPT, 'pcData' );
		$ok( 'landing script/style absent on ' . $key . '; its own assets present', ! wp_script_is( 'peptide-core-data-landing', 'enqueued' ) && ! wp_style_is( 'peptide-core-data-landing', 'enqueued' ) && wp_script_is( 'peptide-core-data-' . $key, 'enqueued' ) && wp_style_is( 'peptide-core-data-' . $key, 'enqueued' ) );
		$ok( $key . ' shell retains comparison config and expected range toolbar flag', true === ( $cfg['showCompare'] ?? null ) && ( 'realtime' !== $key ) === ( $cfg['showRange'] ?? null ) && $key === ( $cfg['page'] ?? null ) );
	}
} catch ( Throwable $e ) {
	echo 'FAIL landing fixture exception: ', $e->getMessage(), "\n";
} finally {
	// 先删退款和订单，再删用户和追踪数据；即使断言失败或抛异常也必须执行。
	foreach ( array_merge( $refund_ids, $order_ids ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( $customer > 0 ) {
		wp_delete_user( $customer );
	}
	Privacy::delete_visitors( $visitor_ids );
	$clear_assets();
	$_GET = $get_backup;
	wp_set_current_user( $user_backup );
}

$left = 0;
foreach ( array_merge( $order_ids, $refund_ids ) as $id ) {
	$left += wc_get_order( $id ) ? 1 : 0;
}
$ok( 'all six base orders, temporary order and partial refund removed', 0 === $left && 7 === count( $order_ids ) && 1 === count( $refund_ids ) );
$left = array( 'visitors' => 0, 'visits' => 0, 'events' => 0 );
if ( $visitor_ids ) {
	$in = implode( ',', array_map( 'intval', $visitor_ids ) );
	foreach ( $left as $name => $unused ) {
		$column = 'visitors' === $name ? 'id' : 'visitor_id';
		$left[ $name ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) . " WHERE {$column} IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
$ok( 'all five visitors and visits and their events removed', array( 'visitors' => 0, 'visits' => 0, 'events' => 0 ) === $left && 5 === count( $visitor_ids ) && 5 === count( $visit_ids ) );
$ok( 'temporary customer deleted and GET/current user restored', $customer > 0 && ! get_userdata( $customer ) && $_GET === $get_backup && get_current_user_id() === $user_backup );
