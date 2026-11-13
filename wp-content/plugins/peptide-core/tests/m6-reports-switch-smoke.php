<?php
/**
 * NF-02 报表三档冒烟：在一家店（沙盒或脱敏快照）上，把概览页和各张数据报表在 关 / 影子 / 开 三档下各跑一遍。
 *
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-reports-switch-smoke.php          # 今天、近 7 天、近 30 天
 *   wp eval-file wp-content/plugins/peptide-core/tests/m6-reports-switch-smoke.php 30d      # 只跑一个时间段
 *
 * 不改店铺的开关：换档用的是这一次运行里的过滤器（peptide_core_m6_mode），选项 peptide_core_m6_mode 不动；
 * 影子档记到的差异只打印、不写进对账日志。会留下报表自己的几条短期缓存（瞬态，最长 5 分钟）。
 *
 * 通过标准（退出码 0）：
 *   1. 每张报表在三档下都正常返回（HTTP 200），peptide-core 的代码没有 PHP 警告，没有数据库报错；
 *   2. 影子档的输出和关着时逐字节相同（实时数据除外：它带当前时间；概览页只比数字部分，不比问候语和当前时间）；
 *   3. 开着时：每个漏斗每一步都不大于上一步；概览页、运营数据的订单数和销售额等于 Data\Metrics；
 *      排行“下单支付方式”各行的订单数加起来等于 Data\Metrics 的订单数。
 * 另外按指标打印影子档记到的差异，给人看“切到开之后哪些数会变、变多少”——这部分不影响退出码。
 */

use PeptideCore\Data\Metrics;
use PeptideCore\Data\Metrics_Overlay;
use PeptideCore\Data\Metrics_Switch;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Metrics_Switch::class ) || ! function_exists( 'rest_do_request' ) ) {
	fwrite( STDERR, "请用 wp eval-file 在店铺目录里运行，并确认 peptide-core 已启用。\n" );
	exit( 1 );
}

$m6s_say = static function ( string $text ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::log( $text );
	} else {
		echo $text, "\n";
	}
};

// 用一个能看数据页的账号调接口。
if ( ! current_user_can( 'manage_woocommerce' ) ) {
	$m6s_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) ( $m6s_admins[0] ?? 0 ) );
}
if ( ! current_user_can( 'manage_woocommerce' ) ) {
	fwrite( STDERR, "找不到管理员账号；请加 --user=<管理员账号> 再运行。\n" );
	exit( 1 );
}

// 这一次运行里临时换档；店铺的选项不动。
$m6s_stored = Metrics_Switch::mode();
$m6s_mode   = 'off';
add_filter(
	'peptide_core_m6_mode',
	static function () use ( &$m6s_mode ) {
		return $m6s_mode;
	},
	PHP_INT_MAX
);

// peptide-core 自己的 PHP 警告算失败；别的插件和 WordPress 的不管。
$m6s_warnings = array();
set_error_handler(
	static function ( int $level, string $message, string $file = '', int $line = 0 ) use ( &$m6s_warnings ): bool {
		if ( str_contains( $file, 'peptide-core' ) && ! str_contains( $file, 'm6-reports-switch-smoke' ) ) {
			$m6s_warnings[] = basename( $file ) . ':' . $line . ' ' . $message;
		}
		return false;
	}
);

$m6s_reports = array(
	'概览页'               => array( '/peptide/v1/overview', array() ),
	'运营数据'             => array( '/peptide/v1/data/operations', array() ),
	'趋势'                 => array( '/peptide/v1/data/trends', array() ),
	'排行'                 => array( '/peptide/v1/data/rankings', array() ),
	'商品详情'             => array( '/peptide/v1/data/products', array() ),
	'转化详情（设备）'     => array( '/peptide/v1/data/conversion', array( 'group' => 'device' ) ),
	'转化详情（国家）'     => array( '/peptide/v1/data/conversion', array( 'group' => 'country' ) ),
	'转化详情（正常访客）' => array( '/peptide/v1/data/conversion', array( 'group' => 'device', 'visitor_status' => 'normal' ) ),
	'落地页'               => array( '/peptide/v1/data/landing', array() ),
	'来源'                 => array( '/peptide/v1/data/sources', array() ),
	'页面访问'             => array( '/peptide/v1/data/pages', array() ),
	'页面转化'             => array( '/peptide/v1/data/page-conversion', array() ),
	'结账步骤'             => array( '/peptide/v1/data/checkout-steps', array() ),
	'实时数据'             => array( '/peptide/v1/data/realtime', array() ),
);
$m6s_volatile = array( '实时数据' ); // 带当前时间，两次调用本来就不会逐字节相同。

