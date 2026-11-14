<?php
/**
 * M3 / M10 发布前比对（店铺）的固定数据：用线上版（11-10）的代码建几张订单并全部上报一次，
 * 这样再换发布版代码时，能看出升级会不会让订单重报、摘要有没有变。
 *
 *   PC_SMOKE_THROWAWAY=1 wp eval-file wp-content/plugins/peptide-core/tests/m3-m10-compare-seed.php
 *
 * 会建订单：只能在一次性的本地副本上跑（WP_ENVIRONMENT_TYPE=local 并设 PC_SMOKE_THROWAWAY=1）。
 * 发往总后台的请求全部在本机截住、当作接收成功，不出网。
 *
 * @package PeptideCore
 */

use PeptideCore\Order_Report as R;
use PeptideCore\Series_Sync;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( 'local' !== wp_get_environment_type() || '1' !== getenv( 'PC_SMOKE_THROWAWAY' ) ) {
	WP_CLI::error( '这个脚本会建订单，只能在一次性的本地副本上跑（WP_ENVIRONMENT_TYPE=local 并设 PC_SMOKE_THROWAWAY=1）。' );
}
if ( ! R::enabled() ) {
	// 店铺第一次用时才生成连接密钥和编号（和订单冒烟测试一样）。
	PeptideCore\Hq_Connector::key();
	PeptideCore\Hq_Connector::uuid();
}
if ( ! R::enabled() ) {
	WP_CLI::error( '店铺副本的 wp-config.php 里需要 PEPTIDE_HQ_URL 和 64 位的 PEPTIDE_HQ_SECRET（测试值即可）。' );
}
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		// 发往总后台（PEPTIDE_HQ_URL 的域名）的订单上报：当作全部接收。网址里的路径是编码过的，按域名认。
		if ( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) === (string) wp_parse_url( (string) PEPTIDE_HQ_URL, PHP_URL_HOST ) && ! empty( $body['orders'] ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'accepted' => array_column( (array) ( $body['orders'] ?? array() ), 'store_order_id' ), 'rejected' => array() ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return new WP_Error( 'seed_blocked', 'blocked during seed' );
	},
	99,
	3
);
add_filter( 'pre_wp_mail', '__return_true' );

update_option( Series_Sync::OPTION, array( 'series_id' => 1, 'slug' => 'series-1', 'name' => '系列 1' ), false );
$ids = wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 1, 'return' => 'ids' ) );
if ( ! $ids ) {
	WP_CLI::error( '店里没有已发布的简单商品。' );
}
// 本地副本里的商品是演示数据，含演示商品的订单会被当成测试单：这份一次性副本里摘掉这个标记。
delete_post_meta( (int) $ids[0], PeptideCore\Meta::DEMO_SEED );

$make = static function ( string $email, string $status, int $qty, array $meta = array() ) use ( $ids ): int {
	$order = wc_create_order();
	$order->add_product( wc_get_product( (int) $ids[0] ), $qty );
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Compare' );
	$order->set_billing_last_name( 'Buyer' );
	$order->set_billing_address_1( '1 Test Street' );
	$order->set_billing_phone( '5550100' );
	foreach ( $meta as $k => $v ) {
		$order->update_meta_data( $k, $v );
	}
	$order->calculate_totals();
	$order->save();
	if ( 'pending' !== $status ) {
		$order->update_status( $status );
	}
	return (int) $order->get_id();
};
$made = array(
	$make( 'Buyer.One@Example.Test', 'processing', 1 ),
	$make( 'buyer.two@example.test', 'processing', 2, array( '_pc_pp_ref' => 'hang-161188301' ) ),
	$make( 'buyer.two@example.test', 'completed', 1, array( '_pc_pp_ref' => 'hang-161188302' ) ),
	$make( 'test@example.test', 'processing', 1, array( '_pc_test_order' => '1' ) ),
	$make( 'unpaid@example.test', 'pending', 1 ),
	$make( 'cancel@example.test', 'cancelled', 1 ),
);
$res = R::backfill( false );
WP_CLI::success( sprintf( '建了 %d 张订单（%s）；用线上版代码全部上报一次：发出 %d 笔，接收 %d 笔。', count( $made ), implode( ', ', $made ), (int) $res['sent'], (int) $res['accepted'] ) );
