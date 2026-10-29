<?php
/**
 * 物流单号追踪（SHIP-TRACK-01）。
 *
 * 发货、单号、承运商识别、追踪链接、顾客邮件和“我的账户”显示都使用 WooCommerce 原生的
 * “订单履约（Fulfillments）”功能（选项 woocommerce_feature_fulfillments_enabled）。本类只补充：
 * 1. 发货前检查批次：标记为已履约时，已有批次记录的商品行必须先指定批次（设置可关闭）；
 * 2. 顾客订单接口里的物流信息（/me/orders/{n}/batches 的 fulfillment 字段）；
 * 3. 工作台的“已付款待发货”统计。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentException;
use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils;

defined( 'ABSPATH' ) || exit;

final class Shipping_Tracking {

	public const FEATURE_OPTION = 'woocommerce_feature_fulfillments_enabled';

	public static function register_hooks(): void {
		// 新建时直接标记为已发货，和先建草稿、之后再改为已发货，两条路径都会经过这个过滤器。
		add_filter( 'woocommerce_fulfillment_before_fulfill', array( self::class, 'guard_batches' ) );
	}

	/**
	 * WooCommerce 原生的订单履约功能是否已开启。
	 */
	public static function native_enabled(): bool {
		return 'yes' === get_option( self::FEATURE_OPTION, 'no' ) && class_exists( Fulfillment::class );
	}

	/**
	 * 需要批次但还没指定的商品行名称。
	 * 只检查本次发货包含的行；商品没有任何已发布批次时（例如配套耗材）不要求。
	 *
	 * @param array<int,array{item_id?:int,qty?:int|float}> $fulfillment_items
	 * @return string[]
	 */
	public static function lines_missing_batch( \WC_Order $order, array $fulfillment_items ): array {
		$missing = array();
		foreach ( $fulfillment_items as $row ) {
			if ( (float) ( $row['qty'] ?? 0 ) <= 0 ) {
				continue;
			}
			$item = $order->get_item( (int) ( $row['item_id'] ?? 0 ) );
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_meta( Order_Batches::ITEM_META ) ) {
				continue;
			}
			if ( self::product_has_batches( (int) $item->get_product_id() ) ) {
				$missing[] = $item->get_name();
			}
		}
		return array_values( array_unique( $missing ) );
	}

	private static function product_has_batches( int $product_id ): bool {
		foreach ( Batches::ids_for_product( $product_id ) as $batch_id ) {
			if ( 'publish' === get_post_status( $batch_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * woocommerce_fulfillment_before_fulfill：缺批次时抛出 FulfillmentException，
	 * WooCommerce 会中止保存，并在后台的发货面板里显示这条提示。
	 *
	 * @param Fulfillment $fulfillment
	 * @return Fulfillment
	 */
	public static function guard_batches( $fulfillment ) {
		if ( ! $fulfillment instanceof Fulfillment || empty( Settings::all()['require_batch_to_ship'] ) ) {
			return $fulfillment;
		}
		$order = $fulfillment->get_order();
		if ( ! $order instanceof \WC_Order ) {
			return $fulfillment;
		}
		$missing = self::lines_missing_batch( $order, $fulfillment->get_items() );
		if ( $missing ) {
			throw new FulfillmentException(
				sprintf(
					/* translators: %s: comma-separated product names */
					esc_html__( 'Assign a batch before shipping: %s. (You can turn this check off in the store settings.)', 'peptide-core' ),
					esc_html( implode( ', ', $missing ) )
				)
			);
		}
		return $fulfillment;
	}

	/**
	 * 某订单已发货（已履约）的包裹。功能未开启时返回 null。
	 * 未标记为已发货的草稿不返回给顾客。
	 *
	 * @return array{status:string,shipments:array<int,array<string,mixed>>}|null
	 */
	public static function customer_view( \WC_Order $order ): ?array {
		if ( ! self::native_enabled() ) {
			return null;
		}
		try {
			$fulfillments = \WC_Data_Store::load( 'order-fulfillment' )->read_fulfillments( \WC_Order::class, (string) $order->get_id() );
		} catch ( \Throwable $e ) {
			return null;
		}

		$providers = FulfillmentUtils::get_shipping_providers();
		$shipments = array();
		foreach ( $fulfillments as $fulfillment ) {
			if ( ! $fulfillment instanceof Fulfillment || ! $fulfillment->get_is_fulfilled() ) {
				continue;
			}
			$key   = (string) $fulfillment->get_shipment_provider();
			$name  = isset( $providers[ $key ] ) ? $providers[ $key ]->get_name() : FulfillmentUtils::resolve_provider_name( $fulfillment );
			$items = array();
			foreach ( $fulfillment->get_items() as $row ) {
				$item = $order->get_item( (int) ( $row['item_id'] ?? 0 ) );
				if ( $item instanceof \WC_Order_Item_Product ) {
					$items[] = array(
						'name'     => $item->get_name(),
						'quantity' => (int) ( $row['qty'] ?? 0 ),
					);
				}
			}
			$url    = (string) $fulfillment->get_tracking_url();
			$number = (string) $fulfillment->get_tracking_number();
			if ( '' === $url && '' !== $number && isset( $providers[ $key ] ) ) {
				// 后台面板通常会自动填好链接；通过接口或批量导入添加、没有链接时，按承运商生成。
				$url = (string) $providers[ $key ]->get_tracking_url( $number );
			}
			$date   = $fulfillment->get_date_fulfilled();

			$shipments[] = array(
				'provider'        => '' !== $key ? $key : null,
				'provider_name'   => '' !== $name ? $name : null,
				'tracking_number' => '' !== $number ? $number : null,
				'tracking_url'    => '' !== $url ? esc_url_raw( $url ) : null,
				// WooCommerce returns date_fulfilled as UTC; do not convert it from the store timezone again.
				'shipped_at'      => $date ? gmdate( 'c', (int) strtotime( $date . ' UTC' ) ) : null,
				'items'           => $items,
			);
		}

		return array(
			'status'    => FulfillmentUtils::get_order_fulfillment_status( $order ),
			'shipments' => $shipments,
		);
	}
}
