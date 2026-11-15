<?php
/**
 * M3 / M10 发布前比对（店铺）：把订单上报的摘要、升级后会不会重报、回填试运行的数字、系列状态和商品导出原样记下来。
 *
 * 用法：同一份数据，先用线上版代码跑一遍，换成发布版代码再跑一遍，然后 diff -r 两个目录。
 *   wp eval-file wp-content/plugins/peptide-core/tests/m3-m10-compare-dump.php <输出目录>
 *
 * 只读：不写数据库（会数出脚本期间的写入语句，写在 meta.json 里），不发网络请求。
 * 导出商品时，线上代码会把每张商品图片的哈希缓存进附件元 _pc_series_sha256（只在导出商品目录时发生，平时打开页面不会）；
 * 本脚本在取数期间拦住这条缓存写入，导出的内容不受影响。
 *
 * “升级后会重报”的订单：上次上报留下的指纹和现在的摘要对不上。摘要里有邮箱哈希和订单号（hang- 付款参考号），
 * 所以在脱敏快照上，被改写过邮箱或付款参考号的订单会被算进来——线上版、发布版一样多。比对时看两次是否相同，
 * 不是看是不是 0；orders_that_would_be_reported_again_list 列出了是哪几张。
 * 输出里没有顾客资料：订单只记编号、金额、状态和摘要的指纹，摘要里的邮箱哈希只记“有 / 没有”。
 *
 * @package PeptideCore
 */

use PeptideCore\Order_Report as R;
use PeptideCore\Series_Sync as S;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$out = rtrim( (string) ( $args[0] ?? '' ), '/' );
if ( '' === $out || ! wp_mkdir_p( $out ) ) {
	WP_CLI::error( '用法：wp eval-file m3-m10-compare-dump.php <输出目录>' );
}
add_filter( 'pre_http_request', static fn() => new WP_Error( 'compare_blocked', 'blocked during compare' ), 1 );
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

// 导出商品会顺手缓存图片哈希（线上代码的老行为）；取数期间不让它写。
$no_hash_cache = static fn( $check, $object_id, $meta_key ) => '_pc_series_sha256' === $meta_key ? false : $check;
add_filter( 'add_post_metadata', $no_hash_cache, 1, 3 );
add_filter( 'update_post_metadata', $no_hash_cache, 1, 3 );

$data = array();

// ---- 订单上报：每张订单的摘要、指纹，和上次上报的指纹比（不一样 = 升级后会重报一次） ----
$orders = wc_get_orders( array( 'limit' => 300, 'orderby' => 'ID', 'order' => 'ASC', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) );
$data['orders'] = array();
$rereport       = 0;
foreach ( $orders as $order ) {
	$s    = R::summary( $order );
	$fp   = R::fingerprint( $s );
	$last = R::parse_reported( (string) $order->get_meta( R::META ) );
	$s['email_hash'] = '' !== (string) $s['email_hash'] ? '(有)' : '(没有)';
	$again = '' !== $last['fingerprint'] && $last['fingerprint'] !== $fp;
	$rereport += $again ? 1 : 0;
	$data['orders'][] = array(
		'summary'          => $s,
		'fingerprint'      => $fp,
		'reported_before'  => '' !== $last['fingerprint'],
		'would_report_again' => $again,
	);
}
$data['orders_that_would_be_reported_again'] = $rereport;
$data['orders_that_would_be_reported_again_list'] = array_values(
	array_map(
		static fn( $o ) => array( 'store_order_id' => $o['summary']['store_order_id'], 'order_number' => $o['summary']['order_number'], 'status' => $o['summary']['status'], 'is_test' => $o['summary']['is_test'] ),
		array_filter( $data['orders'], static fn( $o ) => $o['would_report_again'] )
	)
);

// ---- 回填试运行（只数，不发） ----
$bf = R::backfill( true );
unset( $bf['errors'] );
$data['backfill_dry_run'] = $bf;

// ---- 系列：状态、导出的商品、拿自己的导出和自己比 ----
$state = S::state();
unset( $state['synced_at'] );
$data['series_state'] = $state;
$export               = S::export_catalog();
unset( $export['exported_at'] );
$data['catalog_export'] = array(
	'source_host'   => $export['source_host'],
	'product_count' => count( $export['products'] ),
	'products_md5'  => md5( (string) wp_json_encode( $export['products'] ) ),
	'skus'          => array_column( $export['products'], 'sku' ),
	'shipping'      => $export['shipping'],
	'campaigns'     => $export['campaigns'],
);
$clean                 = S::clean_catalog( array( 'ver' => 1, 'source_host' => $export['source_host'], 'products' => $export['products'] ) );
$data['self_diff']     = is_array( $clean ) ? S::diff_catalog( $clean['products'], S::export_local(), 0 )['summary'] : $clean;
$data['managed_items'] = count( array_filter( S::export_local(), static fn( $l ) => ! empty( $l['managed'] ) ) );

$meta = array(
	'versions' => array(
		'PEPTIDE_CORE_VERSION'        => defined( 'PEPTIDE_CORE_VERSION' ) ? PEPTIDE_CORE_VERSION : '',
		'peptide_core_series'         => get_option( S::OPTION, '(没有)' ),
		'pc_order_report_customer_id' => get_option( 'pc_order_report_customer_id', '(没有这个选项)' ),
	),
	'writes_during_dump' => $writes,
);
$json = static fn( $v ): string => (string) wp_json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
file_put_contents( "$out/data.json", $json( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( "$out/meta.json", $json( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
WP_CLI::success( sprintf( '已写到 %s：%d 张订单，其中升级后会重报的 %d 张；脚本期间写入语句 %d 条。', $out, count( $data['orders'] ), $rereport, count( $writes ) ) );
