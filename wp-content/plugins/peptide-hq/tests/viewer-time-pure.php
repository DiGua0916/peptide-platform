<?php
/**
 * 2026-11-28：总后台“美西时间”开关（Viewer_Time）。
 *
 * 行为级：真的载入 Viewer_Time 和 Stats，WordPress 用内存替身。覆盖：
 *   1. toggle：点美西 = 打开，再点 = 关；不认识的值 = 关；
 *   2. 打开后，总后台页面里时区换成 America/Los_Angeles，gmt_offset 是美西当前小时差；
 *   3. home_timezone / home_date 永远按总后台自己的时区（北京时间）；
 *   4. 不换：常规设置页、定时任务、WP-CLI、店铺上报的 REST（没有后台 referer）、别的站的 referer、没选的账号；
 *      从后台页面发出的 REST 要换；
 *   5. Stats::range（概览用，取各店按天送来的表）不跟美西开关变；
 *   6. 说明文字：没打开时为空，打开时点出访客 / 弃单 / 花费没换日。
 *
 * 不碰数据库、不发网络请求、不需要 WordPress。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['meta']    = array();
	$GLOBALS['user']    = 7;
	$GLOBALS['admin']   = true;
	$GLOBALS['cron']    = false;
	$GLOBALS['ajax']    = false;
	$GLOBALS['referer'] = '';
	$GLOBALS['pagenow'] = 'admin.php';

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	function add_filter( ...$a ) {}
	function add_action( ...$a ) {}
	function did_action( $h ) { return 1; }
	function get_current_user_id() { return $GLOBALS['user']; }
	function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['meta'][ $u ][ $k ] ?? ''; }
	function update_user_meta( $u, $k, $v ) { $GLOBALS['meta'][ $u ][ $k ] = $v; return true; }
	function delete_user_meta( $u, $k ) { unset( $GLOBALS['meta'][ $u ][ $k ] ); return true; }
	function is_admin() { return $GLOBALS['admin']; }
	function wp_doing_cron() { return $GLOBALS['cron']; }
	function wp_doing_ajax() { return $GLOBALS['ajax']; }
	function wp_get_raw_referer() { return $GLOBALS['referer']; }
	function admin_url( $p = '' ) { return 'https://vialcoveresearch.com/wp-admin/' . ltrim( $p, '/' ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	/** 替身：和 WordPress 一样先过 pre_option 过滤，没有就用总后台存的北京时间。 */
	function get_option( $k, $d = false ) {
		if ( 'timezone_string' === $k ) {
			$pre = \PeptideHq\Viewer_Time::filter_timezone_string( false );
			return false !== $pre ? $pre : 'Asia/Shanghai';
		}
		if ( 'gmt_offset' === $k ) {
			$pre = \PeptideHq\Viewer_Time::filter_gmt_offset( false );
			return false !== $pre ? $pre : 8;
		}
		return $d;
	}
	function wp_timezone() { return new DateTimeZone( get_option( 'timezone_string' ) ); }
	function wp_date( $format, $ts = null, $tz = null ) {
		return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz ?? wp_timezone() )->format( $format );
	}
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }

	require dirname( __DIR__ ) . '/includes/class-viewer-time.php';
	require dirname( __DIR__ ) . '/includes/class-stats.php';
}

namespace {
	use PeptideHq\Viewer_Time;
	use PeptideHq\Stats;

	$reset = static function (): void {
		$GLOBALS['meta']    = array();
		$GLOBALS['user']    = 7;
		$GLOBALS['admin']   = true;
		$GLOBALS['cron']    = false;
		$GLOBALS['ajax']    = false;
		$GLOBALS['referer'] = '';
		$GLOBALS['pagenow'] = 'admin.php';
	};

	// 1. toggle
	check( 'toggle on', 'us_west' === Viewer_Time::toggle( '', 'us_west' ) );
	check( 'toggle off', '' === Viewer_Time::toggle( 'us_west', 'us_west' ) );
	check( 'toggle unknown', '' === Viewer_Time::toggle( 'us_west', 'us_east' ) );
	check( 'save unknown clears', '' === Viewer_Time::save( 7, 'mars' ) && '' === Viewer_Time::choice( 7 ) );