/** 调一张报表：[ 状态码, 数据, 数据库报错 ]。 */
$m6s_call = static function ( string $route, array $params ): array {
	global $wpdb;
	$wpdb->last_error = '';
	Metrics::flush();
	Metrics_Overlay::flush();
	$request = new \WP_REST_Request( 'GET', $route );
	$request->set_query_params( $params );
	try {
		$response = rest_do_request( $request );
		return array( (int) $response->get_status(), $response->get_data(), (string) $wpdb->last_error );
	} catch ( \Throwable $e ) {
		return array( 500, array( 'exception' => $e->getMessage() ), (string) $wpdb->last_error );
	}
};
/** 比较“影子 = 关”时用的部分：概览页的问候语、当前时间、在线人数每分钟都在变，只比数字那一半（range）和弃单激活。 */
$m6s_stable = static function ( string $name, $data ) {
	if ( '概览页' === $name && is_array( $data ) ) {
		return array( 'range' => $data['range'] ?? null, 'recovery' => $data['summary']['recovery'] ?? null );
	}
	return $data;
};
/** 一串漏斗层的人数是不是一步不大于一步。 */
$m6s_descending = static function ( $layers ): bool {
	$previous = null;
	foreach ( is_array( $layers ) ? $layers : array() as $layer ) {
		$count = (int) ( is_array( $layer ) ? ( $layer['count'] ?? 0 ) : 0 );
		if ( null !== $previous && $count > $previous ) {
			return false;
		}
		$previous = $count;
	}
	return true;
};

$m6s_presets = isset( $args[0] ) && in_array( $args[0], array( 'today', 'yesterday', '7d', '30d' ), true ) ? array( $args[0] ) : array( 'today', '7d', '30d' );
$m6s_fail    = array();
$m6s_diffs   = array();
$m6s_checks  = 0;

$m6s_say( '店铺现在的开关：' . $m6s_stored . '（这次运行不改它）　口径版本：' . Metrics::VERSION );

