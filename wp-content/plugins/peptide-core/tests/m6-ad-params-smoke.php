<?php
/**
 * M6“按广告拆分”的入库冒烟：不用浏览器，直接走真正的事件接收接口（POST /peptide/v1/track/events），
 * 确认广告系列 / 广告组 / 广告（utm_campaign / utm_term / utm_content）进了归因、落进表 pc_track_ads，
 * 来源报表按三种分组都读得出来，访客被抹除时广告参数跟着删。
 * 给没有浏览器的环境用：沙盒上用 curl 打开带 utm_* 的地址不会执行前台脚本，所以表里一行都不会有。
 *
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-ad-params-smoke.php run
 *
 * 会临时写入 4 个访客（各一次访问、一条页面浏览事件），结束时用 Privacy::delete_visitors() 连同广告参数一起删掉；
 * 不改任何设置和开关。不带 run 只打印这段说明。只在开发站 / 沙盒运行，不要在正式站点运行。
 *
 * 2026-11-13：pc_track_ads 这一份放在自己的开关后面（选项 peptide_core_track_ads，值是 on 才写；不跟 M6 开关走），
 * 默认不建表、不写入（素材归因已经把同样三项写进访问表的新列，两份数据最终用哪一份还没定）。这个用例在这一次运行里用过滤器 peptide_core_track_ads_write 把写入打开，
 * 表不在就建上（表会留下，是空的）；选项 peptide_core_m6_mode 不改。它同时核对访问表的新列也写上了。
 *
 * 通过标准（退出码 0）：最后一行是“结果：通过（N 项检查）”。
 * 这个用例只管服务端这一半；浏览器里主题脚本有没有把三个参数带上来，要用真正的浏览器看（F5 的主题用例管那一半）。
 *
 * @package PeptideCore
 */

use PeptideCore\Tracking\Ad_Params;
use PeptideCore\Tracking\Privacy;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Tracker;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Ad_Params::class ) || ! function_exists( 'rest_do_request' ) ) {
	fwrite( STDERR, "请用 wp eval-file 在店铺目录里运行，并确认 peptide-core 已启用。\n" );
	exit( 1 );
}

$m6a_say = static function ( string $text ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::log( $text );
	} else {
		echo $text, "\n";
	}
};
$m6a_stop = static function ( int $code ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::halt( $code );
	}
	exit( $code );
};

if ( 'run' !== ( $args[0] ?? '' ) ) {
	$m6a_say( '这个用例会临时写入 4 个测试访客，结束时删掉。确认是开发站或沙盒之后，在命令最后加上 run 再运行：' );
	$m6a_say( '  wp eval-file wp-content/plugins/peptide-core/tests/m6-ad-params-smoke.php run' );
	return;
}

global $wpdb;

$m6a_fail   = array();
$m6a_checks = 0;
$m6a_check  = static function ( string $label, bool $ok, string $detail = '' ) use ( &$m6a_fail, &$m6a_checks, $m6a_say ): void {
	++$m6a_checks;
	$m6a_say( ( $ok ? '  通过  ' : '  没过  ' ) . $label . ( $ok || '' === $detail ? '' : '　实际：' . $detail ) );
	if ( ! $ok ) {
		$m6a_fail[] = $label;
	}
};

// 这一次运行里把 pc_track_ads 的写入打开（默认关：选项 peptide_core_track_ads 不是 on 时不建表也不写）。
$m6a_write = static fn() => true;
add_filter( 'peptide_core_track_ads_write', $m6a_write, PHP_INT_MAX );
Ad_Params::maybe_install();

// 0. 前提：追踪表、广告参数表都在，访客追踪开着。
if ( ! Schema::installed() || ! Ad_Params::installed() || ! Tracker::enabled() ) {
	$m6a_say( sprintf( '前提不满足：追踪表 %s，广告参数表 %s，访客追踪 %s。', Schema::installed() ? '在' : '不在', Ad_Params::installed() ? '在' : '不在', Tracker::enabled() ? '开着' : '关着' ) );
	$m6a_say( '结果：没有通过（前提不满足，什么都没有写入）。' );
	$m6a_stop( 1 );
}

