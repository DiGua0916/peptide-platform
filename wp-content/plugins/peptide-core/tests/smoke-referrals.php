<?php
/**
 * 推广人归属冒烟测试（REF-01）。本机：wp eval-file wp-content/plugins/peptide-core/tests/smoke-referrals.php
 * 测试结束恢复推广人名单，删除测试用户和测试记录。
 */

use PeptideCore\Page_Cache;
use PeptideCore\Referrals;

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local CLI only.' ); }

$pass = 0; $fail = 0;
$ok = static function ( string $name, bool $cond ) use ( &$pass, &$fail ): void { if ( $cond ) { $pass++; echo "PASS {$name}\n"; } else { $fail++; echo "FAIL {$name}\n"; } };

global $wpdb;
$saved_owners = get_option( Referrals::OPTION, null );
$saved_cookie = $_COOKIE;
$saved_get    = $_GET;
$saved_ua     = $_SERVER['HTTP_USER_AGENT'] ?? null;
$users        = array();
$touch_before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . Referrals::table() );

try {
	// 名单。
	$ok( 'bad owner line is rejected', is_wp_error( Referrals::save_owners( "A B|x\n" ) ) && is_wp_error( Referrals::save_owners( "a|x\na|y" ) ) && is_wp_error( Referrals::save_owners( 'a|' ) ) );
	$ok( 'owners saved, code lowercased', true === Referrals::save_owners( "A|测试甲\nb2|测试乙\n" ) && array( 'a' => '测试甲', 'b2' => '测试乙' ) === Referrals::owners() );
	$ok( 'only listed codes are valid', 'a' === Referrals::valid_code( 'A' ) && null === Referrals::valid_code( 'zz' ) && null === Referrals::valid_code( array( 'a' ) ) && null === Referrals::valid_code( '<a>' ) );
	$ok( 'add owner validates code, name and duplicates', true === Referrals::add_owner( 'C3', '丙' ) && 'c3' === Referrals::valid_code( 'c3' ) && is_wp_error( Referrals::add_owner( 'c3', '重复' ) ) && is_wp_error( Referrals::add_owner( 'bad code', 'x' ) ) && is_wp_error( Referrals::add_owner( 'd4', ' ' ) ) );
	$ok( 'rename keeps the code, remove drops it', true === Referrals::rename_owner( 'c3', '丙二' ) && '丙二' === Referrals::name( 'c3' ) && is_wp_error( Referrals::rename_owner( 'zz', 'x' ) ) && true === Referrals::remove_owner( 'c3' ) && null === Referrals::valid_code( 'c3' ) && is_wp_error( Referrals::remove_owner( 'c3' ) ) );
	$ok( 'link carries ref', str_ends_with( Referrals::link( 'a' ), '?ref=a' ) && str_contains( Referrals::link( 'b2', home_url( '/shop/' ) ), '/shop/?ref=b2' ) );

	// 页面缓存把 ref 当作广告参数。
	$ok( 'page cache treats ref as a tracking tag', Page_Cache::tracking_only( 'ref=a' ) && Page_Cache::tracking_only( 'utm_source=fb&ref=a' ) && array( '', array( 'ref=a' ) ) === Page_Cache::split_tracking( 'ref=a' ) );

	// cookie 签名。
	$ts = time();
	$ok( 'cookie parse + signature', null !== Referrals::parse( 'a.' . $ts . '.' . Referrals::sign( 'a', $ts ) ) && null === Referrals::parse( 'a.' . $ts ) && null === Referrals::parse( '../x.1.2' ) );
	$_COOKIE[ Referrals::COOKIE ] = 'a.' . $ts . '.' . str_repeat( '0', 20 );
	$ok( 'forged cookie is ignored', null === Referrals::from_cookie() );

	// 第一次进站写 cookie，后来的代号不覆盖。
	unset( $_COOKIE[ Referrals::COOKIE ] );
	wp_set_current_user( 0 );
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)';
	$ua = $_SERVER['HTTP_USER_AGENT'];
	Referrals::land( 'zz', $ua );
	$ok( 'unknown code sets nothing', ! isset( $_COOKIE[ Referrals::COOKIE ] ) );
	Referrals::land( 'A', $ua );
	$first = Referrals::from_cookie();
	$ok( 'first valid landing sets the owner cookie', $first && 'a' === $first['code'] );
	$ok( 'later landing reports no new owner', false === Referrals::land( 'b2', $ua ) );
	$ok( 'later link does not replace the first owner', 'a' === ( Referrals::from_cookie()['code'] ?? '' ) );
	$ok( 'one visitor touch recorded', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Referrals::table() . ' WHERE id > %d', $touch_before ) ) );
	$cookie_a = $_COOKIE[ Referrals::COOKIE ];
	unset( $_COOKIE[ Referrals::COOKIE ] );
	Referrals::land( 'a', 'facebookexternalhit/1.1' );
	$ok( 'crawlers are ignored', ! isset( $_COOKIE[ Referrals::COOKIE ] ) );
	$_COOKIE[ Referrals::COOKIE ] = $cookie_a;

	// 注册继承 cookie 的归属。
	$u1 = wp_insert_user( array( 'user_login' => 'pc_ref_t1_' . wp_generate_password( 6, false ), 'user_email' => 'pc-ref-t1-' . wp_generate_password( 6, false ) . '@example.test', 'user_pass' => wp_generate_password() ) );
	$users[] = $u1;
	$ok( 'registration inherits the cookie owner', 'a' === get_user_meta( $u1, Referrals::USER_META, true ) && (int) get_user_meta( $u1, Referrals::USER_AT, true ) > 0 );

	// 已有归属的账号换浏览器（带 b2 的 cookie）登录，不变。
	$tb = time();
	$_COOKIE[ Referrals::COOKIE ] = 'b2.' . $tb . '.' . Referrals::sign( 'b2', $tb );
	Referrals::bind_user( $u1 );
	$ok( 'existing account owner is kept', 'a' === get_user_meta( $u1, Referrals::USER_META, true ) );

	// 订单：账号归属优先于 cookie。
	$o1 = new WC_Order();
	$o1->set_customer_id( $u1 );
	Referrals::stamp_order( $o1 );
	$ok( 'order takes the account owner over the cookie', 'a' === $o1->get_meta( Referrals::ORDER_META ) );

	// 游客订单用 cookie。
	$o2 = new WC_Order();
	Referrals::stamp_order( $o2 );
	$ok( 'guest order takes the cookie owner', 'b2' === $o2->get_meta( Referrals::ORDER_META ) );

	// 没有 cookie、没有账号归属 = 无归属。
	unset( $_COOKIE[ Referrals::COOKIE ] );
	$o3 = new WC_Order();
	Referrals::stamp_order( $o3 );
	$ok( 'no cookie and no account owner leaves the order unassigned', '' === (string) $o3->get_meta( Referrals::ORDER_META ) );

	// 已有归属的订单不被改。
	$_COOKIE[ Referrals::COOKIE ] = 'b2.' . $tb . '.' . Referrals::sign( 'b2', $tb );
	$o4 = new WC_Order();
	$o4->update_meta_data( Referrals::ORDER_META, 'a' );
	Referrals::stamp_order( $o4 );
	$ok( 'stamped order keeps its owner', 'a' === $o4->get_meta( Referrals::ORDER_META ) );

	// 手动改归属。
	$ok( 'manual reassign validates codes', Referrals::set_user_owner( $u1, 'b2' ) && 'b2' === get_user_meta( $u1, Referrals::USER_META, true ) && ! Referrals::set_user_owner( $u1, 'zz' ) && Referrals::set_user_owner( $u1, '' ) && '' === get_user_meta( $u1, Referrals::USER_META, true ) );

	// 删除名单里的代号后，cookie 不再生效。
	Referrals::save_owners( 'a|测试甲' );
	$ok( 'removed code no longer counts', null === Referrals::from_cookie() );

	// 统计能跑。
	$s = Referrals::stats( 30 );
	$ok( 'stats return every listed owner', isset( $s['a'] ) && $s['a']['visitors'] >= 1 && array_key_exists( 'sales', $s['a'] ) );
} finally {
	foreach ( $users as $id ) { if ( $id && ! is_wp_error( $id ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( (int) $id ); } }
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Referrals::table() . ' WHERE id > %d', $touch_before ) );
	null === $saved_owners ? delete_option( Referrals::OPTION ) : update_option( Referrals::OPTION, $saved_owners, true );
	$_COOKIE = $saved_cookie; $_GET = $saved_get;
	if ( null === $saved_ua ) { unset( $_SERVER['HTTP_USER_AGENT'] ); } else { $_SERVER['HTTP_USER_AGENT'] = $saved_ua; }
}
echo "Referrals: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { exit( 1 ); }