foreach ( $m6s_presets as $m6s_preset ) {
	$m6s_say( '' );
	$m6s_say( '== ' . $m6s_preset . ' ==' );
	$m6s_out = array();
	foreach ( array( 'off', 'shadow', 'on' ) as $m6s_mode ) {
		Metrics_Switch::discard_pending();
		foreach ( $m6s_reports as $m6s_name => [ $m6s_route, $m6s_extra ] ) {
			[ $m6s_status, $m6s_data, $m6s_db ] = $m6s_call( $m6s_route, array( 'range' => $m6s_preset ) + $m6s_extra );
			$m6s_out[ $m6s_mode ][ $m6s_name ]   = $m6s_data;
			++$m6s_checks;
			if ( 200 !== $m6s_status ) {
				$m6s_fail[] = "{$m6s_preset} / {$m6s_name} / {$m6s_mode}：返回 {$m6s_status} " . substr( (string) wp_json_encode( $m6s_data ), 0, 160 );
			}
			if ( '' !== $m6s_db ) {
				$m6s_fail[] = "{$m6s_preset} / {$m6s_name} / {$m6s_mode}：数据库报错 " . substr( $m6s_db, 0, 200 );
			}
			if ( '概览页' === $m6s_name && 200 === $m6s_status ) {
				// 概览页的接口有 30 秒缓存（关着时一份，其余每档各一份）。刚有别的用例改过订单或访客时，
				// 某一档的缓存里还是半分钟前的数字，和另一档现算的对不上，会误报“影子档和关着时不一样”。
				// 所以状态码看接口，拿来比较的数字不走缓存、直接现算。
				Metrics::flush();
				Metrics_Overlay::flush();
				$m6s_out[ $m6s_mode ][ $m6s_name ] = \PeptideCore\Admin\Overview_Page::data( $m6s_preset );
			}
		}
		// 这一档记到的对账结果：只留着打印，不写进日志。
		foreach ( Metrics_Switch::discard_pending() as $m6s_item ) {
			if ( 'shadow' === $m6s_mode && $m6s_item['diffs'] ) {
				// 同一处差异只留一条（概览页接口算一次、上面现算又一次，记到的是同一条）。
				$m6s_diffs[ $m6s_preset ][ $m6s_item['metric'] ][ md5( (string) wp_json_encode( array( $m6s_item['context'], $m6s_item['diffs'] ) ) ) ] = $m6s_item;
			}
		}
	}

	// 2. 影子 = 关。
	foreach ( $m6s_reports as $m6s_name => $m6s_unused ) {
		if ( in_array( $m6s_name, $m6s_volatile, true ) ) {
			continue;
		}
		++$m6s_checks;
		if ( wp_json_encode( $m6s_stable( $m6s_name, $m6s_out['off'][ $m6s_name ] ) ) !== wp_json_encode( $m6s_stable( $m6s_name, $m6s_out['shadow'][ $m6s_name ] ) ) ) {
			$m6s_fail[] = "{$m6s_preset} / {$m6s_name}：影子档的输出和关着时不一样";
		}
	}

	// 3. 开着时的不变量。
	$m6s_mode   = 'on';
	Metrics::flush();
	$m6s_window = Metrics::window( $m6s_preset );
	$m6s_orders = Metrics::orders( (int) $m6s_window['from'], (int) $m6s_window['to'] );
	$m6s_funnel = Metrics::funnel( (int) $m6s_window['from'], (int) $m6s_window['to'] );
	$m6s_on     = $m6s_out['on'];
	$m6s_expect = array(
		'概览页的漏斗每一步不大于上一步'       => $m6s_descending( $m6s_on['概览页']['range']['steps'] ?? array() ),
		'运营数据的漏斗每一步不大于上一步'     => $m6s_descending( $m6s_on['运营数据']['visitor_funnel']['current'] ?? array() ),
		'转化详情的漏斗每一步不大于上一步'     => $m6s_descending( $m6s_on['转化详情（设备）']['visitor_funnel']['current'] ?? array() ) && $m6s_descending( $m6s_on['转化详情（国家）']['visitor_funnel']['current'] ?? array() ) && $m6s_descending( $m6s_on['转化详情（正常访客）']['visitor_funnel']['current'] ?? array() ),
		'概览页的订单数 = Data\\Metrics'       => (int) ( $m6s_on['概览页']['range']['orders'] ?? -1 ) === (int) $m6s_orders['orders'],
		'概览页的销售额 = Data\\Metrics'       => Metrics_Switch::same( $m6s_on['概览页']['range']['sales'] ?? null, Metrics::money( (int) $m6s_orders['sales_cents'] ), 0.005 ),
		'运营数据的订单数 = Data\\Metrics'     => (int) ( $m6s_on['运营数据']['current']['orders'] ?? -1 ) === (int) $m6s_orders['orders'],
		'运营数据的销售额 = Data\\Metrics'     => Metrics_Switch::same( $m6s_on['运营数据']['current']['sales'] ?? null, Metrics::money( (int) $m6s_orders['sales_cents'] ), 0.005 ),
		'运营数据的访客数 = Data\\Metrics'     => (int) ( $m6s_on['运营数据']['current']['visitors'] ?? -1 ) === (int) $m6s_funnel['counts']['visitors'],
		'概览页的漏斗 = Data\\Metrics 的漏斗'  => array_column( (array) ( $m6s_on['概览页']['range']['steps'] ?? array() ), 'count' ) === array_values( $m6s_funnel['counts'] ),
		'运营数据的漏斗 = Data\\Metrics 的漏斗' => array_column( (array) ( $m6s_on['运营数据']['visitor_funnel']['current'] ?? array() ), 'count' ) === array_values( $m6s_funnel['counts'] ),
		'排行“下单支付方式”加起来 = Data\\Metrics 的订单数' => (int) array_sum( array_column( (array) ( $m6s_on['排行']['tables']['payment_methods']['rows'] ?? array() ), 'current' ) ) === (int) $m6s_orders['orders'],
	);
	foreach ( (array) ( $m6s_on['转化详情（设备）']['rows'] ?? array() ) as $m6s_row ) {
		$m6s_expect[ '转化详情“' . ( $m6s_row['key'] ?? '?' ) . '”一组的漏斗每一步不大于上一步' ] = $m6s_descending( $m6s_row['visitor_funnel']['layers'] ?? array() );
	}
	foreach ( $m6s_expect as $m6s_label => $m6s_ok ) {
		++$m6s_checks;
		if ( ! $m6s_ok ) {
			$m6s_fail[] = "{$m6s_preset} / 开：{$m6s_label} —— 不成立";
		}
	}
	$m6s_say( sprintf( '  关：订单 %d　销售额 %s　访客 %d', (int) ( $m6s_out['off']['运营数据']['current']['orders'] ?? 0 ), number_format( (float) ( $m6s_out['off']['运营数据']['current']['sales'] ?? 0 ), 2 ), (int) ( $m6s_out['off']['运营数据']['current']['visitors'] ?? 0 ) ) );
	$m6s_say( sprintf( '  开：订单 %d　销售额 %s　访客 %d　漏斗 %s', (int) $m6s_orders['orders'], number_format( $m6s_orders['sales_cents'] / 100, 2 ), (int) $m6s_funnel['counts']['visitors'], implode( ' → ', array_values( $m6s_funnel['counts'] ) ) ) );

	// 影子档记到的差异。
	if ( empty( $m6s_diffs[ $m6s_preset ] ) ) {
		$m6s_say( '  影子档没有记到差异：切到开之后这个时间段的数字不变。' );
	} else {
		$m6s_say( '  影子档记到的差异（切到开之后会变的地方）：' );
		foreach ( $m6s_diffs[ $m6s_preset ] as $m6s_metric => $m6s_items ) {
			$m6s_last  = end( $m6s_items );
			$m6s_parts = array();
			foreach ( array_slice( $m6s_last['diffs'], 0, 6 ) as $m6s_diff ) {
				$m6s_parts[] = $m6s_diff['path'] . ' ' . wp_json_encode( $m6s_diff['legacy'] ) . ' → ' . wp_json_encode( $m6s_diff['metrics'] );
			}
			$m6s_say( sprintf( '    %s（%d 处）：%s', $m6s_metric, count( $m6s_items ), implode( '；', $m6s_parts ) ) );
		}
	}
}
restore_error_handler();
$m6s_mode = $m6s_stored;
Metrics_Switch::discard_pending();
Metrics::flush();
Metrics_Overlay::flush();

foreach ( array_unique( $m6s_warnings ) as $m6s_warning ) {
	$m6s_fail[] = 'PHP 警告：' . $m6s_warning;
}

$m6s_say( '' );
if ( $m6s_fail ) {
	$m6s_say( '结果：没有通过（' . count( $m6s_fail ) . ' 处）。' );
	foreach ( array_slice( $m6s_fail, 0, 40 ) as $m6s_line ) {
		$m6s_say( '  - ' . $m6s_line );
	}
	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::halt( 1 );
	}
	exit( 1 );
}
$m6s_say( '结果：通过（' . $m6s_checks . ' 项检查）。三档都能正常出数，影子 = 关，开着时各报表和 Data\\Metrics 一致。' );