// 接口按“匿名访客的浏览器”来认：没有登录、带浏览器的 User-Agent。跑完恢复。
$m6a_backup = array(
	'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
	'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
	'cookie' => $_COOKIE[ Tracker::COOKIE ] ?? null,
	'user'   => get_current_user_id(),
);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$_SERVER['REMOTE_ADDR']     = '198.51.100.' . wp_rand( 2, 250 ); // 文档专用地址段。
wp_set_current_user( 0 );

// 这一次运行专用的名字，报表里靠它找到自己的行，不受店里已有数据影响。
$m6a_token    = substr( md5( uniqid( 'm6a', true ) ), 0, 8 );
$m6a_campaign = 'm6-smoke-' . $m6a_token;
$m6a_host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$m6a_iso      = static fn( int $ms ): string => gmdate( 'Y-m-d\TH:i:s', (int) floor( $ms / 1000 ) ) . '.' . str_pad( (string) ( $ms % 1000 ), 3, '0', STR_PAD_LEFT ) . 'Z';

/** 一个新访客带着这组落地参数进店：返回 [ 状态码, 确认的事件数, 访客编号, 访问编号 ]。 */
$m6a_send = static function ( string $label, array $query ) use ( $wpdb, $m6a_iso, $m6a_host ): array {
	unset( $_COOKIE[ Tracker::COOKIE ] ); // 每次一个新访客。
	$now  = Tracker::now_ms();
	$uid  = wp_generate_uuid4();
	$body = array(
		'protocol' => 1,
		'tab_id'   => 'tab-' . substr( md5( $label . $now ), 0, 12 ),
		'sent_at'  => $m6a_iso( $now ),
		'events'   => array(
			array(
				'event_id'         => $uid,
				'page_instance_id' => wp_generate_uuid4(),
				'seq'              => 1,
				'type'             => 'page_view',
				'occurred_at'      => $m6a_iso( $now ),
				'page'             => array( 'page_type' => 'home', 'path' => '/' ),
				'data'             => array(
					'visibility'  => 'visible',
					'activity'    => 'active',
					'attribution' => array( 'version' => 1, 'host' => $m6a_host, 'landing_query' => $query, 'referrer_host' => 'm.facebook.com' ),
				),
			),
		),
	);
	$request = new \WP_REST_Request( 'POST', '/peptide/v1/track/events' );
	$request->set_header( 'Content-Type', 'text/plain' ); // 和浏览器的 sendBeacon 一样。
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT visitor_id, visit_id FROM ' . Schema::table( 'events' ) . ' WHERE event_uid = %s', $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	return array( (int) $response->get_status(), is_array( $data['ack'] ?? null ) ? count( $data['ack'] ) : 0, (int) ( $row['visitor_id'] ?? 0 ), (int) ( $row['visit_id'] ?? 0 ) );
};

$m6a_say( '按广告拆分的入库冒烟　这次运行的广告系列名：' . $m6a_campaign );
$m6a_say( '' );
$m6a_say( '1. 四个访客带着不同的落地参数进店' );
$m6a_base  = array( 'utm_source' => 'facebook', 'utm_medium' => 'paid' );
$m6a_cases = array(
	'A' => $m6a_base + array( 'utm_campaign' => $m6a_campaign, 'utm_term' => 'adset-1-' . $m6a_token, 'utm_content' => 'ad-1-' . $m6a_token ),
	'B' => $m6a_base + array( 'utm_campaign' => $m6a_campaign, 'utm_term' => 'adset-2-' . $m6a_token, 'utm_content' => 'ad-2-' . $m6a_token ),
	// 广告平台没把占位符换成名字时：只丢广告参数，来源照常记。
	'C' => $m6a_base + array( 'utm_campaign' => '{{campaign.name}}', 'utm_term' => '{{adset.name}}', 'utm_content' => '{{ad.name}}' ),
	// 只有来源，没有广告参数。
	'D' => $m6a_base,
);
$m6a_sent     = array();
$m6a_visitors = array();
foreach ( $m6a_cases as $m6a_key => $m6a_query ) {
	$m6a_sent[ $m6a_key ] = $m6a_send( $m6a_key, $m6a_query );
	if ( $m6a_sent[ $m6a_key ][2] > 0 ) {
		$m6a_visitors[] = $m6a_sent[ $m6a_key ][2];
	}
}
$m6a_visit = static fn( string $key ): int => (int) $m6a_sent[ $key ][3];
foreach ( $m6a_sent as $m6a_key => $m6a_result ) {
	$m6a_check( "访客 {$m6a_key}：接口返回 200，事件被确认，建了访问", 200 === $m6a_result[0] && 1 === $m6a_result[1] && $m6a_result[3] > 0, wp_json_encode( $m6a_result ) );
}
$m6a_check( '四个访客各是各的（4 个不同的访客编号）', 4 === count( array_unique( $m6a_visitors ) ), wp_json_encode( $m6a_visitors ) );

