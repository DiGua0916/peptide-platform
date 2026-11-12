<?php
/**
 * M9 投手的订单（ST-03）：订单列表的列、退款状态、导出订单的纯逻辑（不加载 WordPress）。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/m9-buyer-orders-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}
	$inc = dirname( __DIR__ ) . '/includes';
	require $inc . '/class-staff-buyer.php';
	require $inc . '/admin/class-admin-menus.php';
	require $inc . '/admin/class-buyer-order-export.php';

	// ---------- 订单列表的列 ----------
	// 主线 Order_List::columns()（2026-11-11）给出的列。
	$mainline = array( 'cb' => '', 'pc_ops' => '操作', 'pc_number' => '订单编号', 'pc_items' => '商品', 'pc_email' => '顾客邮箱', 'pc_country' => '国家', 'pc_ship' => '配送方式', 'order_total' => '订单总价', 'pc_pay' => '支付方式', 'pc_paid' => '支付时间', 'pc_tid' => '流水号', 'order_status' => '订单状态', 'fulfillment_status' => '发货状态', 'shipment_tracking' => '物流单号', 'origin' => '来源' );
	$cols     = \PeptideCore\Admin\Admin_Menus::buyer_columns( $mainline );
	check( 'no checkbox, no action column (ship / write email), no duplicate source', ! isset( $cols['cb'] ) && ! isset( $cols['pc_ops'] ) && ! isset( $cols['wc_actions'] ) && ! isset( $cols['origin'] ) );
	check( 'order number column stays (it opens the detail page)', isset( $cols['pc_number'] ) && isset( $cols['order_total'] ) && isset( $cols['order_status'] ) );
	check( 'ad source and refund status are added at the end', array( 'pc_source', 'pc_refund' ) === array_slice( array_keys( $cols ), -2 ) && '来源广告' === $cols['pc_source'] && '退款状态' === $cols['pc_refund'] );
	check( 'no second tracking column when the list already has one', ! isset( $cols['pc_tracking'] ) && isset( $cols['shipment_tracking'] ) );
	$plain = \PeptideCore\Admin\Admin_Menus::buyer_columns( array( 'cb' => '', 'order_number' => 'Order', 'order_total' => 'Total', 'wc_actions' => 'Actions' ) );
	check( 'without mainline columns: tracking column is added', array( 'order_number', 'order_total', 'pc_source', 'pc_tracking', 'pc_refund' ) === array_keys( $plain ) );
	$src = (string) file_get_contents( $inc . '/admin/class-admin-menus.php' );
	check( 'buyer columns run after the mainline list (999)', false !== strpos( $src, "array( self::class, 'buyer_order_columns' ), 1000 )" ) );

	// ---------- 退款状态：总后台标记（F3）和店铺里的退款都算 ----------
	$refund = static fn( string $state, float $refunded, float $total, int $cents = 0 ): string => \PeptideCore\Admin\Admin_Menus::refund_label( $state, $refunded, $total, $cents );
	check( 'HQ marks full', '已退款' === $refund( 'full', 0.0, 100.0, 10000 ) );
	check( 'HQ marks partial', '部分退款' === $refund( 'partial', 0.0, 100.0, 3000 ) );
	check( 'cents alone decide when the state is missing', '部分退款' === $refund( '', 0.0, 100.0, 3000 ) && '已退款' === $refund( '', 0.0, 100.0, 10000 ) );
	check( 'HQ none + store refund still shows', '部分退款' === $refund( 'none', 20.0, 100.0, 0 ) );
	check( 'nothing refunded', '无' === $refund( 'none', 0.0, 100.0, 0 ) && '无' === $refund( '', 0.0, 0.0, 0 ) );

	// ---------- 导出：日期范围 ----------
	$range = static fn( string $from, string $to, string $today = '2026-11-12' ): array => \PeptideCore\Admin\Buyer_Order_Export::range( $from, $to, $today );
	check( 'default is the last 30 days', array( 'from' => '2026-10-14', 'to' => '2026-11-12', 'clamped' => false ) === $range( '', '' ) );
	check( 'given range is kept', array( 'from' => '2026-11-01', 'to' => '2026-11-05', 'clamped' => false ) === $range( '2026-11-01', '2026-11-05' ) );
	check( 'reversed range is swapped', array( 'from' => '2026-11-01', 'to' => '2026-11-05', 'clamped' => false ) === $range( '2026-11-05', '2026-11-01' ) );
	check( 'end is never in the future', '2026-11-12' === $range( '2026-11-10', '2027-01-01' )['to'] );
	check( 'start after today collapses to today', array( 'from' => '2026-11-12', 'to' => '2026-11-12', 'clamped' => false ) === $range( '2026-12-01', '' ) );
	$long = $range( '2025-01-01', '2026-11-12' );
	check( 'longer than 92 days is cut to 92', true === $long['clamped'] && '2026-08-13' === $long['from'] && '2026-11-12' === $long['to'] );
	check( 'exactly 92 days is not cut', false === $range( '2026-08-13', '2026-11-12' )['clamped'] );
	check( 'bad dates fall back to the default', $range( '', '' ) === $range( '2026-13-40', 'not-a-date' ) && $range( '', '' ) === $range( '2026-11-0x', '2026/11/05' ) && $range( '', '' ) === $range( '2026-02-30', '' ) );

	// ---------- 导出：单元格和行 ----------
	$cell = static fn( $v ): string => \PeptideCore\Admin\Buyer_Order_Export::cell( $v );
	check( 'formula-looking cells are neutralised', "'=1+1" === $cell( '=1+1' ) && "'+1" === $cell( '+1' ) && "'-1" === $cell( '-1' ) && "'@SUM(A1)" === $cell( '@SUM(A1)' ) && "'=cmd" === $cell( "\t=cmd" ) );
	check( 'ordinary cells are untouched', 'hang-131189201' === $cell( 'hang-131189201' ) && 'a b' === $cell( "a\r\nb" ) && '' === $cell( null ) && '' === $cell( array( 'x' ) ) && '12' === $cell( 12 ) );
	$columns = \PeptideCore\Admin\Buyer_Order_Export::columns();
	$row     = \PeptideCore\Admin\Buyer_Order_Export::row( array(
		'number' => 'hang-131189201', 'created' => '2026-11-11 10:00:00', 'paid' => '2026-11-11 10:03:00', 'status' => '待发货', 'total' => 199.5, 'currency' => 'USD',
		'items' => 'Kit A x2; Kit B x1', 'quantity' => 3, 'email' => 'jane@example.com', 'country' => 'US', 'source' => 'facebook / =HYPERLINK("x") / ad1',
		'utm_source' => 'facebook', 'utm_campaign' => '=HYPERLINK("x")', 'utm_content' => 'ad1', 'utm_term' => '', 'carrier' => 'FedEx', 'tracking' => '123456789012',
		'refund' => '部分退款', 'refund_amount' => 30,
	) );
	check( 'row has one value per column', count( $columns ) === count( $row ) && 19 === count( $row ) );
	$by = array_combine( $columns, $row );
	check( 'row values land in the right columns', 'hang-131189201' === $by['订单号'] && '199.50' === $by['订单金额'] && '3' === $by['件数'] && 'jane@example.com' === $by['顾客邮箱'] && '123456789012' === $by['运单号'] && '部分退款' === $by['退款状态'] && '30.00' === $by['退款金额'] );
	check( 'ad names cannot inject a formula', "'=HYPERLINK(\"x\")" === $by['utm_campaign'] );
	check( 'missing values are empty, amounts are zero', array_fill( 0, 19, '' ) !== ( $empty = \PeptideCore\Admin\Buyer_Order_Export::row( array() ) ) && '0.00' === $empty[4] && '0' === $empty[7] && '0.00' === $empty[18] && '' === $empty[0] );
	check( 'no phone or address column', ! array_filter( $columns, static fn( string $c ): bool => false !== strpos( $c, '电话' ) || false !== strpos( $c, '地址' ) ) );
	check( 'limits', 92 === \PeptideCore\Admin\Buyer_Order_Export::MAX_DAYS && 5000 === \PeptideCore\Admin\Buyer_Order_Export::MAX_ROWS );
	$export_src = (string) file_get_contents( $inc . '/admin/class-buyer-order-export.php' );
	check( 'export checks who is asking and the nonce before reading orders', strpos( $export_src, 'Staff_Buyer::can_use()' ) < strpos( $export_src, 'check_admin_referer( self::NONCE )' ) && strpos( $export_src, 'check_admin_referer( self::NONCE )' ) < strpos( $export_src, 'wc_get_orders(' ) );
	check( 'export skips test orders and unpaid orders', false !== strpos( $export_src, 'Test_Orders::is_test_order( $order )' ) && false !== strpos( $export_src, '! $order->get_date_paid()' ) );

	echo "PASS {$checks} buyer order list / refund / export checks\n";
}
