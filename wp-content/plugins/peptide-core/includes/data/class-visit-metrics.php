<?php
/** Shared Landing/source distinct-set accumulator; identities stay inside the request. */
namespace PeptideCore\Data;
defined( 'ABSPATH' ) || exit;

final class Visit_Metrics {
	public static function blank(): array {
		return array( 'visitors' => array(), 'viewed' => array(), 'carted' => array(), 'customers' => array(), 'paid_visitors' => array(), 'orders' => 0, 'sales' => 0.0 );
	}
	public static function visit( array &$group, array $row ): void {
		$visitor = (int) $row['visitor_id']; $stage = (int) $row['stage']; $user = (int) $row['user_id'];
		$group['visitors'][ $visitor ] = true;
		if ( $stage >= 1 ) { $group['viewed'][ $visitor ] = true; }
		if ( $stage >= 2 ) { $group['carted'][ $visitor ] = true; }
		if ( $user > 0 ) { $group['customers'][ 'u' . $user ] = true; }
	}
	public static function order_fact( \WC_Order $order ): array {
		$customer = (int) $order->get_customer_id(); $email = strtolower( trim( (string) $order->get_billing_email() ) );
		return array( 'sales' => self::sales( $order ), 'visitor' => (int) $order->get_meta( \PeptideCore\Tracking\Server_Events::ORDER_VISITOR_META ), 'visit' => (int) $order->get_meta( \PeptideCore\Tracking\Server_Events::ORDER_VISIT_META ), 'buyer' => $customer > 0 ? 'u' . $customer : ( '' !== $email ? 'e' . $email : '' ) );
	}
	public static function order( array &$group, array $order ): void {
		++$group['orders']; $group['sales'] += $order['sales'];
		if ( $order['visitor'] > 0 ) { $group['paid_visitors'][ $order['visitor'] ] = true; }
		if ( '' !== $order['buyer'] ) { $group['customers'][ $order['buyer'] ] = true; }
	}
	public static function finish( array $group ): array {
		$uv = count( $group['visitors'] ); $count = (int) $group['orders']; $sales = round( (float) $group['sales'], 2 );
		return array( 'uv' => $uv, 'customers' => count( $group['customers'] ), 'viewed_uv' => count( $group['viewed'] ), 'carted_uv' => count( $group['carted'] ), 'paid_uv' => count( $group['paid_visitors'] ), 'orders' => $count, 'conversion' => self::conversion( $count, count( array_intersect_key( $group['paid_visitors'], $group['visitors'] ) ), $uv, 'visit_metrics' ), 'sales' => $sales, 'aov' => $count > 0 ? round( $sales / $count, 2 ) : 0.0, 'uv_value' => $uv > 0 ? round( $sales / $uv, 2 ) : null );
	}

	/** M6（NF-02）：一张订单的销售额按开关走 Metrics_Overlay::order_sales()（关着时 = 订单金额 − WooCommerce 退款，和以前一样）。 */
	private static function sales( $order ): float {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::order_sales( $order ) : (float) $order->get_total() - (float) $order->get_total_refunded();
	}
	/** M6（NF-02）：分组转化率按开关走 Metrics_Overlay::conversion()（关着时 = 订单数 ÷ 访客，和以前一样；开了 = 付了款的访客 ÷ 访客）。 */
	private static function conversion( int $orders, int $paid_visitors, int $visitors, string $report ): ?float {
		if ( class_exists( Metrics_Overlay::class ) ) {
			return Metrics_Overlay::conversion( $orders, $paid_visitors, $visitors, $report );
		}
		return $visitors ? round( $orders / $visitors * 100, 2 ) : null;
	}
}
