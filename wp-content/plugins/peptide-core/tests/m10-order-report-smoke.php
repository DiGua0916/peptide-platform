<?php
/**
 * M10 订单上报与退款标记（HQ-13、OR-03）冒烟测试：在装了 WooCommerce 的店铺里用真订单跑一遍
 * 摘要 → 上报 → 重复上报 → hang- 订单号 → 测试单标记 → 补报 → 回填（试运行 / 正式 / 重复）→ 退款标记（部分 / 全额 / 撤销）→ 合计。
 *
 * 会新建几张订单并在结束时删除。发往总后台的请求全部被截住，不出网。只能在一次性的本地副本上跑，
 * 而且 wp-config.php 里要有 PEPTIDE_HQ_URL / PEPTIDE_HQ_SECRET（随便填测试值即可）：
 *   PC_SMOKE_THROWAWAY=1 wp eval-file wp-content/plugins/peptide-core/tests/m10-order-report-smoke.php
 * 可选：PC_SMOKE_OUT=<文件> 把上报的订单摘要写到文件，给总后台的冒烟测试当输入。
 */

use PeptideCore\Order_Report as R;
use PeptideCore\Series_Sync;

if ( 'local' !== wp_get_environment_type() || '1' !== getenv( 'PC_SMOKE_THROWAWAY' ) ) {
	WP_CLI::error( '这个测试会新建订单，只能在一次性的本地副本上跑（WP_ENVIRONMENT_TYPE=local 并设 PC_SMOKE_THROWAWAY=1）。' );
}
if ( ! R::enabled() ) {
	PeptideCore\Hq_Connector::key();
	PeptideCore\Hq_Connector::uuid();
}
if ( ! R::enabled() ) {
	WP_CLI::error( '店铺副本的 wp-config.php 里需要 PEPTIDE_HQ_URL 和 64 位的 PEPTIDE_HQ_SECRET（测试值即可）。' );
}

$checks = 0;
$check  = static function ( string $name, bool $ok, $detail = null ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		WP_CLI::error( 'FAIL ' . $name . ( null !== $detail ? ' — ' . wp_json_encode( $detail, JSON_UNESCAPED_UNICODE ) : '' ) );
	}
};
set_error_handler(
	static function ( int $no, string $msg, string $file, int $line ): bool {
		if ( str_contains( $file, 'class-order-report.php' ) ) {
			throw new ErrorException( $msg . ' @' . basename( $file ) . ':' . $line, 0, $no, $file, $line );
		}
		return false;
	}
);

// 假总后台：截住发往 /orders/report 的请求，记下内容，全部接收。
$sent = array();
$down = false;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$sent, &$down ) {
		if ( ! str_contains( (string) $url, 'hq.example.test' ) && ! str_contains( (string) $url, (string) wp_parse_url( (string) PEPTIDE_HQ_URL, PHP_URL_HOST ) ) ) {
			return $pre;
		}
		$body   = json_decode( (string) $args['body'], true );
		$sent[] = array( 'url' => (string) $url, 'headers' => (array) $args['headers'], 'orders' => (array) ( $body['orders'] ?? array() ) );
		if ( $down ) {
			return new WP_Error( 'http', 'HQ unreachable' );
		}
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'accepted' => array_column( (array) ( $body['orders'] ?? array() ), 'store_order_id' ), 'rejected' => array() ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	99,
	3
);

update_option( Series_Sync::OPTION, array( 'series_id' => 7, 'slug' => 'series-7', 'name' => 'Smoke series' ), false );
$product_ids = wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 1, 'return' => 'ids' ) );
if ( ! $product_ids ) {
	$variations  = wc_get_products( array( 'type' => 'variation', 'status' => 'publish', 'limit' => 1, 'return' => 'ids' ) );
	$product_ids = $variations;
}
$check( 'the store has something to sell', (bool) $product_ids );
// 测试期间不真的发邮件。
add_filter( 'pre_wp_mail', '__return_true' );
// 本地副本里的商品是演示数据，含演示商品的订单会被当成测试单；测试期间先摘掉这个标记，结束时放回去。
$sold      = wc_get_product( (int) $product_ids[0] );
$parent_id = $sold->get_parent_id() ? $sold->get_parent_id() : $sold->get_id();
$demo_mark = get_post_meta( $parent_id, PeptideCore\Meta::DEMO_SEED, true );
delete_post_meta( $parent_id, PeptideCore\Meta::DEMO_SEED );
$make = static function ( string $email, string $status, array $meta = array() ) use ( $product_ids ): WC_Order {
	$order = wc_create_order();
	$order->add_product( wc_get_product( (int) $product_ids[0] ), 2 );
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Smoke' );
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
	return wc_get_order( $order->get_id() );
};
$created = array();
$paid    = $make( 'Smoke.Buyer@Example.Test', 'processing' );
$unpaid  = $make( 'unpaid@example.test', 'pending' );
$created = array( $paid->get_id(), $unpaid->get_id() );

// ---- 新订单带上所属系列 ----
$check( 'new order is stamped with the series', '7' === (string) $paid->get_meta( Series_Sync::ORDER_META ) );

