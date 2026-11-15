<?php
// 热修复包 A 的沙盒验证：清理。删掉带标记的测试单、待发队列、临时顾客，还原像素设置，清页面缓存。
if ( ! defined( 'VIALCOVE_SANDBOX' ) || ! VIALCOVE_SANDBOX ) { exit( 1 ); }
use PeptideCore\Pixels; use PeptideCore\Pixels\Server;
$n = 0; foreach ( wc_get_orders( array( 'meta_key' => '_f1_hotfix_check', 'meta_value' => '1', 'limit' => 50, 'status' => 'any' ) ) as $o ) { $o->delete( true ); $n++; }
$a = 0; foreach ( as_get_scheduled_actions( array( 'hook' => Server::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ), 'ids' ) as $id ) { ActionScheduler::store()->delete_action( $id ); $a++; }
require_once ABSPATH . 'wp-admin/includes/user.php'; $u = get_user_by( 'login', 'f1_hv_customer' ); if ( $u ) { wp_delete_user( $u->ID ); }
$b = get_option( 'f1_hv_px_backup' ); if ( is_array( $b ) ) { if ( $b['had'] ) { update_option( Pixels::OPTION, $b['value'] ); } else { delete_option( Pixels::OPTION ); } delete_option( 'f1_hv_px_backup' ); }
if ( class_exists( 'PeptideCore\Page_Cache' ) && method_exists( 'PeptideCore\Page_Cache', 'flush' ) ) { PeptideCore\Page_Cache::flush(); }
echo "清理：测试订单 $n 张、待发队列 $a 条已删；临时顾客已删；像素设置已还原（any=", (int) Pixels::any(), "）\n";
