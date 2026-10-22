<?php
/**
 * 公开优惠码（COUPON-01）冒烟测试：公开条件、列表 / 详情页、分享链接自动使用、菜单链接。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-coupons.php
 * 测试创建的优惠券、菜单和（如果原来没有的）优惠码页面在结束时彻底删除，购物车清空。不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Coupons;
use PeptideCore\Menus;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

$had_page = Coupons::page_id();
$made     = array();
$make     = static function ( string $code, array $props, bool $public ) use ( &$made ): int {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_props( $props );
	$id     = $c->save();
	$made[] = $id;
	update_post_meta( $id, Coupons::PUBLIC_META, $public ? 'yes' : 'no' );
	return $id;
};

$a = $make( 'SMOKEA10', array( 'discount_type' => 'percent', 'amount' => 10, 'minimum_amount' => '50', 'date_expires' => time() + 30 * DAY_IN_SECONDS, 'usage_limit' => 100 ), true );
$b = $make( 'SMOKEBMAIL', array( 'discount_type' => 'fixed_cart', 'amount' => 5, 'email_restrictions' => array( 'someone@example.com' ) ), true );
$c = $make( 'SMOKECOLD', array( 'discount_type' => 'fixed_cart', 'amount' => 5, 'date_expires' => time() - DAY_IN_SECONDS ), true );
$d = $make( 'SMOKEDPRIV', array( 'discount_type' => 'fixed_cart', 'amount' => 3 ), false );
$e = $make( 'SMOKEEUSED', array( 'discount_type' => 'fixed_cart', 'amount' => 5, 'usage_limit' => 1, 'usage_count' => 1 ), true );
$f = $make( 'SMOKEFLINK', array( 'discount_type' => 'fixed_cart', 'amount' => 2 ), false );
update_post_meta( $a, Coupons::TITLE_META, 'Ten percent for testing' );

// 1. 公开条件。
$codes = array_column( Coupons::public_list(), 'code' );
$ok( 'public coupon listed', in_array( 'SMOKEA10', $codes, true ) );
$ok( 'email-restricted coupon never listed', ! in_array( 'SMOKEBMAIL', $codes, true ) && 'emails' === Coupons::hidden_reason( new WC_Coupon( $b ) ) );
$ok( 'expired coupon not listed', ! in_array( 'SMOKECOLD', $codes, true ) && 'expired' === Coupons::hidden_reason( new WC_Coupon( $c ) ) );
$ok( 'private coupon not listed', ! in_array( 'SMOKEDPRIV', $codes, true ) && 'private' === Coupons::hidden_reason( new WC_Coupon( $d ) ) );
$ok( 'used-up coupon not listed', ! in_array( 'SMOKEEUSED', $codes, true ) && 'used_up' === Coupons::hidden_reason( new WC_Coupon( $e ) ) );
$view = Coupons::get_public( 'smokea10' );
$ok( 'lookup is case-insensitive', is_array( $view ) && 'SMOKEA10' === $view['code'] );
$ok( 'view fields', '10% off' === $view['value'] && 'Ten percent for testing' === $view['title'] && 50.0 === $view['minimum_spend'] && null !== $view['expires'] && 100 === $view['uses_left'] );
$ok( 'view does not expose email restrictions', ! str_contains( wp_json_encode( Coupons::view( new WC_Coupon( $b ) ) ), 'someone@example.com' ) );
$ok( 'hidden coupon detail is null', null === Coupons::get_public( 'SMOKEDPRIV' ) && null === peptide_core_get_public_coupon( 'NOPE-NOPE' ) );

// 2. 后台保存：勾选公开时建好优惠码页面。
$_POST = array(
	Coupons::PUBLIC_META => 'yes',
	Coupons::TITLE_META  => ' <b>Three</b> off ',
	Coupons::TEXT_META   => 'For testing',
	Coupons::ORDER_META  => '5',
);
Coupons::save_fields( $d, new WC_Coupon( $d ) );
$_POST = array();
$ok( 'save marks public and cleans title', 'yes' === get_post_meta( $d, Coupons::PUBLIC_META, true ) && 'Three off' === get_post_meta( $d, Coupons::TITLE_META, true ) );
$ok( 'coupons page exists and is published', Coupons::page_id() && 'publish' === get_post_status( Coupons::page_id() ) );
$ok( 'detail URL on the coupons page', str_contains( Coupons::detail_url( 'SMOKEA10' ), 'code=SMOKEA10' ) && str_starts_with( Coupons::detail_url( 'SMOKEA10' ), get_permalink( Coupons::page_id() ) ) );
$ok( 'share link has the apply argument', str_contains( Coupons::apply_url( 'SMOKEA10' ), 'pc_coupon=SMOKEA10' ) );

// 后台表单函数（woocommerce_wp_checkbox 等）只在后台加载，命令行里手动引入。
require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
ob_start();
Coupons::render_fields( $a, new WC_Coupon( $a ) );
$fields = ob_get_clean();
$ok( 'admin panel shows the share link', str_contains( $fields, 'pc_coupon=SMOKEA10' ) );
ob_start();
Coupons::render_fields( $b, new WC_Coupon( $b ) );
$fields = ob_get_clean();
$ok( 'admin panel explains why a coupon is not listed', str_contains( $fields, 'specific emails' ) );

// 3. 前台页面。
$list = do_shortcode( '[pc_coupons]' );
$ok( 'list page shows public coupons only', str_contains( $list, 'SMOKEA10' ) && str_contains( $list, 'SMOKEDPRIV' ) && ! str_contains( $list, 'SMOKEBMAIL' ) && ! str_contains( $list, 'SMOKECOLD' ) );
$ok( 'list has copy buttons', str_contains( $list, 'data-pc-copy="SMOKEA10"' ) );
$_GET['code'] = 'SMOKEA10';
$detail       = do_shortcode( '[pc_coupons]' );
$_GET['code'] = 'SMOKECOLD';
$gone         = do_shortcode( '[pc_coupons]' );
unset( $_GET['code'] );
$ok( 'detail page shows conditions and apply link', str_contains( $detail, 'Minimum spend' ) && str_contains( $detail, 'pc_coupon=SMOKEA10' ) );
$ok( 'ended coupon detail says it is not available', str_contains( $gone, 'not available' ) && ! str_contains( $gone, 'SMOKECOLD' ) );

// 4. 分享链接：购物车为空时先记下，满足最低消费后自动使用。
wc_load_cart();
WC()->cart->empty_cart();
$product = null;
foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 20 ) ) as $p ) {
	if ( (float) $p->get_price() > 0 && $p->is_purchasable() && $p->is_in_stock() ) {
		$product = $p;
		break;
	}
}
if ( ! $product ) {
	foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'variable', 'limit' => 10 ) ) as $p ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && (float) $v->get_price() > 0 && $v->is_purchasable() && $v->is_in_stock() ) {
				$product = $v;
				break 2;
			}
		}
	}
}
$ok( 'found a purchasable product', (bool) $product );
if ( $product ) {
	$add = static function ( int $qty ) use ( $product ) {
		return $product->get_parent_id() ? WC()->cart->add_to_cart( $product->get_parent_id(), $qty, $product->get_id(), $product->get_attributes() ) : WC()->cart->add_to_cart( $product->get_id(), $qty );
	};
	$ok( 'empty cart: code is kept for later', 'pending' === Coupons::remember( 'SMOKEA10' ) && ! WC()->cart->has_discount( 'smokea10' ) );
	$price  = (float) $product->get_price();
	$under  = max( 1, (int) floor( 49 / $price ) );
	if ( $price * $under < 50 ) {
		$add( $under );
		WC()->cart->calculate_totals();
		$ok( 'below minimum spend: still waiting', ! WC()->cart->has_discount( 'smokea10' ) && 'SMOKEA10' === strtoupper( (string) WC()->session->get( 'pc_pending_coupon' ) ) );
	}
	$add( (int) ceil( 60 / $price ) );
	WC()->cart->calculate_totals();
	$ok( 'minimum reached: coupon applied automatically', WC()->cart->has_discount( 'smokea10' ) );
	$ok( 'pending code cleared after use', ! WC()->session->get( 'pc_pending_coupon' ) );
	$ok( 'expired code is not kept', 'invalid' === Coupons::remember( 'SMOKECOLD' ) && ! WC()->session->get( 'pc_pending_coupon' ) );
	$ok( 'used-up code is refused', 'applied' !== Coupons::remember( 'SMOKEEUSED' ) && ! WC()->cart->has_discount( 'smokeeused' ) );
	$ok( 'unlisted but valid code works through a link', 'applied' === Coupons::remember( 'SMOKEFLINK' ) && WC()->cart->has_discount( 'smokeflink' ) );
}
WC()->cart->empty_cart();
wc_clear_notices();

// 5. 菜单链接。
$m     = Menus::create( 'SMOKE coupon menu' );
$saved = Menus::save(
	$m,
	array(
		'name'     => 'SMOKE coupon menu',
		'location' => '',
		'items'    => array(
			array( 'title' => 'Offers', 'link' => array( 'type' => 'coupons' ) ),
			array( 'title' => '', 'link' => array( 'type' => 'coupon', 'id' => $a ) ),
			array( 'title' => 'Mail', 'link' => array( 'type' => 'coupon', 'id' => $b ) ),
		),
	)
);
$ok( 'coupon links saved, name defaults to the code', is_array( $saved ) && 'SMOKEA10' === $saved['items'][1]['title'] && 'coupon' === $saved['items'][1]['link']['type'] && $a === $saved['items'][1]['link']['id'] );
$ok( 'unlisted coupon flagged in the editor', ! empty( $saved['items'][2]['link']['broken'] ) );
$opts = Menus::link_options();
$ok( 'editor offers public coupons', in_array( $a, array_column( $opts['coupon'], 'id' ), true ) && ! in_array( $b, array_column( $opts['coupon'], 'id' ), true ) );
$prev = get_theme_mod( 'nav_menu_locations', array() );
Menus::set_location( $m, 'footer' );
$tree = Menus::public_tree( 'footer' );
$ok( 'coupons page link resolves', Coupons::list_url() === $tree[0]['url'] );
$ok( 'coupon detail link resolves', isset( $tree[1] ) && str_contains( $tree[1]['url'], 'code=SMOKEA10' ) );
$ok( 'unlisted coupon hidden on the storefront', 2 === count( $tree ) );
$export = Menus::export();
$mine   = array_values( array_filter( $export['menus'], static fn( $x ) => 'SMOKE coupon menu' === $x['name'] ) )[0];
$ok( 'export records the coupon code', 'SMOKEA10' === $mine['items'][1]['link']['code'] );
set_theme_mod( 'nav_menu_locations', $prev );
Menus::delete( $m );

// 6. 清理。
foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
if ( ! $had_page && Coupons::page_id() ) {
	wp_delete_post( Coupons::page_id(), true );
	delete_option( Coupons::PAGE_OPTION );
}
$ok( 'test coupons deleted', ! array_filter( array_map( 'get_post', $made ) ) );
$ok( 'menu locations restored', get_theme_mod( 'nav_menu_locations', array() ) === $prev );