// ---- 摘要：只有汇总字段 ----
$s = R::summary( $paid );
$check( 'summary keys are exactly the contract', array( 'store_order_id', 'order_number', 'status', 'paid', 'is_test', 'currency', 'total_cents', 'created_at', 'paid_at', 'order_day', 'series_id', 'email_hash', 'utm_source', 'ad_id' ) === array_keys( $s ) );
$check( 'summary values', $paid->get_id() === $s['store_order_id'] && true === $s['paid'] && false === $s['is_test'] && $s['total_cents'] === R::cents( $paid->get_total() ) && $s['total_cents'] > 0 && $s['paid_at'] > 0 && 7 === $s['series_id'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s['order_day'] ), $s );
$json = (string) wp_json_encode( $s );
$check( 'no personal data in the summary', hash( 'sha256', 'smoke.buyer@example.test' ) === $s['email_hash'] && false === stripos( $json, 'example.test' ) && false === stripos( $json, 'Buyer' ) && false === stripos( $json, 'Test Street' ) && false === strpos( $json, '5550100' ) );

// ---- 状态变化会排一个异步任务（Action Scheduler） ----
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	$queued = as_get_scheduled_actions( array( 'hook' => R::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ), 'ids' );
	$check( 'paid order queued an async report, unpaid one did not', count( $queued ) >= 1 );
}

// ---- 上报：付过款的才报；成功后记指纹；重复不再报 ----
$first = R::report( $created );
$check( 'paid order sent, unpaid skipped', 1 === $first['sent'] && 1 === $first['accepted'] && 1 === $first['skipped'] && '' === $first['error'], $first );
$check( 'request is signed and goes to the HQ route', 1 === count( $sent ) && str_contains( $sent[0]['url'], rawurlencode( '/peptide-hq/v1/orders/report' ) ) && 64 === strlen( (string) $sent[0]['headers']['X-PC-HQ-Sign'] ) && '' !== (string) $sent[0]['headers']['X-PC-HQ-Site'] );
$paid = wc_get_order( $paid->get_id() );
$check( 'fingerprint stored on the order', '' !== R::parse_reported( (string) $paid->get_meta( R::META ) )['fingerprint'] );
$check( 'repeat sends nothing', 0 === R::report( $created )['sent'] && 1 === count( $sent ) );

// ---- 支付方订单号（hang-）：显示用订单号跟着变，内部订单 ID 不变，会重新上报 ----
$paid->update_meta_data( '_pc_pp_ref', 'hang-161188301' );
$paid->save();
$paid = wc_get_order( $paid->get_id() );
$s2   = R::summary( $paid );
if ( class_exists( PeptideCore\Order_Number::class ) ) {
	$check( 'display number is the hang- id, store_order_id stays the WooCommerce id', 'hang-161188301' === $s2['order_number'] && $paid->get_id() === $s2['store_order_id'], $s2 );
	$again = R::reconcile();
	$check( 'changed display number is re-reported by the hourly reconcile', $again['sent'] >= 1 && 'hang-161188301' === end( $sent )['orders'][0]['order_number'] && $paid->get_id() === end( $sent )['orders'][0]['store_order_id'], $again );
}

// ---- 测试单：全填 1 的地址，或者店主手工打的标记，都报成 is_test ----
$marked = $make( 'marked@example.test', 'processing' );
$marked->update_meta_data( PeptideCore\Test_Orders::META, '1' );
$marked->save();
$created[] = $marked->get_id();
$check( 'manually marked test order is flagged', true === R::summary( wc_get_order( $marked->get_id() ) )['is_test'] );
$check( 'data pages use the same rule', PeptideCore\Overview::is_test_order( wc_get_order( $marked->get_id() ) ) );

// ---- 手动订单链接下的单：和普通订单一样上报 ----
$manual = $make( 'manual@example.test', 'processing', array( '_pc_manual_link' => 'smoke-token' ) );
$created[] = $manual->get_id();
$ms = R::summary( $manual );
$check( 'manual-link order is reported like any paid order', true === $ms['paid'] && false === $ms['is_test'] && $ms['total_cents'] > 0 );

// ---- 总后台连不上：不记指纹，下次补报 ----
$down = true;
$fail = R::report( array( $marked->get_id(), $manual->get_id() ) );
$check( 'HQ down keeps the orders unreported', 2 === $fail['sent'] && 0 === $fail['accepted'] && '' !== $fail['error'] && '' === (string) wc_get_order( $manual->get_id() )->get_meta( R::META ) );
$down = false;
$rec  = R::reconcile();
$check( 'reconcile reports what failed', $rec['accepted'] >= 2 && '' === $rec['error'], $rec );
$check( 'second reconcile is quiet', 0 === R::reconcile()['sent'] );

