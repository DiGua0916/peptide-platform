<?php
/**
 * FE-05 八步漏斗回放：不用浏览器，把一次完整购买按顺序重放一遍，每走一步核对一次漏斗读数；
 * 再放一个“跳过填地址直接选支付、下单、付款”的反例，核对它不会被算进后四步。
 * 给沙盒和 F1 的 critical-path 套件用（那里“8 步漏斗顺序正确”一项原来因为事件要浏览器脚本上报而跳过）。
 *
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-funnel-replay-smoke.php run          # 按新规则（开关当成 on）
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-funnel-replay-smoke.php run shadow   # 开关当成 shadow
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-funnel-replay-smoke.php run off      # 开关当成 off
 *
 * 每一步走的都是线上的同一条路：
 *   浏览器事件（访问、看商品、进入结账、填地址、选物流、选支付）→ 事件接收接口 POST /peptide/v1/track/events；
 *   加购 → 真正的购物车（服务端自己记 add_to_cart）；
 *   下单 → 追踪模块挂在 WooCommerce 下单钩子上的处理函数；付款 → WooCommerce 的 payment_complete()。
 * 漏斗读数用 Data\Metrics::funnel()（今天，店铺时区），看的是每一步比运行前多了几个访客。
 *
 * 会写入：2 位访客、2 张订单（收件信息正常、邮箱是 @example.test），找不到可用的简单商品时临时建一个；
 * 结束时全部删掉，漏斗异常计数恢复成运行前的值。开关用这一次运行里的过滤器设定，选项 peptide_core_m6_mode 不改；
 * 这一次运行里邮件和对外请求一律拦下（付款完成会触发店里挂在它上面的其他动作）。不带 run 只打印说明。
 * 运行期间如果有真实访客进店，“比运行前多几个”会对不上——只在开发站 / 沙盒运行，不要在正式站点运行。
 *
 * 通过标准（退出码 0）：最后一行是“结果：通过（N 项检查）”。
 *
 * @package PeptideCore
 */

use PeptideCore\Data\Metrics;
use PeptideCore\Tracking\Funnel_Guard;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;
use PeptideCore\Tracking\Tracker;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Funnel_Guard::class ) || ! function_exists( 'rest_do_request' ) || ! function_exists( 'wc_create_order' ) ) {
	fwrite( STDERR, "请用 wp eval-file 在店铺目录里运行，并确认 peptide-core 和 WooCommerce 已启用。\n" );
	exit( 1 );
}
require_once __DIR__ . '/m6-smoke-fixtures.php';

$m6r_say  = static function ( string $text ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::log( $text );
	} else {
		echo $text, "\n";
	}
};
$m6r_stop = static function ( int $code ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::halt( $code );
	}
	exit( $code );
};

if ( 'run' !== ( $args[0] ?? '' ) ) {
	$m6r_say( '这个用例会写入 2 位测试访客和 2 张订单并走一遍付款完成，结束时都删掉。确认是开发站或沙盒之后，在命令最后加上 run 再运行：' );
	$m6r_say( '  wp eval-file wp-content/plugins/peptide-core/tests/m6-funnel-replay-smoke.php run' );
	return;
}
$m6r_mode = in_array( $args[1] ?? '', array( 'off', 'shadow', 'on' ), true ) ? $args[1] : 'on';

global $wpdb;

$m6r_fail   = array();
$m6r_checks = 0;
$m6r_check  = static function ( string $label, bool $ok, string $detail = '' ) use ( &$m6r_fail, &$m6r_checks, $m6r_say ): void {
	++$m6r_checks;
	$m6r_say( ( $ok ? '  通过  ' : '  没过  ' ) . $label . ( $ok || '' === $detail ? '' : '　实际：' . $detail ) );
	if ( ! $ok ) {
		$m6r_fail[] = $label;
	}
};

if ( ! Schema::installed() || ! Tracker::enabled() ) {
	$m6r_say( sprintf( '前提不满足：追踪表 %s，访客追踪 %s。', Schema::installed() ? '在' : '不在', Tracker::enabled() ? '开着' : '关着' ) );
	$m6r_say( '结果：没有通过（前提不满足，什么都没有写入）。' );
	$m6r_stop( 1 );
}

