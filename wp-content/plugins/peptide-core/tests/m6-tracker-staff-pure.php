<?php
/**
 * 热修复 A：员工角色不被访客追踪（Tracking\Tracker::is_staff()）。加载真正的 Tracker，纯逻辑：php -n m6-tracker-staff-pure.php
 *
 * 背景：员工角色（销售账号、投手）的店铺权限是进后台时按请求临时给的，在前台没有 manage_woocommerce。
 * 只按权限判断时，员工在前台浏览会被记成访客，他们下的单也会带上访问、进漏斗。现在另外按员工角色认。
 * 像素那一半（Pixels::allowed()）的用例在 m7-pixels-pure.php。
 */

namespace PeptideCore {
	/** 员工角色的桩：$ids 里的用户编号带员工角色。 */
	final class Staff {
		public static array $ids = array();
		public static function is_staff( $user ): bool { return in_array( (int) $user, self::$ids, true ); }
	}
}

namespace {
	require __DIR__ . '/m6-stubs.php';

	/** 前台请求里每个用户有哪些权限。 */
	$GLOBALS['t_caps'] = array();
	function user_can( $user, $cap ) { return in_array( $cap, $GLOBALS['t_caps'][ (int) $user ] ?? array(), true ); }

	require dirname( __DIR__ ) . '/includes/tracking/class-tracker.php';

	use PeptideCore\Staff;
	use PeptideCore\Tracking\Tracker;

	// 1 管理员、2 店铺管理员、3 销售账号（前台没有店铺权限）、4 投手（销售账号 + 投手角色，同样没有）、5 顾客。
	$GLOBALS['t_caps'] = array( 1 => array( 'manage_options', 'manage_woocommerce' ), 2 => array( 'manage_woocommerce' ), 3 => array( 'read' ), 4 => array( 'read' ), 5 => array( 'read' ) );
	Staff::$ids        = array( 3, 4 );

	m6_check( 'not logged in is never staff', false === Tracker::is_staff( 0 ) && false === Tracker::is_staff( -1 ) );
	m6_check( 'administrators and shop managers are staff, as before', true === Tracker::is_staff( 1 ) && true === Tracker::is_staff( 2 ) );
	m6_check( 'a sales account without store capabilities on the storefront is staff by its role', true === Tracker::is_staff( 3 ) );
	m6_check( 'a media buyer (sales account with the buyer role) is staff too', true === Tracker::is_staff( 4 ) );
	m6_check( 'a customer is not staff', false === Tracker::is_staff( 5 ) );
	m6_check( 'an unknown user is not staff', false === Tracker::is_staff( 99 ) );

	// 角色去掉之后恢复成普通访客；员工角色加上管理员权限也照样算员工。
	Staff::$ids = array( 4 );
	m6_check( 'once the role is removed the account is tracked like anyone else', false === Tracker::is_staff( 3 ) && true === Tracker::is_staff( 4 ) );
	Staff::$ids = array( 1, 4 );
	m6_check( 'the role plus admin capabilities is still staff', true === Tracker::is_staff( 1 ) );

	echo 'PASS ', $GLOBALS['m6_passed'], " tracker staff (hotfix A) assertions\n";
}