	// 2. 打开后总后台页面换美西
	$reset();
	check( 'default is Beijing', 'Asia/Shanghai' === wp_timezone()->getName() );
	check( 'note empty when off', '' === Viewer_Time::day_keyed_note() && '' === Viewer_Time::overview_note() );
	Viewer_Time::save( 7, 'us_west' );
	check( 'admin page switched', 'America/Los_Angeles' === wp_timezone()->getName() );
	$expect = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Los_Angeles' ) ) )->getOffset() / 3600;
	check( 'gmt_offset follows LA', (float) get_option( 'gmt_offset' ) === (float) $expect );
	$ts = 1764356400; // 2025-11-28 19:00 UTC = 北京 11-29 03:00 = 美西 11-28 11:00
	check( 'wp_date in LA', '2025-11-28 11:00' === wp_date( 'Y-m-d H:i', $ts ) );

	// 3. 总后台自己的时区不变
	check( 'home tz', 'Asia/Shanghai' === Viewer_Time::home_timezone()->getName() );
	check( 'home date', '2025-11-29 03:00' === Viewer_Time::home_date( 'Y-m-d H:i', $ts ) );
	check( 'after bypass still LA', 'America/Los_Angeles' === wp_timezone()->getName() );

	// 6. 说明文字
	check( 'day note names visitors/abandon/spend', str_contains( Viewer_Time::day_keyed_note(), '访客' ) && str_contains( Viewer_Time::day_keyed_note(), '弃单' ) && str_contains( Viewer_Time::day_keyed_note(), '广告花费' ) );
	check( 'note names Beijing', str_contains( Viewer_Time::day_keyed_note(), '北京时间' ) );
	check( 'overview note', str_contains( Viewer_Time::overview_note(), '跨店报表' ) );

	// 5. 概览取日子不跟美西变
	[ $from, $to ] = Stats::range( 'today' );
	$bj_today = ( new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Shanghai' ) ) )->format( 'Y-m-d' );
	check( 'Stats::range today = Beijing date', $bj_today === $from && $bj_today === $to );
	[ $from ] = Stats::range( '7d' );
	check( 'Stats::range 7d from Beijing', ( new DateTimeImmutable( '@' . ( time() - 6 * 86400 ) ) )->setTimezone( new DateTimeZone( 'Asia/Shanghai' ) )->format( 'Y-m-d' ) === $from );

	// 4. 不换的情况
	$GLOBALS['pagenow'] = 'options-general.php';
	check( 'settings page not switched', 'Asia/Shanghai' === wp_timezone()->getName() );
	$GLOBALS['pagenow'] = 'admin.php';
	$GLOBALS['cron'] = true;
	check( 'cron not switched', 'Asia/Shanghai' === wp_timezone()->getName() );
	$GLOBALS['cron'] = false;
	$GLOBALS['ajax'] = true;
	check( 'ajax without referer not switched', 'Asia/Shanghai' === wp_timezone()->getName() );
	$GLOBALS['referer'] = 'https://vialcoveresearch.com/wp-admin/admin.php?page=pchq-report';
	check( 'ajax from admin page switched', 'America/Los_Angeles' === wp_timezone()->getName() );
	$GLOBALS['ajax'] = false;
	$GLOBALS['referer'] = '';

	define( 'REST_REQUEST', true );
	$GLOBALS['admin'] = false;
	check( 'store REST push not switched', 'Asia/Shanghai' === wp_timezone()->getName() );
	$GLOBALS['referer'] = 'https://www.lumornea.com/wp-admin/admin.php';
	check( 'other host referer not switched', 'Asia/Shanghai' === wp_timezone()->getName() );
	$GLOBALS['referer'] = 'https://vialcoveresearch.com/checkout/';
	check( 'front-end referer not switched', 'Asia/Shanghai' === wp_timezone()->getName() );
	$GLOBALS['referer'] = 'https://vialcoveresearch.com/wp-admin/admin.php?page=pchq';
	check( 'REST from HQ admin switched', 'America/Los_Angeles' === wp_timezone()->getName() );
	$GLOBALS['user'] = 8;
	check( 'other account not switched', 'Asia/Shanghai' === wp_timezone()->getName() );
	$GLOBALS['user'] = 0;
	check( 'logged out not switched', 'Asia/Shanghai' === wp_timezone()->getName() );

	echo "viewer-time-pure: {$checks} checks passed\n";
}
