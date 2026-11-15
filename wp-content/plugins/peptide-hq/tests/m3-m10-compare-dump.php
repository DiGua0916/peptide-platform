<?php
/**
 * M3 / M10 发布前比对（总后台）：把系列管理、订单与退款、跨店报表、订单号搜索的页面和数字原样记下来。
 *
 * 用法：同一份数据，先用线上版代码跑一遍，换成发布版代码再跑一遍，然后 diff -r 两个目录。
 *   wp eval-file wp-content/plugins/peptide-hq/tests/m3-m10-compare-dump.php <输出目录> [<起始日 Y-m-d> <结束日 Y-m-d>]
 *
 * 只读：脚本自己不写数据库（读页面时 WordPress 可能写几条缓存，会数出来写在 meta.json 里）；不发网络请求。
 * 可以在本地副本和沙盒上跑；输出里有店铺名和商品名，没有顾客资料（总后台本来就没有）。
 * 注意：发布版代码第一次加载时会把 pchq_m10_db 从 1 升到 2，这是代码加载时做的，不是本脚本。
 *
 * @package PeptideHq
 */

use PeptideHq\Orders;
use PeptideHq\Series;
use PeptideHq\Sites;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$out  = rtrim( (string) ( $args[0] ?? '' ), '/' );
$from = (string) ( $args[1] ?? wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ) );
$to   = (string) ( $args[2] ?? wp_date( 'Y-m-d' ) );
if ( '' === $out || ! wp_mkdir_p( $out . '/pages' ) ) {
	WP_CLI::error( '用法：wp eval-file m3-m10-compare-dump.php <输出目录> [<起始日> <结束日>]' );
}

// 不发任何网络请求。
add_filter( 'pre_http_request', static fn() => new WP_Error( 'compare_blocked', 'blocked during compare' ), 1 );
// 数一数脚本期间的写入语句。
$writes = array();
add_filter(
	'query',
	static function ( $q ) use ( &$writes ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', (string) $q ) ) {
			$writes[] = substr( preg_replace( '/\s+/', ' ', (string) $q ), 0, 160 );
		}
		return $q;
	}
);

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

