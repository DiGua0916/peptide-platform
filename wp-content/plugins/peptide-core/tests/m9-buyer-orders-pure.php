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
	// 开关 pc_buyer_extras 关着（默认）：原样返回，和线上（11-13 改版后的订单列表）一模一样，勾选框和“操作”列也不去掉。
	$online = \PeptideCore\Admin\Admin_Menus::buyer_columns( $mainline, false );
	check( 'switch off: exactly the online columns, nothing removed or added', $mainline === $online );
	check( 'switch off: WooCommerce columns pass through untouched', array( 'order_number' => 'Order', 'wc_actions' => 'Actions' ) === \PeptideCore\Admin\Admin_Menus::buyer_columns( array( 'order_number' => 'Order', 'wc_actions' => 'Actions' ), false ) );
	// 开关打开：只在最后多一列“退款状态”。来源和物流单号一律用线上的列（2026-11-14，重复的以主站为准）。
	$cols = \PeptideCore\Admin\Admin_Menus::buyer_columns( $mainline, true );
	check( 'switch on: exactly the online columns plus refund status at the end', $mainline + array( 'pc_refund' => '退款状态' ) === $cols );
	check( 'switch on: no column of our own for source or tracking', ! isset( $cols['pc_source'] ) && ! isset( $cols['pc_tracking'] ) );
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
	$facts   = array(
		'number' => 'hang-131189201', 'created' => '2026-11-11 10:00:00', 'paid' => '2026-11-11 10:03:00', 'status' => '待发货', 'total' => 199.5, 'currency' => 'USD',
		'items' => 'Kit A x2; Kit B x1', 'quantity' => 3, 'email' => 'jane@example.com', 'country' => 'US', 'source' => 'facebook / =HYPERLINK("x") / ad1',
		'utm_source' => 'facebook', 'utm_campaign' => '=HYPERLINK("x")', 'utm_content' => 'ad1', 'utm_term' => '', 'carrier' => 'FedEx', 'tracking' => '123456789012',
		'refund' => '部分退款', 'refund_amount' => 30,
	);
	$row     = \PeptideCore\Admin\Buyer_Order_Export::row( $facts );
	check( 'row has one value per column', count( $columns ) === count( $row ) && 18 === count( $row ) );
	$by = array_combine( $columns, $row );
	check( 'row values land in the right columns', 'hang-131189201' === $by['订单号'] && '199.50' === $by['订单金额'] && '3' === $by['件数'] && 'US' === $by['国家'] && '123456789012' === $by['运单号'] && '部分退款' === $by['退款状态'] && '30.00' === $by['退款金额'] );
	check( 'ad names cannot inject a formula', "'=HYPERLINK(\"x\")" === $by['utm_campaign'] );
	check( 'missing values are empty, amounts are zero', array_fill( 0, 18, '' ) !== ( $empty = \PeptideCore\Admin\Buyer_Order_Export::row( array() ) ) && '0.00' === $empty[4] && '0' === $empty[7] && '0.00' === $empty[17] && '' === $empty[0] );
	// 顾客邮箱：平台所有者 2026-11-14 定“带邮箱”，默认带；这家店把选项 pc_buyer_export_email 设成 off 才不带。
	check( 'without the email flag the row has no email', ! in_array( '顾客邮箱', $columns, true ) && ! in_array( 'jane@example.com', $row, true ) );
	$wanted = static fn( $v ): bool => \PeptideCore\Admin\Buyer_Order_Export::email_wanted( $v );
	check( 'email is included by default and with on', $wanted( 'on' ) && $wanted( 'ON' ) && $wanted( '' ) );
	check( 'a store can turn the email off', ! $wanted( 'off' ) && ! $wanted( ' Off ' ) && ! $wanted( '0' ) && ! $wanted( 'no' ) );
	$columns_e = \PeptideCore\Admin\Buyer_Order_Export::columns( true );
	$row_e     = \PeptideCore\Admin\Buyer_Order_Export::row( $facts, true );
	check( 'email column is added only when the store asks for it', 19 === count( $columns_e ) && 19 === count( $row_e ) && '顾客邮箱' === $columns_e[8] && 'jane@example.com' === array_combine( $columns_e, $row_e )['顾客邮箱'] );
	check( 'the other columns do not move', array_values( array_diff( $columns_e, array( '顾客邮箱' ) ) ) === $columns && 'US' === array_combine( $columns_e, $row_e )['国家'] && '30.00' === array_combine( $columns_e, $row_e )['退款金额'] );
	check( 'no phone or address column', ! array_filter( $columns_e, static fn( string $c ): bool => false !== strpos( $c, '电话' ) || false !== strpos( $c, '地址' ) ) );
	check( 'limits', 92 === \PeptideCore\Admin\Buyer_Order_Export::MAX_DAYS && 5000 === \PeptideCore\Admin\Buyer_Order_Export::MAX_ROWS );
	$export_src = (string) file_get_contents( $inc . '/admin/class-buyer-order-export.php' );
	check( 'export checks who is asking and the nonce before reading orders', strpos( $export_src, 'Staff_Buyer::can_use()' ) < strpos( $export_src, 'check_admin_referer( self::NONCE )' ) && strpos( $export_src, 'check_admin_referer( self::NONCE )' ) < strpos( $export_src, 'wc_get_orders(' ) );
	check( 'one switch decides the email column, on unless the store says off', 'pc_buyer_export_email' === \PeptideCore\Admin\Buyer_Order_Export::OPTION_EMAIL && false !== strpos( $export_src, "self::email_wanted( get_option( self::OPTION_EMAIL, 'on' ) )" ) && false !== strpos( $export_src, '$email = self::with_email();' ) );
	check( 'export skips test orders and unpaid orders', false !== strpos( $export_src, 'Test_Orders::is_test_order( $order )' ) && false !== strpos( $export_src, '! $order->get_date_paid()' ) );

	echo "PASS {$checks} buyer order list / refund / export checks\n";
}
