<?php
/**
 * M6 / M7 模块发布前的“线上功能保持原样”比对：同一份数据，旧代码读一遍、新代码读一遍，逐项比较。
 *
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-release-snapshot.php run <输出.json> [起始日期 结束日期]
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-release-snapshot.php compare <旧.json> <新.json>
 *
 * run 读的东西（不改设置、不改数据；只会删掉、重建报表自己的缓存，让两次读到的都是现算的数）：
 *   - 数据页（含主线 11-14 的“页面与落地页”、复盘、今日作战室）、概览页、实时访客 / 访客档案 / 访客转化页、像素页：
 *     服务端输出的页面外壳和交给页面脚本的配置（window.pcData 等）；
 *   - 这些页面用到的全部 GET 接口（peptide/v1 下的 data/、overview、track/admin、product-activity），时间段用“今天”和给定的日期段；
 *   - 像素：设置（令牌只留指纹）、给浏览器的配置、谁会触发像素、最近 30 张订单的购买事件；
 *   - 指标：今天和给定日期段的汇总、漏斗、每日汇总，总后台每日汇总的弃单数那一段（Hq_Connector::with_abandoned）；
 *   - 开关、版本选项、表在不在。
 * 每一项分别以管理员和投手（店里第一个有 pc_media_buyer 角色的账号，没有就跳过）的身份读。
 * 模块开关（peptide_core_m6_mode）按店铺现在的值读；要看别的档位，加环境变量 M6_MODE=off|shadow|on（只在这一次运行里生效）。
 *
 * compare 逐项比较两份输出，打印不一样的项和第一处不一样的位置。去掉了每次都会变的东西（静态文件的 ver 参数、nonce、
 * 生成时间）。退出码：0 = 完全一样，1 = 有不一样的项。
 *
 * 只在开发站 / 沙盒运行。旧代码和新代码都能跑（只用两边都有的公开接口；新代码才有的类不在时跳过那一项）。
 *
 * @package PeptideCore
 */

use PeptideCore\Admin\Data_Pages;
use PeptideCore\Admin\Overview_Page;
use PeptideCore\Admin\Pixels_Page;
use PeptideCore\Admin\Tracking_Page;
use PeptideCore\Data\Metrics;
use PeptideCore\Pixels;

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'rest_do_request' ) ) {
	fwrite( STDERR, "请用 wp eval-file 在店铺目录里运行。\n" );
	exit( 1 );
}

$m6x_cmd = (string) ( $args[0] ?? '' );

// ---------- compare ----------
if ( 'compare' === $m6x_cmd ) {
	$a = json_decode( (string) file_get_contents( (string) ( $args[1] ?? '' ) ), true );
	$b = json_decode( (string) file_get_contents( (string) ( $args[2] ?? '' ) ), true );
	if ( ! is_array( $a ) || ! is_array( $b ) ) {
		fwrite( STDERR, "读不出两份输出。\n" );
		exit( 2 );
	}
	$first = static function ( $x, $y, string $path = '' ) use ( &$first ): ?string {
		if ( is_array( $x ) && is_array( $y ) ) {
			foreach ( array_unique( array_merge( array_keys( $x ), array_keys( $y ) ) ) as $k ) {
				if ( ! array_key_exists( $k, $x ) ) {
					return $path . '/' . $k . '：旧的没有，新的是 ' . substr( (string) wp_json_encode( $y[ $k ], JSON_UNESCAPED_UNICODE ), 0, 300 );
				}
				if ( ! array_key_exists( $k, $y ) ) {
					return $path . '/' . $k . '：新的没有，旧的是 ' . substr( (string) wp_json_encode( $x[ $k ], JSON_UNESCAPED_UNICODE ), 0, 300 );
				}
				$d = $first( $x[ $k ], $y[ $k ], $path . '/' . $k );
				if ( null !== $d ) {
					return $d;
				}
			}
			return null;
		}
		if ( $x === $y ) {
			return null;
		}
		$sx = is_string( $x ) ? $x : (string) wp_json_encode( $x, JSON_UNESCAPED_UNICODE );
		$sy = is_string( $y ) ? $y : (string) wp_json_encode( $y, JSON_UNESCAPED_UNICODE );
		$i  = 0;
		$n  = min( strlen( $sx ), strlen( $sy ) );
		while ( $i < $n && $sx[ $i ] === $sy[ $i ] ) {
			++$i;
		}
		$from = max( 0, $i - 80 );
		return $path . '：第 ' . $i . ' 个字节起不同' . "\n      旧：…" . substr( $sx, $from, 240 ) . "\n      新：…" . substr( $sy, $from, 240 );
	};
	$same = 0;
	$diff = 0;
	foreach ( array_unique( array_merge( array_keys( $a['items'] ?? array() ), array_keys( $b['items'] ?? array() ) ) ) as $key ) {
		$d = $first( $a['items'][ $key ] ?? '(没有这一项)', $b['items'][ $key ] ?? '(没有这一项)' );
		if ( null === $d ) {
			++$same;
			continue;
		}
		++$diff;
		echo 'DIFF ', $key, "\n   ", $d, "\n";
	}
	echo "旧：{$a['meta']['code']}　新：{$b['meta']['code']}\n";
	echo "结果：{$same} 项一样，{$diff} 项不一样\n";
	exit( $diff ? 1 : 0 );
}

