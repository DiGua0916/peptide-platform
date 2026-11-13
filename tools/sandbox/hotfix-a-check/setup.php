<?php
// 热修复包 A 的沙盒验证：准备 + 判断函数 + 订单路径。只在沙盒执行（run.sh 调用）。
if ( ! defined( 'VIALCOVE_SANDBOX' ) || ! VIALCOVE_SANDBOX ) { fwrite( STDERR, "sandbox only\n" ); exit( 1 ); }
use PeptideCore\Pixels; use PeptideCore\Pixels\Server; use PeptideCore\Tracking\Tracker; use PeptideCore\Test_Orders;
$ok = static function ( string $what, bool $pass, string $extra = '' ) { echo ( $pass ? 'PASS ' : 'FAIL ' ), $what, $extra ? '  [' . $extra . ']' : '', "\n"; };
// 1. 临时放一个假的 Facebook 像素（沙盒对外请求是拦住的，不会真的发给 Meta）；原设置存起来，cleanup.php 还原。
$opt = Pixels::OPTION; $before = get_option( $opt, null ); update_option( 'f1_hv_px_backup', array( 'had' => null !== $before, 'value' => $before ), false );
$s = is_array( $before ) ? $before : array(); $s['staff'] = false;
$s['platforms']['facebook']['pixels'] = array( array( 'id' => '100000000000001', 'note' => 'F1 sandbox check', 'domain' => '', 'token' => 'SANDBOX_FAKE_TOKEN' ) );
update_option( $opt, $s );
$host = Pixels::store_domain(); $set = Pixels::settings();
$ok( '假像素已生效（any=1，服务端目标 1 个）', Pixels::any() && 1 === count( Server::targets( $set, $host ) ), 'host=' . $host );
// 2. 员工判断（真实数据库里的账号和角色）
$buyer = get_user_by( 'login', 'sbx_buyer' ); $admin = get_user_by( 'login', 'sandbox_admin' );
$cid = username_exists( 'f1_hv_customer' ) ?: wp_insert_user( array( 'user_login' => 'f1_hv_customer', 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'f1-hv-customer@example.com', 'role' => 'customer' ) ); $cust = get_user_by( 'id', $cid );
$ok( 'Tracker::is_staff：投手 = 是', Tracker::is_staff( $buyer->ID ), 'roles=' . implode( ',', $buyer->roles ) . ' manage_woocommerce=' . (int) user_can( $buyer->ID, 'manage_woocommerce' ) );
$ok( 'Tracker::is_staff：管理员 = 是', Tracker::is_staff( $admin->ID ) );
$ok( 'Tracker::is_staff：顾客 = 否', $cust && ! Tracker::is_staff( $cust->ID ) );
$ok( 'Tracker::is_staff：未登录 = 否', ! Tracker::is_staff( 0 ) );
unset( $_COOKIE[ Pixels::OPTOUT ], $_SERVER['HTTP_SEC_GPC'] );
wp_set_current_user( 0 ); $ok( 'Pixels::allowed：未登录访客 = 回传', Pixels::allowed() );
wp_set_current_user( $cust->ID ); $ok( 'Pixels::allowed：顾客 = 回传', Pixels::allowed() );
wp_set_current_user( $buyer->ID ); $ok( 'Pixels::allowed：投手 = 不回传', ! Pixels::allowed() );
wp_set_current_user( $admin->ID ); $ok( 'Pixels::allowed：管理员 = 不回传', ! Pixels::allowed() );
wp_set_current_user( 0 );
// 3. 订单路径：付款时回传、补发任务
$pending = static fn(): int => count( as_get_scheduled_actions( array( 'hook' => Server::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ), 'ids' ) );
$product = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'type' => 'simple' ) ); $product = $product ? $product[0] : null;
$make = static function ( bool $test ) use ( $product, $host ) {
	$o = wc_create_order(); if ( $product ) { $o->add_product( $product, 1 ); }
	$v = $test ? '1' : null;
	$addr = array( 'first_name' => $v ?? 'Sandbox', 'last_name' => $v ?? 'Check', 'address_1' => $v ?? '100 Test St', 'city' => $v ?? 'Testville', 'state' => $test ? '1' : 'TX', 'postcode' => $v ?? '77001', 'country' => 'US', 'phone' => $v ?? '5550100', 'email' => 'f1-hotfix-check@example.com' );
	$o->set_address( $addr, 'billing' ); unset( $addr['email'], $addr['phone'] ); $o->set_address( $addr, 'shipping' );
	$o->set_payment_method( 'cod' ); $o->calculate_totals();
	$o->update_meta_data( Server::ORDER_CTX, array( 'host' => $host, 'ip' => '198.18.0.1', 'ua' => 'F1-sandbox-check', 'url' => home_url( '/' ) ) );
	$o->update_meta_data( '_f1_hotfix_check', '1' ); $o->save();
	return $o;
};
$n = $make( false ); $t = $make( true );
$ok( '前提：普通单不是测试单、全 1 的是测试单', ! Test_Orders::is_test_order( $n ) && Test_Orders::is_test_order( $t ) && Pixels::is_test_order( $t ) );
$p0 = $pending(); $n->update_status( 'processing' ); $n = wc_get_order( $n->get_id() ); $p1 = $pending();
$ok( '付款时回传：普通单 → 打上已回传标记，后台队列 +1', '' !== (string) $n->get_meta( Server::ORDER_SENT ) && 1 === $p1 - $p0, '队列 ' . $p0 . '→' . $p1 );
$t->update_status( 'processing' ); $t = wc_get_order( $t->get_id() ); $p2 = $pending();
$ok( '付款时回传：测试单 → 不标记，后台队列不变', '' === (string) $t->get_meta( Server::ORDER_SENT ) && $p2 === $p1, '队列 ' . $p1 . '→' . $p2 );
$c = $make( false ); remove_action( 'woocommerce_order_status_changed', array( Pixels::class, 'on_order_status' ), 20 );
$c->update_status( 'processing' ); $c->set_date_paid( time() ); $c->save(); $c = wc_get_order( $c->get_id() );
$ok( '前提：模拟“付款时漏发”的订单没有已回传标记，付款时间是刚才', '' === (string) $c->get_meta( Server::ORDER_SENT ) && $c->get_date_paid() && $c->get_date_paid()->getTimestamp() > time() - 60 );
$found = wc_get_orders( array( 'status' => array( 'processing' ), 'date_paid' => '>' . ( time() - 6 * DAY_IN_SECONDS ), 'limit' => 50, 'return' => 'ids' ) );
$ok( '前提：补发任务的查询能查到这张订单（所以不补是因为 10 分钟缓冲）', in_array( $c->get_id(), $found, true ) );
$q0 = $pending(); Pixels::purchase_catchup(); $c = wc_get_order( $c->get_id() ); $t = wc_get_order( $t->get_id() ); $q1 = $pending();
$ok( '补发：付款不到 10 分钟的订单不补', '' === (string) $c->get_meta( Server::ORDER_SENT ) && $q1 === $q0, '队列 ' . $q0 . '→' . $q1 );
$c->set_date_paid( time() - 1200 ); $c->save(); $t->set_date_paid( time() - 1200 ); $t->save();
Pixels::purchase_catchup(); $c = wc_get_order( $c->get_id() ); $t = wc_get_order( $t->get_id() ); $q2 = $pending();
$ok( '补发：付款 20 分钟的普通单补发一次', '' !== (string) $c->get_meta( Server::ORDER_SENT ) && 1 === $q2 - $q1, '队列 ' . $q1 . '→' . $q2 );
$ok( '补发：付款 20 分钟的测试单仍然不发', '' === (string) $t->get_meta( Server::ORDER_SENT ) );
Pixels::purchase_catchup(); $q3 = $pending();
$ok( '补发：再跑一次不重复', $q3 === $q2, '队列 ' . $q2 . '→' . $q3 );
echo 'IDS ', $n->get_id(), ' ', $t->get_id(), "\n";
echo 'URLN ', $n->get_checkout_order_received_url(), "\nURLT ", $t->get_checkout_order_received_url(), "\n";
