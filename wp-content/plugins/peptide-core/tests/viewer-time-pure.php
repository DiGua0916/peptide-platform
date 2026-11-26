<?php
/**
 * 后台看数时区（Viewer_Time，店主 2026-11-26）纯测试：开关的切换规则、哪些请求换时区、店铺时区设置页不换、
 * 换了以后 wp_timezone / wp_date / current_time 和“今天”的日界跟着变、缓存名带时区、店铺自己的时区读得到。
 *
 * 运行：php wp-content/plugins/peptide-core/tests/viewer-time-pure.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['t_meta']    = array();
$GLOBALS['t_user']    = 7;
$GLOBALS['t_admin']   = true;
$GLOBALS['t_ajax']    = false;
$GLOBALS['t_referer'] = '';
$GLOBALS['t_actions'] = array( 'set_current_user' => 1 );
$GLOBALS['t_options'] = array( 'timezone_string' => 'Asia/Shanghai', 'gmt_offset' => 0 );
$GLOBALS['t_filters'] = array();

function add_filter( $hook, $cb ) { $GLOBALS['t_filters'][ $hook ][] = $cb; }
function add_action( $hook, $cb ) {}
function did_action( $hook ) { return $GLOBALS['t_actions'][ $hook ] ?? 0; }
function get_current_user_id() { return $GLOBALS['t_user']; }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['t_meta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['t_meta'][ $id ][ $key ] = $value; return true; }
function delete_user_meta( $id, $key ) { unset( $GLOBALS['t_meta'][ $id ][ $key ] ); return true; }
function is_admin() { return $GLOBALS['t_admin']; }
function wp_doing_ajax() { return $GLOBALS['t_ajax']; }
function wp_doing_cron() { return false; }
function wp_get_raw_referer() { return $GLOBALS['t_referer']; }
function admin_url( $path = '' ) { return 'https://lumornea.com/wp-admin/' . ltrim( $path, '/' ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function get_option( $name, $default = false ) {
	foreach ( $GLOBALS['t_filters'][ 'pre_option_' . $name ] ?? array() as $cb ) {
		$pre = call_user_func( $cb, false );
		if ( false !== $pre ) { return $pre; }
	}
	return $GLOBALS['t_options'][ $name ] ?? $default;
}
function wp_timezone() {
	$tz = get_option( 'timezone_string' );
	if ( $tz ) { return new DateTimeZone( $tz ); }
	$o = (float) get_option( 'gmt_offset' );
	return new DateTimeZone( sprintf( '%+03d:%02d', (int) $o, abs( ( $o - (int) $o ) * 60 ) ) );
}
function wp_date( $format, $ts = null, $tz = null ) { return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz ?? wp_timezone() )->format( $format ); }
function current_time_ts() { return time() + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); }

require dirname( __DIR__ ) . '/includes/class-viewer-time.php';
use PeptideCore\Viewer_Time as V;
V::register_hooks();

$fails = 0;
$ok = static function ( string $label, bool $cond ) use ( &$fails ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
	if ( ! $cond ) { $fails++; }
};
$reset = static function (): void {
	$GLOBALS['t_admin'] = true; $GLOBALS['t_ajax'] = false; $GLOBALS['t_referer'] = '';
	$GLOBALS['pagenow'] = 'admin.php'; $_GET = array( 'page' => 'pc-overview' ); $_REQUEST = $_GET;
};
$reset();

// 1. 开关：点未选的 = 换成它；点已选的 = 回默认；不认识的 = 默认。
$ok( 'toggle: default → us_west', 'us_west' === V::toggle( '', 'us_west' ) );
$ok( 'toggle: us_west again → default', '' === V::toggle( 'us_west', 'us_west' ) );
$ok( 'toggle: us_west → us_east', 'us_east' === V::toggle( 'us_west', 'us_east' ) );
$ok( 'toggle: unknown → default', '' === V::toggle( 'us_east', 'mars' ) );

// 2. 没选：一切照旧。
$ok( 'no choice: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$ok( 'no choice: cache tag empty', '' === V::cache_tag() );

// 3. 选了美西，后台页面换成洛杉矶时区，gmt_offset 跟着（含夏令时）。
V::save( 7, 'us_west' );
$ok( 'choice saved on account', 'us_west' === V::choice( 7 ) );
$ok( 'admin page: Los Angeles', 'America/Los_Angeles' === wp_timezone()->getName() );
$la_offset = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->getOffset() / 3600;
$ok( 'admin page: gmt_offset follows (' . $la_offset . ')', (float) get_option( 'gmt_offset' ) === (float) $la_offset );
$ok( 'cache tag carries zone', '|tz:America/Los_Angeles' === V::cache_tag() );
$ok( 'store zone still readable', 'Asia/Shanghai' === V::store_timezone()->getName() );
$ok( 'store zone read does not leak bypass', 'America/Los_Angeles' === wp_timezone()->getName() );

// 4. “今天”的日界：北京时间 2026-11-26 10:00 = 美西 11-25 18:00。
$ts = ( new DateTimeImmutable( '2026-11-26 10:00', new DateTimeZone( 'Asia/Shanghai' ) ) )->getTimestamp();
$ok( 'viewer day is US West day', '2026-11-25' === wp_date( 'Y-m-d', $ts ) );
$ok( 'store_date keeps store day', '2026-11-26' === V::store_date( 'Y-m-d', $ts ) );
$midnight = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() )->setTime( 0, 0 )->getTimestamp();
$ok( 'day starts at US West midnight (16:00 Beijing)', '2026-11-25 16:00' === ( new DateTimeImmutable( '@' . $midnight ) )->setTimezone( new DateTimeZone( 'Asia/Shanghai' ) )->format( 'Y-m-d H:i' ) );

// 5. 美东。
V::save( 7, 'us_east' );
$ok( 'us_east: New York', 'America/New_York' === wp_timezone()->getName() );
V::save( 7, 'nonsense' );
$ok( 'bad value clears choice', '' === V::choice( 7 ) && ! isset( $GLOBALS['t_meta'][7][ V::META ] ) );
V::save( 7, 'us_west' );

// 6. 哪些请求不换。
$GLOBALS['t_admin'] = false;
$ok( 'storefront page: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$reset();
$GLOBALS['t_user'] = 0;
$ok( 'logged out: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$GLOBALS['t_user'] = 8;
$ok( 'other account without choice: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$GLOBALS['t_user'] = 7;
$GLOBALS['t_actions'] = array();
$ok( 'before user is known: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$GLOBALS['t_actions'] = array( 'set_current_user' => 1 );
$_GET = array( 'page' => 'pc-system', 'section' => 'basic' );
$ok( 'system settings (store time zone form): store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$reset();
$GLOBALS['pagenow'] = 'options-general.php'; $_GET = array();
$ok( 'WordPress general settings: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$GLOBALS['pagenow'] = 'admin-post.php'; $_REQUEST = array( 'action' => 'pc_basic_config_save' );
$ok( 'basic config save: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$reset();
$GLOBALS['t_ajax'] = true; $GLOBALS['t_referer'] = 'https://lumornea.com/product/bpc-157/';
$ok( 'admin-ajax from storefront: store zone', 'Asia/Shanghai' === wp_timezone()->getName() );
$GLOBALS['t_referer'] = 'https://lumornea.com/wp-admin/admin.php?page=pc-data';
$ok( 'admin-ajax from admin page: viewer zone', 'America/Los_Angeles' === wp_timezone()->getName() );

// 7. 纯函数：后台 referer 和 Woo 分析接口。
$ok( 'referer: admin page', V::is_admin_referer( 'https://lumornea.com/wp-admin/admin.php?page=pc-overview&pc_embed=1', 'https://lumornea.com/wp-admin/' ) );
$ok( 'referer: storefront', ! V::is_admin_referer( 'https://lumornea.com/cart/', 'https://lumornea.com/wp-admin/' ) );
$ok( 'referer: other host', ! V::is_admin_referer( 'https://evil.example/wp-admin/', 'https://lumornea.com/wp-admin/' ) );
$ok( 'referer: empty', ! V::is_admin_referer( '', 'https://lumornea.com/wp-admin/' ) );
$ok( 'rest: wc-analytics excluded', V::rest_excluded( '/wc-analytics/reports/orders' ) && V::rest_excluded( 'wc-admin/options' ) );
$ok( 'rest: peptide data kept', ! V::rest_excluded( '/peptide/v1/data/trends' ) );
$ok( 'admin_excluded: ordinary page', ! V::admin_excluded( 'admin.php', 'pc-data', '' ) );

echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