// ---------- 这一次运行里的设定 ----------
$m6r_switch  = static fn() => $m6r_mode;
$m6r_no_mail = static fn() => true;
$m6r_no_http = static fn( $pre, $args, $url ) => new \WP_Error( 'm6_smoke_blocked', 'blocked during the smoke run' );
add_filter( 'peptide_core_m6_mode', $m6r_switch, PHP_INT_MAX );
add_filter( 'pre_wp_mail', $m6r_no_mail, PHP_INT_MAX );
add_filter( 'pre_http_request', $m6r_no_http, PHP_INT_MAX, 3 );
$m6r_counters_before = get_option( Funnel_Guard::OPTION, null ); // 结束时原样放回去。
$m6r_backup          = pc_m6_smoke_browser();

// 商品：已发布、有价格、不是演示商品的简单商品；没有就临时建一个。
$m6r_product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $m6r_candidate ) {
	if ( (float) $m6r_candidate->get_price() > 0 && $m6r_candidate->is_purchasable() && $m6r_candidate->is_in_stock() && '1' !== get_post_meta( $m6r_candidate->get_id(), \PeptideCore\Meta::DEMO_SEED, true ) ) {
		$m6r_product = $m6r_candidate;
		break;
	}
}
$m6r_product = $m6r_product ?: pc_m6_smoke_temp_product();
if ( ! $m6r_product ) {
	pc_m6_smoke_browser_restore( $m6r_backup );
	$m6r_say( '结果：没有通过（找不到也建不出可用的商品）。' );
	$m6r_stop( 1 );
}
$m6r_pid = (int) $m6r_product->get_id();
if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
	wc_load_cart();
}

// ---------- 读数 ----------
$m6r_window = Metrics::window( 'today' );
/** 今天的漏斗八步人数。 */
$m6r_funnel = static function () use ( $m6r_window ): array {
	Metrics::flush();
	return array_map( 'intval', array_values( Metrics::funnel( (int) $m6r_window['from'], (int) $m6r_window['to'] )['counts'] ) );
};
$m6r_orders = static function () use ( $m6r_window ): array {
	Metrics::flush();
	$orders = Metrics::orders( (int) $m6r_window['from'], (int) $m6r_window['to'] );
	return array( (int) $orders['orders'], (int) $orders['sales_cents'] );
};
/** 漏斗异常计数（各天加起来）：[ 没有填地址就下单或付款被记下的次数, 事后补上的次数 ]。 */
$m6r_anomalies = static function (): array {
	$blocked = 0;
	$settled = 0;
	foreach ( Funnel_Guard::counters( 2 ) as $day ) {
		$blocked += (int) ( $day['no_address_4'] ?? 0 ) + (int) ( $day['no_address_5'] ?? 0 );
		$settled += (int) ( $day['settled'] ?? 0 );
	}
	return array( $blocked, $settled );
};
$m6r_base          = $m6r_funnel();
$m6r_orders_base   = $m6r_orders();
$m6r_anomaly_base  = $m6r_anomalies();
$m6r_names         = array( '访问', '看商品', '加购', '发起结账', '填地址', '选物流', '选支付', '支付成功' );
/** 现在的漏斗比运行前多出来的人数是不是正好等于 $want（八个数）。 */
$m6r_delta_is = static function ( array $want ) use ( $m6r_funnel, $m6r_base ): array {
	$now   = $m6r_funnel();
	$delta = array();
	foreach ( $now as $i => $count ) {
		$delta[] = $count - $m6r_base[ $i ];
	}
	return array( $delta === $want, implode( ',', $delta ) );
};
$m6r_step = static function ( string $label, array $want, ?int $stage = null ) use ( $m6r_delta_is, $m6r_check ): void {
	[ $ok, $got ] = $m6r_delta_is( $want );
	[ , , $visit_stage ] = pc_m6_smoke_current_visit();
	$stage_ok = null === $stage || $stage === $visit_stage;
	$m6r_check( $label . '：漏斗比运行前多 ' . implode( ',', $want ) . ( null === $stage ? '' : '，访问的阶段是 ' . $stage ), $ok && $stage_ok, $got . ( null === $stage ? '' : '，阶段 ' . $visit_stage ) );
};