$m6a_say( '' );
$m6a_say( '2. 表 ' . Ad_Params::table() . ' 里存了什么' );
$m6a_rows = Ad_Params::for_visits( array_filter( array_map( $m6a_visit, array_keys( $m6a_cases ) ) ) );
$m6a_row  = static fn( string $key ): array => (array) ( $m6a_rows[ $m6a_visit( $key ) ] ?? array() );
$m6a_check( '访客 A：三个参数原样入库', $m6a_campaign === ( $m6a_row( 'A' )['utm_campaign'] ?? null ) && 'adset-1-' . $m6a_token === ( $m6a_row( 'A' )['utm_term'] ?? null ) && 'ad-1-' . $m6a_token === ( $m6a_row( 'A' )['utm_content'] ?? null ), wp_json_encode( $m6a_row( 'A' ) ) );
$m6a_check( '访客 B：同一个广告系列、另一个广告组和广告', $m6a_campaign === ( $m6a_row( 'B' )['utm_campaign'] ?? null ) && 'adset-2-' . $m6a_token === ( $m6a_row( 'B' )['utm_term'] ?? null ) && 'ad-2-' . $m6a_token === ( $m6a_row( 'B' )['utm_content'] ?? null ), wp_json_encode( $m6a_row( 'B' ) ) );
$m6a_check( '访客 C：没被替换的占位符不入库', array() === array_filter( array_intersect_key( $m6a_row( 'C' ), array_flip( array( 'utm_campaign', 'utm_term', 'utm_content' ) ) ) ), wp_json_encode( $m6a_row( 'C' ) ) );
$m6a_check( '访客 D：没有广告参数就没有这一行', array() === $m6a_row( 'D' ), wp_json_encode( $m6a_row( 'D' ) ) );
$m6a_sources = $m6a_visit( 'C' ) > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT utm_source, utm_medium FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $m6a_visit( 'C' ) ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
$m6a_check( '访客 C：来源和媒介照常记在访问上（不因为广告参数不合规而丢）', 'facebook' === ( $m6a_sources['utm_source'] ?? null ) && 'paid' === ( $m6a_sources['utm_medium'] ?? null ), wp_json_encode( $m6a_sources ) );
// 素材归因（主线 11-11 起）把同样三项写进访问表的新列：两份应该一致。
$m6a_columns = $m6a_visit( 'A' ) > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT utm_campaign, utm_term, utm_content FROM ' . Schema::table( 'visits' ) . ' WHERE id = %d', $m6a_visit( 'A' ) ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
$m6a_check( '访客 A：访问表的新列里也是同样三项（素材归因写的那一份）', $m6a_campaign === ( $m6a_columns['utm_campaign'] ?? null ) && 'adset-1-' . $m6a_token === ( $m6a_columns['utm_term'] ?? null ) && 'ad-1-' . $m6a_token === ( $m6a_columns['utm_content'] ?? null ), wp_json_encode( $m6a_columns ) );