// ---- 回填：试运行不写不发；正式运行；再跑一遍没有新动作 ----
delete_option( Series_Sync::OPTION ); // 历史订单没有系列的情况：先没有系列信息。
update_option( Series_Sync::OPTION, array( 'series_id' => 7, 'slug' => 'series-7', 'name' => 'Smoke series' ), false );
$before_posts = count( $sent );
$dry          = R::backfill( true );
$check( 'dry run counts and writes nothing', $dry['orders'] >= 3 && $dry['test_orders'] >= 1 && $before_posts === count( $sent ) && 0 === $dry['stamped'] && 0 === $dry['sent'], $dry );
$check( 'dry run is repeatable', $dry === R::backfill( true ) );
$real = R::backfill( false );
$check( 'real run reports everything outstanding', $real['orders'] === $dry['orders'] && $real['total_cents'] === $dry['total_cents'] && $real['accepted'] === $real['to_report'] && array() === $real['errors'] && $real['stamped'] === $dry['missing_series'], $real );
$third = R::backfill( false );
$check( 'third run has nothing to do', 0 === $third['to_report'] && $third['already_reported'] === $third['orders'] && 0 === $third['missing_series'] && 0 === $third['sent'], $third );
$digest = R::digest();
$check( 'digest equals the backfill totals', $digest['count'] === $real['orders'] - $real['test_orders'] && $digest['total_cents'] === $real['total_cents'] && $digest['test_count'] === $real['test_orders'], array( $digest, $real ) );

// ---- 退款标记：只写订单元和备注，不创建 WooCommerce 退款、不改状态 ----
$total  = R::cents( $paid->get_total() );
$call   = static function ( array $p ) {
	$req = new WP_REST_Request( 'POST', '/peptide/v1/hq/order-refund' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( (string) wp_json_encode( $p ) );
	return R::refund_route( $req );
};
$notes  = static fn( int $id ): int => count( wc_get_order_notes( array( 'order_id' => $id, 'limit' => 100 ) ) );
$n0     = $notes( $paid->get_id() );
$res    = $call( array( 'order_id' => $paid->get_id(), 'refunded_cents' => 100, 'state' => 'partial', 'note' => 'chargeback' ) );
$fresh  = wc_get_order( $paid->get_id() );
$check( 'partial mark written', is_array( $res ) && 'partial' === $res['state'] && true === $res['changed'] && '100' === (string) $fresh->get_meta( R::REFUND_CENTS ) && 'partial' === (string) $fresh->get_meta( R::REFUND_STATE ), $res );
$check( 'one private note, no WooCommerce refund, status untouched', $n0 + 1 === $notes( $paid->get_id() ) && array() === $fresh->get_refunds() && 'processing' === $fresh->get_status() && 0.0 === (float) $fresh->get_total_refunded() );
$call( array( 'order_id' => $paid->get_id(), 'refunded_cents' => 100, 'state' => 'partial', 'note' => 'chargeback' ) );
$check( 'same mark again adds no note', $n0 + 1 === $notes( $paid->get_id() ) );
$res = $call( array( 'order_id' => $paid->get_id(), 'refunded_cents' => $total, 'state' => 'full', 'note' => '' ) );
$check( 'full mark written', 'full' === $res['state'] && (string) $total === (string) wc_get_order( $paid->get_id() )->get_meta( R::REFUND_CENTS ) );
$check( 'digest reflects the marked refund', $total === R::digest()['refunded_cents'] );
$res = $call( array( 'order_id' => $paid->get_id(), 'refunded_cents' => 0, 'state' => 'none', 'note' => '' ) );
$check( 'void restores the order', 'none' === $res['state'] && 'none' === (string) wc_get_order( $paid->get_id() )->get_meta( R::REFUND_STATE ) && $n0 + 3 === $notes( $paid->get_id() ) && 'processing' === wc_get_order( $paid->get_id() )->get_status() );
$check( 'over-refund and unknown order refused', is_wp_error( $call( array( 'order_id' => $paid->get_id(), 'refunded_cents' => $total + 1 ) ) ) && is_wp_error( $call( array( 'order_id' => 999999999, 'refunded_cents' => 1 ) ) ) );
$check( 'refund marks do not disturb the report fingerprint', 0 === R::report( array( $paid->get_id() ) )['sent'] );

if ( getenv( 'PC_SMOKE_OUT' ) ) {
	$all = array();
	foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'return' => 'objects' ) ) as $o ) {
		$f = R::facts( $o );
		if ( R::reportable( $f['paid'], $f['paid_at'], false ) ) {
			$all[] = R::summary( $o );
		}
	}
	file_put_contents( (string) getenv( 'PC_SMOKE_OUT' ), (string) wp_json_encode( array( 'orders' => $all, 'digest' => R::digest() ) ) );
}

// ---- 收尾 ----
foreach ( $created as $id ) {
	$o = wc_get_order( $id );
	if ( $o ) {
		$o->delete( true );
	}
}
foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'return' => 'objects' ) ) as $o ) {
	$o->delete_meta_data( R::META );
	$o->delete_meta_data( Series_Sync::ORDER_META );
	$o->save_meta_data();
}
delete_option( Series_Sync::OPTION );
if ( '' !== (string) $demo_mark ) {
	update_post_meta( $parent_id, PeptideCore\Meta::DEMO_SEED, $demo_mark );
}

WP_CLI::success( 'PASS ' . $checks . ' M10 order report and refund smoke checks on a real WooCommerce store copy.' );