$m6r_home     = array( 'page_type' => 'home', 'path' => '/' );
$m6r_page     = array( 'page_type' => 'product', 'path' => '/product/m6-replay/', 'product_id' => $m6r_pid, 'product_name' => 'M6 replay' );
$m6r_checkout = array( 'page_type' => 'checkout', 'path' => '/checkout/' );
$m6r_alive    = array( 'visibility' => 'visible', 'activity' => 'active' );
/** 提交一条浏览器事件，返回是不是被接口确认了。 */
$m6r_emit = static function ( string $type, array $page, array $data = array() ): bool {
	[ $status, $ack ] = pc_m6_smoke_send( array( array( $type, $page, $data ) ) );
	return 200 === $status && 1 === $ack;
};
/** 给当前访客建一张收件信息正常的订单（还没下单、没付款）。 */
$m6r_new_order = static function ( string $who ) use ( $m6r_product ): \WC_Order {
	$order   = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( $m6r_product, 1 );
	$address = array( 'first_name' => 'Replay', 'last_name' => 'Buyer', 'address_1' => '9 Oak Ave', 'city' => 'Reno', 'state' => 'NV', 'postcode' => '89501', 'country' => 'US', 'email' => 'm6-replay-' . $who . '@example.test' );
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->set_payment_method( 'bacs' );
	$order->set_payment_method_title( 'Direct bank transfer' );
	$order->calculate_totals();
	$order->save();
	return $order;
};

$m6r_say( '八步漏斗回放　开关按 ' . $m6r_mode . '（这次运行里的过滤器；店铺的选项不动）　商品 #' . $m6r_pid . '　口径版本 ' . Metrics::VERSION );
$m6r_say( '运行前今天的漏斗：' . implode( ' → ', $m6r_base ) );
$m6r_visitors = array();
$m6r_made     = array();

// ---------- A. 一次完整的购买 ----------
$m6r_say( '' );
$m6r_say( 'A. 一次完整的购买，按顺序走八步' );
pc_m6_smoke_new_visitor();
$m6r_sent = $m6r_emit( 'page_view', $m6r_home, $m6r_alive );
[ $m6r_visitors['A'] ] = pc_m6_smoke_current_visit();
$m6r_step( '1 访问', array( 1, 0, 0, 0, 0, 0, 0, 0 ), 0 );
$m6r_sent = $m6r_emit( 'product_view', $m6r_page, array( 'product_id' => $m6r_pid ) ) && $m6r_sent;
$m6r_step( '2 看商品', array( 1, 1, 0, 0, 0, 0, 0, 0 ), 1 );
WC()->cart->empty_cart();
$m6r_carted = (bool) WC()->cart->add_to_cart( $m6r_pid, 1 );
$m6r_step( '3 加购（真正的购物车）', array( 1, 1, 1, 0, 0, 0, 0, 0 ), 2 );
$m6r_sent = $m6r_emit( 'checkout_view', $m6r_checkout ) && $m6r_sent;
$m6r_step( '4 发起结账', array( 1, 1, 1, 1, 0, 0, 0, 0 ), 3 );
$m6r_sent = $m6r_emit( 'checkout_address_completed', $m6r_checkout, array( 'source' => 'native_blocks' ) ) && $m6r_sent;
$m6r_step( '5 填地址', array( 1, 1, 1, 1, 1, 0, 0, 0 ), 3 );
$m6r_sent = $m6r_emit( 'checkout_shipping_selected', $m6r_checkout, array( 'method' => 'flat_rate:1', 'origin' => 'user' ) ) && $m6r_sent;
$m6r_step( '6 选物流', array( 1, 1, 1, 1, 1, 1, 0, 0 ), 3 );
$m6r_sent = $m6r_emit( 'checkout_payment_selected', $m6r_checkout, array( 'method' => 'bacs', 'origin' => 'user' ) ) && $m6r_sent;
$m6r_step( '7 选支付', array( 1, 1, 1, 1, 1, 1, 1, 0 ), 3 );
$m6r_made['A'] = $m6r_new_order( 'a' );
Server_Events::on_order_placed( $m6r_made['A'] );
$m6r_step( '  下单（还没付款）', array( 1, 1, 1, 1, 1, 1, 1, 0 ), 4 );
$m6r_made['A']->payment_complete( 'm6-replay-a' );
$m6r_step( '8 支付成功', array( 1, 1, 1, 1, 1, 1, 1, 1 ), 5 );
$m6r_check( 'A 的六条浏览器事件都被接口确认，加购成功', $m6r_sent && $m6r_carted );
[ , , , $m6r_linked ] = pc_m6_smoke_current_visit();
$m6r_check( 'A 的订单挂在这次访问上', $m6r_linked === (int) $m6r_made['A']->get_id(), (string) $m6r_linked );