$m6a_say( '' );
$m6a_say( '3. 来源报表按广告系列 / 广告组 / 广告分组' );
$m6a_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $m6a_admins[0] ?? 0 ) );
/** 来源报表里这次运行的那几行：[ 状态码, [ 各标识列的值拼起来 => 访客数 ] ]。 */
$m6a_report = static function ( string $group, array $extra = array() ) use ( $m6a_token ): array {
	$request = new \WP_REST_Request( 'GET', '/peptide/v1/data/sources' );
	$request->set_query_params( array( 'range' => 'today', 'group_by' => $group, 'q' => $m6a_token, 'per_page' => 100 ) + $extra );
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$found    = array();
	foreach ( (array) ( is_array( $data ) ? ( $data['rows'] ?? array() ) : array() ) as $row ) {
		$id = array();
		foreach ( array( 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$id[] = 'value' === ( $row[ $key ]['state'] ?? '' ) ? (string) $row[ $key ]['value'] : '<' . ( $row[ $key ]['state'] ?? '?' ) . '>';
			}
		}
		$found[ implode( ' | ', $id ) ] = (int) ( $row['uv'] ?? 0 );
	}
	ksort( $found );
	return array( (int) $response->get_status(), $found );
};
[ $m6a_status, $m6a_found ] = $m6a_report( 'campaign' );
$m6a_check( '按广告系列：一行，访客数 2', 200 === $m6a_status && array( $m6a_campaign => 2 ) === $m6a_found, $m6a_status . ' ' . wp_json_encode( $m6a_found ) );
[ $m6a_status, $m6a_found ] = $m6a_report( 'adset' );
$m6a_check( '按广告组：两行，各 1 个访客', 200 === $m6a_status && array( $m6a_campaign . ' | adset-1-' . $m6a_token => 1, $m6a_campaign . ' | adset-2-' . $m6a_token => 1 ) === $m6a_found, $m6a_status . ' ' . wp_json_encode( $m6a_found ) );
[ $m6a_status, $m6a_found ] = $m6a_report( 'ad' );
$m6a_check( '按广告：两行，各 1 个访客', 200 === $m6a_status && array( $m6a_campaign . ' | adset-1-' . $m6a_token . ' | ad-1-' . $m6a_token => 1, $m6a_campaign . ' | adset-2-' . $m6a_token . ' | ad-2-' . $m6a_token => 1 ) === $m6a_found, $m6a_status . ' ' . wp_json_encode( $m6a_found ) );
[ $m6a_status ] = $m6a_report( 'campaign', array( 'split_medium' => 1 ) );
$m6a_check( '按广告分组时不能同时“按媒介拆分”（接口拒绝，400）', 400 === $m6a_status, (string) $m6a_status );

$m6a_say( '' );
$m6a_say( '4. 清理：删掉这四个访客，广告参数跟着删' );
if ( $m6a_visitors ) {
	Privacy::delete_visitors( array_values( array_unique( $m6a_visitors ) ) );
}
$m6a_in       = implode( ',', array_map( 'intval', $m6a_visitors ?: array( 0 ) ) );
$m6a_left     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visitors' ) . " WHERE id IN ({$m6a_in})" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'visits' ) . " WHERE visitor_id IN ({$m6a_in})" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . " WHERE visitor_id IN ({$m6a_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
$m6a_ads_left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ad_Params::table() . ' WHERE utm_campaign = %s', $m6a_campaign ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
$m6a_check( '访客、访问、事件都删干净了', 0 === $m6a_left, (string) $m6a_left );
$m6a_check( '广告参数的行也删了', 0 === $m6a_ads_left, (string) $m6a_ads_left );
[ $m6a_status, $m6a_found ] = $m6a_report( 'campaign' );
$m6a_check( '来源报表里不再有这次运行的行', 200 === $m6a_status && array() === $m6a_found, $m6a_status . ' ' . wp_json_encode( $m6a_found ) );

// 恢复运行前的环境。
remove_filter( 'peptide_core_track_ads_write', $m6a_write, PHP_INT_MAX );
foreach ( array( 'ua' => 'HTTP_USER_AGENT', 'ip' => 'REMOTE_ADDR' ) as $m6a_key => $m6a_name ) {
	if ( null === $m6a_backup[ $m6a_key ] ) {
		unset( $_SERVER[ $m6a_name ] );
	} else {
		$_SERVER[ $m6a_name ] = $m6a_backup[ $m6a_key ];
	}
}
if ( null === $m6a_backup['cookie'] ) {
	unset( $_COOKIE[ Tracker::COOKIE ] );
} else {
	$_COOKIE[ Tracker::COOKIE ] = $m6a_backup['cookie'];
}
wp_set_current_user( (int) $m6a_backup['user'] );

$m6a_say( '' );
if ( $m6a_fail ) {
	$m6a_say( '结果：没有通过（' . count( $m6a_fail ) . ' / ' . $m6a_checks . ' 项）。' );
	$m6a_stop( 1 );
}
$m6a_say( '结果：通过（' . $m6a_checks . ' 项检查）。广告系列 / 广告组 / 广告能入库，来源报表三种分组都读得出来，抹除访客时一起删掉。' );