if ( 'run' !== $m6x_cmd || empty( $args[1] ) ) {
	echo "用法：run <输出.json> [起始日期 结束日期] ｜ compare <旧.json> <新.json>（说明见文件开头）\n";
	exit( 0 );
}

// ---------- run ----------
global $wpdb;
$m6x_out   = (string) $args[1];
$m6x_today = wp_date( 'Y-m-d' );
$m6x_from  = (string) ( $args[2] ?? wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) );
$m6x_to    = (string) ( $args[3] ?? $m6x_today );
$m6x_mode  = (string) getenv( 'M6_MODE' );
if ( in_array( $m6x_mode, array( 'off', 'shadow', 'on' ), true ) ) {
	add_filter( 'pre_option_peptide_core_m6_mode', static fn() => $m6x_mode, PHP_INT_MAX );
}
add_filter( 'pre_wp_mail', '__return_false' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'm6_snapshot', 'blocked' ), PHP_INT_MAX );

$m6x_items = array();
$m6x_put   = static function ( string $key, $value ) use ( &$m6x_items ): void {
	$m6x_items[ $key ] = $value;
};
/** 去掉每次都会变的东西。 */
$m6x_clean = static function ( $value ) use ( &$m6x_clean ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( in_array( (string) $k, array( 'nonce', '_wpnonce', 'restNonce', 'generated_at', 'generated_ms', 'now_ms', 'server_ms', 'cached_at', 'now', 'updated' ), true ) ) {
				$v = '(变动值)';
			}
			$out[ $k ] = $m6x_clean( $v );
		}
		return $out;
	}
	if ( is_string( $value ) ) {
		$value = (string) preg_replace( '/([?&]ver=)[^&"\'\s]+/', '$1*', $value );
		$value = (string) preg_replace( '/("(?:nonce|_wpnonce|restNonce)"\s*:\s*")[^"]*"/', '$1*"', $value );
		$value = (string) preg_replace( '/(_wpnonce=)[0-9a-f]{10}/', '$1*', $value );
		$value = (string) preg_replace( '/(name="_wpnonce" value=")[0-9a-f]{10}/', '$1*', $value );
		$value = (string) preg_replace( '/("(?:now|updated)"\s*:\s*")[0-9: -]{19}"/', '$1*"', $value ); // 页面配置里内嵌的“现在几点”。
	}
	return $value;
};
/** 报表自己的缓存：读之前删掉，保证读到的是现算的数（不碰别的选项）。 */
$m6x_flush = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pc\\_%' OR option_name LIKE '\\_transient\\_peptide%' OR option_name LIKE '\\_transient\\_timeout\\_peptide%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
	if ( class_exists( Metrics::class ) && method_exists( Metrics::class, 'flush' ) ) {
		Metrics::flush();
	}
};

// 0. 设置、版本、表。
$m6x_tables = array();
foreach ( array( 'pc_track_visitors', 'pc_track_visits', 'pc_track_events', 'pc_track_tabs', 'pc_track_ads', 'pc_px_log' ) as $name ) {
	$m6x_tables[ $name ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $name ) );
}
$m6x_options = array();
foreach ( array( 'peptide_core_m6_mode', 'peptide_core_tracking', 'peptide_core_tracking_db', 'peptide_core_track_ads_db', 'peptide_core_track_ads', 'peptide_core_buyer_data_layout', 'peptide_core_daily_abandoned', 'pc_product_activity', 'pc_product_activity_min' ) as $name ) {
	$v = get_option( $name, '(没有)' );
	if ( is_array( $v ) ) {
		unset( $v['changed_ms'] );
	}
	$m6x_options[ $name ] = $v;
}
$m6x_put( 'settings/options', $m6x_options );
$m6x_put( 'settings/tables', $m6x_tables );

