<?php
/**
 * 2026-11-21：“广告数据追踪”隐藏开关。隐藏时四个页面只登记、不进菜单；关掉开关恢复原来的菜单。
 * 运行：php cr-hide-pure.php（不需要 WordPress、数据库、网络）。
 */
namespace PeptideCore { final class Staff_Buyer { public const CAP_VIEW = 'pc_view'; public const PAGE_ADS = 'pc-buyer-ads'; } }
namespace PeptideCore\Admin { final class Onboarding { public static function render_ads(): void {} } }
namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['pc_menu'] = array();
	$GLOBALS['pc_hide'] = null;
	function add_menu_page( $t, $m, $c, $slug ) { $GLOBALS['pc_menu'][] = array( 'top', $slug ); }
	function add_submenu_page( $parent, $t, $m, $c, $slug ) { $GLOBALS['pc_menu'][] = array( $parent, $slug ); }
	function apply_filters( $name, $v ) { return 'pc_adtrack_hidden' === $name && null !== $GLOBALS['pc_hide'] ? $GLOBALS['pc_hide'] : $v; }
	require dirname( __DIR__ ) . '/includes/creatives/class-module.php';
	use PeptideCore\Creatives\Module;
	$fail = 0; $n = 0;
	$check = static function ( string $name, bool $ok ) use ( &$fail, &$n ): void { $n++; if ( ! $ok ) { $fail++; echo "FAIL $name\n"; } };

	$check( 'hidden by default', true === Module::HIDDEN && Module::hidden() );
	Module::add_pages();
	$check( 'hidden: no top-level menu', array() === array_filter( $GLOBALS['pc_menu'], static fn( $r ) => 'top' === $r[0] ) );
	$check( 'hidden: four pages registered without a parent', array( array( '', 'pc-buyer-ads' ), array( '', 'pc-creatives' ), array( '', 'pc-creatives-spend' ), array( '', 'pc-creatives-board' ) ) === $GLOBALS['pc_menu'] );

	$GLOBALS['pc_menu'] = array();
	$GLOBALS['pc_hide'] = false;
	Module::add_pages();
	$check( 'shown: top-level menu back', array( 'top', 'pc-buyer-ads' ) === $GLOBALS['pc_menu'][0] );
	$check( 'shown: library under the menu', in_array( array( 'pc-buyer-ads', 'pc-creatives' ), $GLOBALS['pc_menu'], true ) );

	echo $fail ? "FAILED $fail of $n\n" : "PASS $n adtrack hide checks; no WordPress, database, or network.\n";
	exit( $fail ? 1 : 0 );
}