// ---------- B. 反例：跳过填地址 ----------
$m6r_say( '' );
$m6r_say( 'B. 反例：没有填地址的记录，直接选物流、选支付、下单、付款' );
pc_m6_smoke_new_visitor();
WC()->cart->empty_cart();
$m6r_sent = $m6r_emit( 'page_view', $m6r_home, $m6r_alive );
[ $m6r_visitors['B'] ] = pc_m6_smoke_current_visit();
$m6r_sent   = $m6r_emit( 'product_view', $m6r_page, array( 'product_id' => $m6r_pid ) ) && $m6r_sent;
$m6r_carted = (bool) WC()->cart->add_to_cart( $m6r_pid, 1 );
$m6r_sent   = $m6r_emit( 'checkout_view', $m6r_checkout ) && $m6r_sent;
$m6r_step( '走到发起结账', array( 2, 2, 2, 2, 1, 1, 1, 1 ), 3 );
$m6r_sent = $m6r_emit( 'checkout_shipping_selected', $m6r_checkout, array( 'method' => 'flat_rate:1', 'origin' => 'initial' ) ) && $m6r_sent;
$m6r_sent = $m6r_emit( 'checkout_payment_selected', $m6r_checkout, array( 'method' => 'bacs', 'origin' => 'user' ) ) && $m6r_sent;
$m6r_step( '选了物流和支付但没有填地址：后四步一个都不算', array( 2, 2, 2, 2, 1, 1, 1, 1 ), 3 );
$m6r_made['B'] = $m6r_new_order( 'b' );
Server_Events::on_order_placed( $m6r_made['B'] );
$m6r_made['B']->payment_complete( 'm6-replay-b' );
// 写入侧：开着时停在“发起结账”（3）；关着或影子时照旧推进到 5。读数那一侧三档都不算。
$m6r_want_stage = 'on' === $m6r_mode ? 3 : 5;
$m6r_step( '下单并付款之后：漏斗仍然不算后四步', array( 2, 2, 2, 2, 1, 1, 1, 1 ), $m6r_want_stage );
$m6r_check( 'B 的五条浏览器事件都被接口确认，加购成功', $m6r_sent && $m6r_carted );
[ , $m6r_visit_b, , $m6r_linked ] = pc_m6_smoke_current_visit();
$m6r_check( 'B 的订单照样挂在这次访问上（弃单和明细靠它找订单）', $m6r_linked === (int) $m6r_made['B']->get_id(), (string) $m6r_linked );
$m6r_paid_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visit_id = %d AND type = 'payment_success'", $m6r_visit_b ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
$m6r_check( 'B 的付款事件照样记下了（只是不推进漏斗）', 1 === $m6r_paid_event, (string) $m6r_paid_event );
[ $m6r_orders_now, $m6r_sales_now ] = $m6r_orders();
$m6r_check( '订单数和销售额不受影响：两张订单都算', 2 === $m6r_orders_now - $m6r_orders_base[0] && $m6r_sales_now - $m6r_orders_base[1] === Metrics::cents( $m6r_made['A']->get_total() ) + Metrics::cents( $m6r_made['B']->get_total() ), ( $m6r_orders_now - $m6r_orders_base[0] ) . ' 单 ' . ( $m6r_sales_now - $m6r_orders_base[1] ) . ' 分' );
[ $m6r_blocked ] = $m6r_anomalies();
$m6r_want_blocked = 'off' === $m6r_mode ? 0 : 2; // 下单一次、付款一次。
$m6r_check( 'off' === $m6r_mode ? '开关关着：不记异常' : '没有填地址就下单、付款：各记一次异常（共 2 次）', $m6r_want_blocked === $m6r_blocked - $m6r_anomaly_base[0], (string) ( $m6r_blocked - $m6r_anomaly_base[0] ) );