$m6x_admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) )[0] ?? null;
$m6x_buyer = get_users( array( 'role' => 'pc_media_buyer', 'number' => 1, 'orderby' => 'ID' ) )[0] ?? null;
$m6x_cust  = get_users( array( 'role' => 'customer', 'number' => 1, 'orderby' => 'ID' ) )[0] ?? null;
$m6x_who   = array_filter( array( 'admin' => $m6x_admin, 'buyer' => $m6x_buyer ) );

// 1. 接口。
$m6x_routes = array();
foreach ( array_keys( rest_get_server()->get_routes( 'peptide/v1' ) ) as $route ) {
	// 总后台 AI 分析（track/admin/ai/*）不归 M6 / M7，不读。
	if ( preg_match( '#^/peptide/v1/(data/|overview|track/admin|product-activity)#', $route ) && ! str_contains( $route, '(?P' ) && ! str_starts_with( $route, '/peptide/v1/track/admin/ai' ) ) {
		$m6x_routes[] = $route;
	}
}
sort( $m6x_routes );
$m6x_put( 'rest/routes', $m6x_routes );
/** GET 一个接口；接口自己抛错时记下错误（新旧代码同样报错也算“一样”），不让整次运行停下。 */
$m6x_get = static function ( string $route, array $params ) use ( $m6x_clean ): array {
	try {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		$response = rest_do_request( $request );
		return $m6x_clean( array( 'status' => $response->get_status(), 'data' => rest_get_server()->response_to_data( $response, false ) ) );
	} catch ( \Throwable $e ) {
		return array( 'status' => 'ERROR', 'data' => get_class( $e ) . ': ' . preg_replace( '#/[^ ]*/(wp-content|wp-includes)/#', '$1/', $e->getMessage() ) );
	}
};
/** 调一个函数；抛错时记下错误。 */
$m6x_try = static function ( callable $fn ) {
	try {
		return $fn();
	} catch ( \Throwable $e ) {
		return 'ERROR ' . get_class( $e ) . ': ' . preg_replace( '#/[^ ]*/(wp-content|wp-includes)/#', '$1/', $e->getMessage() );
	}
};
$m6x_product = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$m6x_ranges  = array( 'today' => array(), 'period' => array( 'from' => $m6x_from, 'to' => $m6x_to ) );
foreach ( $m6x_who as $who => $user ) {
	wp_set_current_user( (int) $user->ID );
	foreach ( $m6x_routes as $route ) {
		foreach ( $m6x_ranges as $rname => $params ) {
			if ( str_contains( $route, 'realtime' ) || str_contains( $route, 'live' ) ) {
				if ( 'period' === $rname ) {
					continue;
				}
			}
			$m6x_flush();
			$m6x_put( "rest/{$who}/{$rname}{$route}", $m6x_get( $route, $params + ( str_contains( $route, 'product-activity' ) ? array( 'product_id' => $m6x_product ) : array() ) ) );
		}
	}
	// 分组的几种读法（来源页的下拉、转化详情的分组）。
	foreach ( array( '/peptide/v1/data/sources' => array( array( 'group_by' => 'channel' ), array( 'split_medium' => 1 ), array( 'group_by' => 'campaign' ) ), '/peptide/v1/data/conversion' => array( array( 'group' => 'country' ), array( 'group' => 'device', 'visitor_status' => 'normal' ) ) ) as $route => $variants ) {
		foreach ( $variants as $variant ) {
			$m6x_flush();
			$m6x_put( "rest/{$who}/variant{$route}?" . http_build_query( $variant ), $m6x_get( $route, $variant + array( 'from' => $m6x_from, 'to' => $m6x_to ) ) );
		}
	}
}

