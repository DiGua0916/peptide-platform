<?php
/**
 * ST-05：数据子页对投手合并成两页（漏斗 + 来源、商品 + 落地页），管理员仍然看到全部。
 * 纯逻辑，加载真正的 Admin\Data_Pages 和 Data\Audience：php -n m6-buyer-data-pages-pure.php
 */
require __DIR__ . '/m6-stubs.php';

final class WP_User {
	public function __construct( public int $ID, public array $roles = array(), public array $caps = array() ) {}
	public function exists(): bool { return $this->ID > 0; }
}
function wp_get_current_user() { return $GLOBALS['t_user']; }
function get_userdata( $id ) { return $GLOBALS['t_users'][ (int) $id ] ?? false; }
function user_can( $user, $cap ) { return $user instanceof WP_User && in_array( $cap, $user->caps, true ); }
function current_user_can( $cap ) { return user_can( wp_get_current_user(), $cap ); }
function get_user_locale() { return $GLOBALS['t_locale']; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_unslash( $value ) { return $value; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_url( $url ) { return (string) $url; }
function admin_url( $path = '' ) { return 'https://store.test/wp-admin/' . $path; }
function add_query_arg( array $args, string $url ) { return $url . '?' . http_build_query( $args ); }
function add_menu_page( ...$args ) { $GLOBALS['t_menu'][] = $args[3]; }
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $callback = null ) { $GLOBALS['t_submenu'][ $slug ] = $menu_title; }
function remove_submenu_page( $parent, $slug ) { $GLOBALS['t_removed'][] = $slug; }
function get_edit_post_link( $id, $context = 'display' ) { return 'https://store.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }

require dirname( __DIR__ ) . '/includes/data/class-audience.php';
require dirname( __DIR__ ) . '/includes/admin/class-data-pages.php';

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Data\Audience;

$admin = new WP_User( 1, array( 'administrator' ), array( 'manage_options', 'manage_woocommerce' ) );
$buyer = new WP_User( 2, array( 'pc_media_buyer' ), array( 'manage_woocommerce' ) );
$GLOBALS['t_users']  = array( 1 => $admin, 2 => $buyer, 3 => new WP_User( 3, array( 'pc_media_buyer', 'administrator' ), array( 'manage_options', 'manage_woocommerce' ) ), 4 => new WP_User( 4, array( 'shop_manager' ), array( 'manage_woocommerce' ) ) );
$GLOBALS['t_locale'] = 'zh_CN';
$menus  = static function (): void {
	$GLOBALS['t_menu']    = array();
	$GLOBALS['t_submenu'] = array();
	$GLOBALS['t_removed'] = array();
	$GLOBALS['m6_actions'] = array();
	Data_Pages::add_menu();
	foreach ( $GLOBALS['m6_actions']['admin_head'] ?? array() as $callback ) { $callback(); }
};
$render = static function ( array $get ): string {
	$_GET = $get;
	ob_start();
	Data_Pages::render();
	return (string) ob_get_clean();
};

// ---------- 谁是投手 ----------
$GLOBALS['t_user'] = $buyer;
m6_check( 'media buyer role without manage_options is a buyer', Audience::is_media_buyer() && Audience::is_media_buyer( 2 ) );
m6_check( 'administrators and shop managers are not buyers', ! Audience::is_media_buyer( 1 ) && ! Audience::is_media_buyer( 4 ) );
m6_check( 'the role plus manage_options still sees everything', ! Audience::is_media_buyer( 3 ) );
m6_check( 'unknown or logged-out users are not buyers', ! Audience::is_media_buyer( 99 ) );
add_filter( 'peptide_core_is_media_buyer', static fn( $is, $user_id ) => 4 === $user_id ? true : $is );
m6_check( 'the filter can rewrite the decision', Audience::is_media_buyer( 4 ) && ! Audience::is_media_buyer( 1 ) );
$GLOBALS['m6_filters'] = array();

// 报表里商品名上的编辑链接：用线上原有的 get_edit_post_link()，WordPress 自己按权限给投手返回空（本机实测），不另写一份（11-13 所有者定的原则）。
$GLOBALS['t_user'] = $buyer;

// ---------- 管理员：菜单和以前一样 ----------
$GLOBALS['t_user'] = $admin;
$menus();
m6_check( 'admin: the top-level menu is still pc-data', array( 'pc-data' ) === $GLOBALS['t_menu'] );
m6_check( 'admin: all twelve pages are registered in the same order', array_values( Data_Pages::PAGES ) === array_slice( array_keys( $GLOBALS['t_submenu'] ), 0, 12 ) );
m6_check( 'admin: the two merged pages are reachable but hidden from the menu', array( 'pc-data-sources', 'pc-data-dimensions', 'pc-data-funnel-sources', 'pc-data-products-landing' ) === $GLOBALS['t_removed'] && 15 === count( $GLOBALS['t_submenu'] ) && isset( $GLOBALS['t_submenu']['pc-data-pages-landing'] ) );

// 数据栏目第 1 步清理：菜单只留五项，三个页面挂到别的菜单下。
$GLOBALS['submenu'] = array( 'pc-promotions' => array( array( 'Spend-and-save', 'manage_woocommerce', 'pc-promotions' ) ), 'pc-mail' => array( array( '发件箱', 'manage_woocommerce', 'pc-mail' ) ), 'pc-media' => array( array( 'Media library', 'manage_woocommerce', 'pc-media' ) ), 'pc-data' => array() );
foreach ( array( 'pc-data-ai', 'pc-data-visit-classifier', 'pc-data-review', 'pc-data-cockpit' ) as $extra ) { $GLOBALS['t_submenu'][ $extra ] = $extra; }
foreach ( $GLOBALS['t_submenu'] as $slug => $title ) { $GLOBALS['submenu']['pc-data'][] = array( $title, 'manage_woocommerce', $slug ); }
Data_Pages::tidy_admin_menu();
m6_check( 'admin: the data menu keeps five entries in order', array( 'pc-data-cockpit', 'pc-data-review', 'pc-data-products', 'pc-data-pages-landing', 'pc-data-ai' ) === array_column( $GLOBALS['submenu']['pc-data'], 2 ) );
m6_check( 'admin: app statistics, email data and visit classification move to other menus', 'admin.php?page=pc-data-apps' === end( $GLOBALS['submenu']['pc-promotions'] )[2] && 'admin.php?page=pc-data-emails' === end( $GLOBALS['submenu']['pc-mail'] )[2] && 'admin.php?page=pc-data-visit-classifier' === end( $GLOBALS['submenu']['pc-media'] )[2] );
$_GET = array( 'page' => 'pc-data-emails' );
m6_check( 'admin: a moved page highlights its new menu', 'pc-mail' === Data_Pages::parent_file( 'pc-data' ) && 'admin.php?page=pc-data-emails' === Data_Pages::submenu_file( 'x' ) );
$_GET = array( 'page' => 'pc-data-experiments' );
m6_check( 'admin: hidden A/B page still opens and highlights the review page', 'experiments' === Data_Pages::current_key() && 'pc-data-review' === Data_Pages::submenu_file( 'x' ) );
$_GET = array( 'page' => 'pc-data-operations' );
m6_check( 'admin: the old operations page now sits under the review page', 'pc-data-review' === Data_Pages::submenu_file( 'x' ) );
$_GET = array( 'page' => 'pc-data' );
m6_check( 'admin: the realtime page now sits under the cockpit', 'realtime' === Data_Pages::current_key() && 'pc-data-cockpit' === Data_Pages::submenu_file( 'x' ) );
foreach ( array( '' => 'landing', 'pages' => 'pages', 'page_conversion' => 'page_conversion' ) as $tab => $key ) {
	$_GET = array_filter( array( 'page' => 'pc-data-pages-landing', 'tab' => $tab ) );
	m6_check( "admin: pages-and-landing tab '{$tab}' shows the {$key} report", $key === Data_Pages::current_key() );
}
$html = $render( array( 'page' => 'pc-data-pages-landing', 'tab' => 'pages' ) );
m6_check( 'admin: pages-and-landing renders three tabs', str_contains( $html, 'data-page="pages"' ) && str_contains( $html, '>落地页</a>' ) && str_contains( $html, 'nav-tab-active" aria-current="page" href="https://store.test/wp-admin/admin.php?page=pc-data-pages-landing&tab=pages">页面访问</a>' ) && str_contains( $html, '>页面商品转化</a>' ) );
$_GET = array( 'page' => 'pc-data-landing' );
m6_check( 'admin: the old landing page url highlights pages-and-landing', 'pc-data-pages-landing' === Data_Pages::submenu_file( 'x' ) );
$_GET = array( 'page' => 'pc-data-products' );
m6_check( 'admin: an ordinary page keeps its key', 'products' === Data_Pages::current_key() );
m6_check( 'admin: ordinary page markup is byte-identical to before', '<div class="wrap pc-data-wrap"><h1 class="screen-reader-text">Product details data</h1><div id="pc-data" class="pc-data" data-page="products"><p>Loading…</p></div></div>' === $render( array( 'page' => 'pc-data-products' ) ) );
m6_check( 'admin: realtime is still the landing page', str_contains( $render( array( 'page' => 'pc-data' ) ), 'data-page="realtime"' ) );
$_GET = array( 'page' => 'pc-data-sources' );
m6_check( 'admin: hidden source pages highlight the review page', 'pc-data-review' === Data_Pages::submenu_file( 'x' ) );
$_GET = array( 'page' => 'pc-data-funnel-sources', 'tab' => 'sources' );
m6_check( 'merged pages never borrow another menu highlight', 'x' === Data_Pages::submenu_file( 'x' ) );
m6_check( 'not a data page', null === ( function () { $_GET = array( 'page' => 'wc-orders' ); return Data_Pages::current_key(); } )() );

// ---------- 投手：只有两页 ----------
$GLOBALS['t_user'] = $buyer;
$menus();
m6_check( 'buyer: the top-level menu keeps the slug pc-data', array( 'pc-data' ) === $GLOBALS['t_menu'] );
m6_check( 'buyer: exactly two pages, named in Chinese', array( 'pc-data-funnel-sources' => '漏斗 + 来源', 'pc-data-products-landing' => '商品 + 落地页' ) === $GLOBALS['t_submenu'] );
m6_check( 'buyer: the automatic parent entry and admin tools are hidden', array( 'pc-data', 'pc-data-visit-classifier' ) === $GLOBALS['t_removed'] );

$tabs = array(
	array( 'pc-data-funnel-sources', '', 'conversion' ),
	array( 'pc-data-funnel-sources', 'funnel', 'conversion' ),
	array( 'pc-data-funnel-sources', 'sources', 'sources' ),
	array( 'pc-data-funnel-sources', 'nonsense', 'conversion' ),
	array( 'pc-data-products-landing', '', 'products' ),
	array( 'pc-data-products-landing', 'landing', 'landing' ),
);
foreach ( $tabs as [ $page, $tab, $key ] ) {
	$_GET = array_filter( array( 'page' => $page, 'tab' => $tab ) );
	m6_check( "buyer: {$page} tab '{$tab}' shows the {$key} report", $key === Data_Pages::current_key() );
}

// 默认是页签布局（线上现在的样子）；这里仍然明确把选项设成 tabs，下面再看没有选项时的默认值。
$GLOBALS['m6_options'][ Data_Pages::BUYER_LAYOUT_OPTION ] = 'tabs';
$html = $render( array( 'page' => 'pc-data-funnel-sources' ) );
m6_check( 'buyer: merged page renders the existing report container', str_contains( $html, '<div id="pc-data" class="pc-data" data-page="conversion">' ) );
m6_check( 'buyer: merged page has both tabs, the first one active', str_contains( $html, '<a class="nav-tab nav-tab-active" aria-current="page" href="https://store.test/wp-admin/admin.php?page=pc-data-funnel-sources&tab=funnel">漏斗</a>' ) && str_contains( $html, '<a class="nav-tab" href="https://store.test/wp-admin/admin.php?page=pc-data-funnel-sources&tab=sources">来源（按广告）</a>' ) );
$html = $render( array( 'page' => 'pc-data-products-landing', 'tab' => 'landing' ) );
m6_check( 'buyer: second merged page switches report by tab', str_contains( $html, 'data-page="landing"' ) && str_contains( $html, 'nav-tab-active" aria-current="page" href="https://store.test/wp-admin/admin.php?page=pc-data-products-landing&tab=landing">落地页</a>' ) && str_contains( $html, '>商品</a>' ) );

// 同屏布局（选项 peptide_core_buyer_data_layout = stacked）：不出页签，容器不变；tab 仍然决定“当前是哪个报表”（外壳脚本用它滚到那一段）。
$GLOBALS['m6_options'][ Data_Pages::BUYER_LAYOUT_OPTION ] = 'stacked';
$html = $render( array( 'page' => 'pc-data-funnel-sources', 'tab' => 'sources' ) );
m6_check( 'buyer stacked: no tabs, same container', ! str_contains( $html, 'nav-tab' ) && str_contains( $html, '<div id="pc-data" class="pc-data" data-page="sources">' ) );
m6_check( 'buyer stacked: the tab still names the report to focus', 'sources' === Data_Pages::current_key() );
$GLOBALS['m6_options'][ Data_Pages::BUYER_LAYOUT_OPTION ] = 'something-else';
m6_check( 'an unknown layout value means the default (tabs)', str_contains( $render( array( 'page' => 'pc-data-funnel-sources' ) ), 'nav-tab-active' ) );
unset( $GLOBALS['m6_options'][ Data_Pages::BUYER_LAYOUT_OPTION ] );
m6_check( 'without the option the layout is tabs, as it is in production', str_contains( $render( array( 'page' => 'pc-data-funnel-sources' ) ), 'nav-tab-active' ) && str_contains( $render( array( 'page' => 'pc-data-funnel-sources' ) ), 'id="pc-data"' ) );
$GLOBALS['m6_options'][ Data_Pages::BUYER_LAYOUT_OPTION ] = 'tabs';
m6_check( 'the tabs layout can also be set explicitly', str_contains( $render( array( 'page' => 'pc-data-funnel-sources' ) ), 'nav-tab-active' ) );
unset( $GLOBALS['m6_options'][ Data_Pages::BUYER_LAYOUT_OPTION ] );

$html = $render( array( 'page' => 'pc-data' ) );
m6_check( 'buyer: the admin pages-and-landing page is not a report for buyers', ! str_contains( $render( array( 'page' => 'pc-data-pages-landing' ) ), 'id="pc-data"' ) );
m6_check( 'buyer: other data pages show no report, only the two entries', ! str_contains( $html, 'id="pc-data"' ) && str_contains( $html, 'admin.php?page=pc-data-funnel-sources">漏斗 + 来源</a>' ) && str_contains( $html, 'admin.php?page=pc-data-products-landing">商品 + 落地页</a>' ) );
$_GET = array( 'page' => 'pc-data-operations' );
Data_Pages::enqueue(); // 没有定义 plugins_url()：真的去加载报表脚本就会报错。
m6_check( 'buyer: other data pages load no report scripts', true );
m6_check( 'merged page urls', 'https://store.test/wp-admin/admin.php?page=pc-data-funnel-sources' === Data_Pages::buyer_url( 'pc-data-funnel-sources' ) );

$GLOBALS['t_locale'] = 'en_US';
$menus();
m6_check( 'buyer: English labels for other locales', array( 'pc-data-funnel-sources' => 'Funnel + sources', 'pc-data-products-landing' => 'Products + landing pages' ) === $GLOBALS['t_submenu'] );

// 没有访问权限的人什么都看不到。
$GLOBALS['t_user'] = new WP_User( 9, array( 'subscriber' ), array() );
m6_check( 'no capability, no output', '' === $render( array( 'page' => 'pc-data-funnel-sources' ) ) );

echo 'PASS ', $GLOBALS['m6_passed'], " buyer data pages (ST-05) assertions\n";