// ---------- C. 填地址的事件晚到 ----------
$m6r_say( '' );
$m6r_say( 'C. B 的填地址事件晚到（浏览器攒批上报，比下单还晚）' );
$m6r_late = $m6r_emit( 'checkout_address_completed', $m6r_checkout, array( 'source' => 'native_blocks' ) );
$m6r_step( '事件一到就补上：B 也算进后四步', array( 2, 2, 2, 2, 2, 2, 2, 2 ), 5 );
[ , $m6r_settled ] = $m6r_anomalies();
$m6r_check( 'off' === $m6r_mode ? '开关关着：没有“已补上”的记录' : '这次异常记为“已补上”', $m6r_late && ( 'off' === $m6r_mode ? 0 : 1 ) === $m6r_settled - $m6r_anomaly_base[1], (string) ( $m6r_settled - $m6r_anomaly_base[1] ) );
$m6r_now = $m6r_funnel();
$m6r_monotone = true;
foreach ( $m6r_now as $m6r_i => $m6r_count ) {
	$m6r_monotone = $m6r_monotone && ( 0 === $m6r_i || $m6r_count <= $m6r_now[ $m6r_i - 1 ] );
}
$m6r_check( '今天的漏斗每一步都不大于上一步', $m6r_monotone, implode( ' → ', $m6r_now ) );
$m6r_check( '全程没有数据库报错', '' === (string) $wpdb->last_error, (string) $wpdb->last_error );

// ---------- 清理 ----------
$m6r_say( '' );
$m6r_say( '清理' );
WC()->cart->empty_cart();
foreach ( $m6r_made as $m6r_order ) {
	$m6r_order->delete( true );
}
$m6r_ids = array_values( array_unique( array_filter( array_map( 'intval', $m6r_visitors ) ) ) );
if ( $m6r_ids ) {
	Privacy::delete_visitors( $m6r_ids );
}
if ( null === $m6r_counters_before ) {
	delete_option( Funnel_Guard::OPTION );
} else {
	update_option( Funnel_Guard::OPTION, $m6r_counters_before, false );
}
[ $m6r_ok, $m6r_got ] = $m6r_delta_is( array( 0, 0, 0, 0, 0, 0, 0, 0 ) );
$m6r_check( '访客和订单删掉之后，今天的漏斗回到运行前的数', $m6r_ok, $m6r_got );
$m6r_check( '订单数回到运行前，漏斗异常计数恢复成运行前的值', $m6r_orders() === $m6r_orders_base && $m6r_anomalies() === $m6r_anomaly_base );
remove_filter( 'peptide_core_m6_mode', $m6r_switch, PHP_INT_MAX );
remove_filter( 'pre_wp_mail', $m6r_no_mail, PHP_INT_MAX );
remove_filter( 'pre_http_request', $m6r_no_http, PHP_INT_MAX );
Metrics::flush();
pc_m6_smoke_browser_restore( $m6r_backup );

$m6r_say( '' );
if ( $m6r_fail ) {
	$m6r_say( '结果：没有通过（' . count( $m6r_fail ) . ' / ' . $m6r_checks . ' 项）。' );
	$m6r_stop( 1 );
}
$m6r_say( '结果：通过（' . $m6r_checks . ' 项检查）。八步按顺序各加一人；没有填地址记录的付款不算进后四步' . ( 'on' === $m6r_mode ? '，访问停在“发起结账”' : '' ) . '；填地址事件晚到会补上。' );