// 2. 后台页面：页面外壳和交给页面脚本的配置。
$m6x_scripts = static function (): array {
	$out = array();
	foreach ( wp_scripts()->queue as $handle ) {
		if ( ! str_starts_with( $handle, 'peptide-core' ) && ! str_starts_with( $handle, 'pc-' ) ) {
			continue;
		}
		$reg            = wp_scripts()->registered[ $handle ] ?? null;
		$out[ $handle ] = array(
			'src'    => $reg ? (string) $reg->src : '',
			'deps'   => $reg ? $reg->deps : array(),
			'before' => $reg ? implode( "\n", (array) ( $reg->extra['before'] ?? array() ) ) : '',
			'after'  => $reg ? implode( "\n", (array) ( $reg->extra['after'] ?? array() ) ) : '',
			'data'   => $reg ? (string) ( $reg->extra['data'] ?? '' ) : '',
		);
	}
	foreach ( wp_styles()->queue as $handle ) {
		if ( str_starts_with( $handle, 'peptide-core' ) ) {
			$out[ 'style:' . $handle ] = (string) ( wp_styles()->registered[ $handle ]->src ?? '' );
		}
	}
	ksort( $out );
	return $out;
};
$m6x_page = static function ( array $get, callable $enqueue, callable $render ) use ( $m6x_scripts, $m6x_flush, $m6x_clean ): array {
	$m6x_flush();
	$_GET                   = $get;
	$_REQUEST               = $get;
	$GLOBALS['wp_scripts']  = null;
	$GLOBALS['wp_styles']   = null;
	wp_scripts();
	wp_styles();
	try {
		$enqueue();
		ob_start();
		$render();
		$html = (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		while ( ob_get_level() > 1 ) {
			ob_end_clean();
		}
		$html = 'ERROR ' . get_class( $e ) . ': ' . $e->getMessage();
	}
	return $m6x_clean( array( 'html' => $html, 'scripts' => $m6x_scripts() ) );
};
if ( ! function_exists( 'get_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
foreach ( $m6x_who as $who => $user ) {
	wp_set_current_user( (int) $user->ID );
	if ( class_exists( Data_Pages::class ) ) {
		$slugs = array_values( Data_Pages::PAGES );
		$merged = defined( Data_Pages::class . '::BUYER_PAGES' ) ? Data_Pages::BUYER_PAGES : array();
		if ( defined( Data_Pages::class . '::ADMIN_PAGES' ) ) {
			$merged += Data_Pages::ADMIN_PAGES; // 主线 11-14：管理员的“页面与落地页”。
		}
		if ( $merged ) {
			foreach ( $merged as $slug => $tabs ) {
				$slugs[] = $slug;
				foreach ( array_keys( $tabs ) as $tab ) {
					$slugs[] = $slug . '&tab=' . $tab;
				}
			}
		}
		foreach ( $slugs as $slug ) {
			parse_str( 'page=' . $slug, $get );
			$m6x_put( "page/{$who}/{$slug}", $m6x_page( $get, array( Data_Pages::class, 'enqueue' ), array( Data_Pages::class, 'render' ) ) );
		}
	}
	// 主线 11-14 的复盘页、今日作战室（只有服务端输出，没有单独的脚本配置）。
	foreach ( array( '\\PeptideCore\\Admin\\Data_Review', '\\PeptideCore\\Admin\\Data_Cockpit' ) as $class ) {
		if ( class_exists( $class ) ) {
			$m6x_put( "page/{$who}/" . $class::SLUG, $m6x_page( array( 'page' => $class::SLUG ), static function () {}, array( $class, 'render' ) ) );
		}
	}
	if ( class_exists( Overview_Page::class ) ) {
		$m6x_put( "page/{$who}/" . Overview_Page::SLUG, $m6x_page( array( 'page' => Overview_Page::SLUG ), static fn() => Overview_Page::enqueue( 'toplevel_page_' . Overview_Page::SLUG ), array( Overview_Page::class, 'render' ) ) );
	}
	if ( class_exists( Tracking_Page::class ) ) {
		foreach ( array( Tracking_Page::SLUG, Tracking_Page::STATS_SLUG, Tracking_Page::ARCHIVE_SLUG ) as $slug ) {
			$m6x_put( "page/{$who}/{$slug}", $m6x_page( array( 'page' => $slug ), static fn() => Tracking_Page::enqueue( 'toplevel_page_' . $slug ), array( Tracking_Page::class, 'render' ) ) );
		}
	}
	if ( class_exists( Pixels_Page::class ) && class_exists( \PeptideCore\Admin\Store_Settings::class ) ) {
		$get = array( 'page' => \PeptideCore\Admin\Store_Settings::SYSTEM_SLUG, 'section' => Pixels_Page::SECTION );
		$m6x_put( "page/{$who}/pixels", $m6x_page( $get, array( Pixels_Page::class, 'enqueue' ), array( Pixels_Page::class, 'render' ) ) );
	}
}
$_GET     = array();
$_REQUEST = array();

// 3. 像素。
if ( class_exists( Pixels::class ) ) {
	$s    = Pixels::settings();
	$mask = static function ( $value ) use ( &$mask ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = ( 'token' === $k && is_string( $v ) && '' !== $v ) ? 'sha256:' . substr( hash( 'sha256', $v ), 0, 12 ) : $mask( $v );
			}
			return $out;
		}
		return $value;
	};
	$m6x_put( 'pixels/settings', $mask( $s ) );
	$m6x_put( 'pixels/browser_config', $m6x_clean( Pixels::browser_config( $s, array() ) ) );
	$allowed = array();
	foreach ( array( 'anonymous' => 0, 'admin' => $m6x_admin->ID ?? 0, 'buyer' => $m6x_buyer->ID ?? 0, 'customer' => $m6x_cust->ID ?? 0 ) as $who => $id ) {
		if ( 'anonymous' !== $who && ! $id ) {
			continue;
		}
		wp_set_current_user( (int) $id );
		$allowed[ $who ] = Pixels::allowed();
	}
	$m6x_put( 'pixels/allowed', $allowed );
	$events = array();
	foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => 30, 'orderby' => 'ID', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
		$events[ $order->get_id() ] = array(
			'status'     => $order->get_status(),
			'event'      => Pixels::order_event( $order ),
			'test_order' => method_exists( Pixels::class, 'is_test_order' ) ? Pixels::is_test_order( $order ) : null,
			'sent'       => (bool) $order->get_meta( '_pc_px_sent' ) || (bool) $order->get_meta( Pixels\Server::ORDER_SENT ),
			'optout'     => (string) $order->get_meta( '_pc_px_optout' ),
		);
	}
	$m6x_put( 'pixels/order_events', $events );
	$log = $wpdb->get_results( 'SELECT platform, event, ok, code FROM ' . $wpdb->prefix . 'pc_px_log ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$m6x_put( 'pixels/log_summary', array_count_values( array_map( static fn( $r ) => implode( '|', $r ), (array) $log ) ) );
}

// 4. 指标。
wp_set_current_user( (int) ( $m6x_admin->ID ?? 0 ) );
if ( class_exists( Metrics::class ) ) {
	$tz = wp_timezone();
	foreach ( array( 'today' => array( $m6x_today, $m6x_today ), 'period' => array( $m6x_from, $m6x_to ) ) as $rname => [ $f, $t ] ) {
		$from = ( new DateTimeImmutable( $f . ' 00:00:00', $tz ) )->getTimestamp();
		$to   = ( new DateTimeImmutable( $t . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp();
		$m6x_flush();
		$m6x_put( "metrics/{$rname}/summary", $m6x_try( static fn() => Metrics::summary( $from, $to ) ) );
		$m6x_put( "metrics/{$rname}/funnel", $m6x_try( static fn() => Metrics::funnel( $from, $to ) ) );
		$m6x_put( "metrics/{$rname}/daily", $m6x_try( static fn() => Metrics::daily( $from, $to ) ) );
		if ( class_exists( \PeptideCore\Hq_Connector::class ) && method_exists( \PeptideCore\Hq_Connector::class, 'with_abandoned' ) ) {
			$rows = array();
			for ( $d = $from; $d < $to; $d += DAY_IN_SECONDS ) {
				$rows[ wp_date( 'Y-m-d', $d ) ] = array( 'day' => wp_date( 'Y-m-d', $d ), 'orders' => 0 );
			}
			$m6x_put( "hq/{$rname}/with_abandoned", $m6x_try( static fn() => \PeptideCore\Hq_Connector::with_abandoned( $rows, $from, $to ) ) );
		}
	}
}

$m6x_code = defined( 'PEPTIDE_CORE_VERSION' ) ? PEPTIDE_CORE_VERSION : '?';
$m6x_code .= ' ' . substr( md5_file( PEPTIDE_CORE_DIR . '/includes/data/class-metrics.php' ) . '', 0, 8 );
ksort( $m6x_items );
$m6x_json = wp_json_encode(
	array(
		'meta'  => array( 'code' => $m6x_code, 'mode' => get_option( 'peptide_core_m6_mode', 'off' ), 'today' => $m6x_today, 'period' => array( $m6x_from, $m6x_to ), 'errors' => (string) $wpdb->last_error ),
		'items' => $m6x_items,
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
file_put_contents( $m6x_out, $m6x_json );
echo '写好了：', $m6x_out, '（', count( $m6x_items ), ' 项，代码 ', $m6x_code, '，开关 ', get_option( 'peptide_core_m6_mode', 'off' ), "）\n";