/** 页面 HTML 去掉每次都会变的东西（随机校验码）。 */
$normalize = static function ( string $html ): string {
	$html = (string) preg_replace( '/(_wpnonce|nonce)(["\']?\s*(?:value=|=)\s*["\']?)[0-9a-f]{10}/i', '$1$2NONCE', $html );
	$html = (string) preg_replace( '/name="_wp_http_referer" value="[^"]*"/', 'name="_wp_http_referer" value="REFERER"', $html );
	return (string) preg_replace( '/>\s*</', ">\n<", $html );
};
/** 页面上看得见的文字，一块一行。 */
$visible = static function ( string $html ): string {
	$html = (string) preg_replace( '#<(script|style)\b.*?</\1>#is', '', $html );
	$html = (string) preg_replace( '#</(td|th)>#i', ' | ', $html );
	$html = (string) preg_replace( '#<(br|/p|/div|/tr|/li|/h[1-6]|/dt|/dd|/section|/label|/option|/caption|/summary)\b[^>]*>#i', "\n", $html );
	$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES, 'UTF-8' );
	$rows = array();
	foreach ( explode( "\n", $text ) as $line ) {
		$line = trim( (string) preg_replace( '/[ \t]+/u', ' ', $line ) );
		if ( '' !== $line && '|' !== $line ) {
			$rows[] = $line;
		}
	}
	return implode( "\n", $rows ) . "\n";
};
$render = static function ( string $name, string $class, array $get ) use ( $out, $normalize, $visible ): void {
	$_GET     = $get;
	$_REQUEST = $get;
	ob_start();
	try {
		if ( class_exists( $class ) ) {
			call_user_func( array( $class, 'render' ) );
		} else {
			echo 'CLASS MISSING: ' . esc_html( $class );
		}
	} catch ( Throwable $e ) {
		echo 'ERROR: ' . esc_html( $e->getMessage() );
	} finally {
		$html = (string) ob_get_clean();
	}
	file_put_contents( "$out/pages/$name.html", $normalize( $html ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	file_put_contents( "$out/pages/$name.txt", $visible( $html ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$_GET     = array();
	$_REQUEST = array();
};

$data = array( 'range' => array( $from, $to ) );

// ---- 系列 ----
$series         = Series::all( true );
$data['series'] = $series;
$data['site_map'] = Series::site_map();
$render( 'series-default', PeptideHq\Admin\Series_Page::class, array( 'page' => 'pchq-series' ) );
foreach ( array_keys( $series ) as $sid ) {
	$render( 'series-' . $sid, PeptideHq\Admin\Series_Page::class, array( 'page' => 'pchq-series', 'series' => (string) $sid ) );
}

// ---- 订单：合计、报表、搜索 ----
$tz    = wp_timezone();
$t0    = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp();
$t1    = ( new DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp() - 1;
$data['site_totals_all']   = Orders::site_totals();
$data['site_totals_range'] = Orders::site_totals( $t0, $t1 );
$data['report']            = Orders::report( $from, $to );

global $wpdb;
$orders = (array) $wpdb->get_results( 'SELECT id, site_id, store_order_id, order_number FROM ' . Orders::table() . ' ORDER BY id LIMIT 12', ARRAY_A ); // phpcs:ignore
$queries = array();
foreach ( $orders as $o ) {
	$n = (string) $o['order_number'];
	$queries[] = $n;
	$queries[] = strtoupper( $n );
	$queries[] = '#' . $n;
	$queries[] = (string) $o['store_order_id'];
	$queries[] = '#' . $o['store_order_id'];
}
$queries[] = 'C00SMK01';
$queries[] = 'no-such-order';
$data['search'] = array();
foreach ( array_values( array_unique( $queries ) ) as $q ) {
	$res                  = Orders::search( array( 'q' => $q ) );
	$data['search'][ $q ] = array(
		'total' => (int) $res['total'],
		'found' => array_map( static fn( $r ) => $r['site_id'] . ':' . $r['store_order_id'], $res['rows'] ),
	);
}
foreach ( array( '', 'paid', 'unpaid', 'refunded', 'test' ) as $state ) {
	$res = Orders::search( array( 'state' => $state ) );
	$data['filter'][ '' === $state ? 'all' : $state ] = array( 'total' => (int) $res['total'], 'found' => array_map( static fn( $r ) => $r['site_id'] . ':' . $r['store_order_id'], $res['rows'] ) );
}
$data['orders_rows'] = array();
foreach ( $orders as $o ) {
	$row = Orders::get( (int) $o['site_id'], (int) $o['store_order_id'] );
	unset( $row['updated_at'], $row['reported_at'] ); // 两次运行之间可能被补报改写，不比。
	$data['orders_rows'][] = $row;
}

$render( 'orders-list', PeptideHq\Admin\Orders_Page::class, array( 'page' => 'pchq-orders' ) );
$render( 'orders-refunded', PeptideHq\Admin\Orders_Page::class, array( 'page' => 'pchq-orders', 'state' => 'refunded' ) );
foreach ( array_slice( $orders, 0, 4 ) as $o ) {
	$render( 'order-' . $o['site_id'] . '-' . $o['store_order_id'], PeptideHq\Admin\Orders_Page::class, array( 'page' => 'pchq-orders', 'order' => (string) $o['id'] ) );
	if ( '' !== (string) $o['order_number'] ) {
		$render( 'orders-search-' . sanitize_title( (string) $o['order_number'] ), PeptideHq\Admin\Orders_Page::class, array( 'page' => 'pchq-orders', 'q' => strtoupper( (string) $o['order_number'] ) ) );
	}
}
$render( 'report', PeptideHq\Admin\Report_Page::class, array( 'page' => 'pchq-report', 'from' => $from, 'to' => $to ) );

// ---- 花费 ----
$data['spend'] = (array) $wpdb->get_results( 'SELECT site_id, day, spend_cents, source FROM ' . Orders::spend_table() . ' ORDER BY site_id, day', ARRAY_A ); // phpcs:ignore

// ---- 表和版本（这一项预期有差别：发布版多一列、一张表） ----
$meta = array(
	'versions' => array(
		'pchq_m3_db'        => get_option( 'pchq_m3_db' ),
		'pchq_m10_db'       => get_option( 'pchq_m10_db' ),
		'pchq_creatives_db' => get_option( 'pchq_creatives_db' ),
		'PEPTIDE_HQ_VERSION' => defined( 'PEPTIDE_HQ_VERSION' ) ? PEPTIDE_HQ_VERSION : '',
		'pchq_m10_extras'   => get_option( 'pchq_m10_extras', '(没有这个选项)' ),
	),
	'columns'  => array(
		'pchq_orders' => $wpdb->get_col( 'SHOW COLUMNS FROM ' . Orders::table(), 0 ), // phpcs:ignore
		'pchq_sites'  => $wpdb->get_col( 'SHOW COLUMNS FROM ' . PeptideHq\Db::table( 'sites' ), 0 ), // phpcs:ignore
	),
	'sites'    => array_map( static fn( $s ) => $s['name'], Sites::all() ),
	'writes_during_dump' => $writes,
);

$json = static fn( $v ): string => (string) wp_json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
file_put_contents( "$out/data.json", $json( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( "$out/meta.json", $json( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
WP_CLI::success( sprintf( '已写到 %s：%d 个页面，%d 次搜索；脚本期间写入语句 %d 条。', $out, count( glob( "$out/pages/*.txt" ) ), count( $data['search'] ), count( $writes ) ) );
