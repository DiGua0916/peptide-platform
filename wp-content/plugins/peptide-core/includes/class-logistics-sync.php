<?php
/**
 * 物流填单（店主 2026-11-12）：付款成功的订单自动报给总后台，总后台按物流填单表的格式出“发货登记”和“报关填单”两个表。
 *
 * - 付款后（订单进入处理中 / 已完成）20 秒内报一次；状态改了（退款、取消）也报，总后台据此从表里去掉。
 * - 每小时把最近 45 天付款的订单再报一遍，补上后来填的物流单号。
 * - 只读订单，不改订单。测试订单不报。手动订单（后台开的链接单）付款后一样报。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Logistics_Sync {

	public const CRON_ONE   = 'pc_logistics_push';
	public const CRON_DAILY = 'pc_logistics_hourly';
	private const ROUTE     = '/peptide-hq/v1/logistics/push';
	private const DAYS      = 45;

	public static function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status' ), 40, 3 );
		add_action( self::CRON_ONE, array( self::class, 'push' ), 10, 1 );
		add_action( self::CRON_DAILY, array( self::class, 'recent' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
					wp_schedule_event( time() + 300, 'hourly', self::CRON_DAILY );
				}
			}
		);
	}

	public static function on_status( $order_id, $from = '', $to = '' ): void {
		$o = wc_get_order( $order_id );
		if ( ! $o instanceof \WC_Order || ! $o->get_date_paid() ) {
			return; // 从没付过款的订单不进表。
		}
		$id = (int) $o->get_id();
		if ( ! wp_next_scheduled( self::CRON_ONE, array( $id ) ) ) {
			wp_schedule_single_event( time() + 20, self::CRON_ONE, array( $id ) );
		}
	}

	/** 物流单号：WooCommerce 原生“发货”里的单号；没有就用旧的 _pc_tracking_number。 */
	private static function tracking( \WC_Order $o ): array {
		$out = array();
		if ( class_exists( Shipping_Tracking::class ) ) {
			$v = Shipping_Tracking::customer_view( $o );
			foreach ( (array) ( $v['shipments'] ?? array() ) as $s ) {
				$n = preg_replace( '/\s+/', '', (string) ( $s['tracking_number'] ?? '' ) );
				if ( '' !== $n ) {
					$out[] = $n;
				}
			}
		}
		$meta = preg_replace( '/\s+/', '', (string) $o->get_meta( '_pc_tracking_number', true ) );
		if ( '' !== $meta ) {
			$out[] = $meta;
		}
		return array_values( array_unique( $out ) );
	}

	/** @return array<string,mixed>|null */
	public static function row( \WC_Order $o ): ?array {
		$paid = $o->get_date_paid();
		if ( ! $paid || $o->get_meta( '_pc_test_order' ) ) {
			return null;
		}
		$items = array();
		foreach ( $o->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$p       = $item->get_product();
			$sku     = $p instanceof \WC_Product ? (string) $p->get_sku() : '';
			$items[] = array(
				'sku'  => '' !== $sku ? $sku : $item->get_name(),
				'name' => $item->get_name(),
				'qty'  => (int) $item->get_quantity(),
			);
		}
		$ship  = $o->has_shipping_address();
		$g     = static fn( string $f ) => (string) ( $ship ? $o->{'get_shipping_' . $f}() : $o->{'get_billing_' . $f}() );
		$phone = (string) $o->get_shipping_phone();
		return array(
			'order_id'  => (int) $o->get_id(),
			'number'    => (string) $o->get_order_number(),
			'status'    => (string) $o->get_status(),
			'paid'      => (int) $paid->getTimestamp(),
			'name'      => trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) ),
			'company'   => $g( 'company' ),
			'address'   => trim( $g( 'address_1' ) . ' ' . $g( 'address_2' ) ),
			'city'      => $g( 'city' ),
			'state'     => strtoupper( $g( 'state' ) ),
			'postcode'  => $g( 'postcode' ),
			'country'   => strtoupper( $g( 'country' ) ),
			'phone'     => '' !== $phone ? $phone : (string) $o->get_billing_phone(),
			'email'     => (string) $o->get_billing_email(),
			'items'     => $items,
			'tracking'  => self::tracking( $o ),
		);
	}

	/**
	 * 报给总后台。
	 *
	 * @param int|int[] $ids
	 * @return int|\WP_Error 总后台存下的条数
	 */
	public static function push( $ids ) {
		if ( ! class_exists( Hq_Connector::class ) || ! Hq_Connector::configured() || ! defined( 'PEPTIDE_HQ_URL' ) ) {
			return new \WP_Error( 'pc_logi_hq', 'This store is not connected to the master admin.' );
		}
		$rows = array();
		foreach ( (array) $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o instanceof \WC_Order ) {
				$r = self::row( $o );
				if ( $r ) {
					$rows[] = $r;
				}
			}
		}
		if ( ! $rows ) {
			return 0;
		}
		$s     = Hq_Connector::settings();
		$saved = 0;
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$time = time();
			$body = (string) wp_json_encode( array( 'rows' => $chunk ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$res  = wp_remote_post(
				add_query_arg( 'rest_route', rawurlencode( self::ROUTE ), trailingslashit( (string) PEPTIDE_HQ_URL ) ),
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type' => 'application/json',
						'X-PC-HQ-Site' => $s['uuid'],
						'X-PC-HQ-Time' => (string) $time,
						'X-PC-HQ-Sign' => Hq_Connector::sign( $s['key'], 'POST', self::ROUTE, '', $time, $body ),
					),
					'body'    => $body,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return new \WP_Error( 'pc_logi_hq', 'HTTP ' . wp_remote_retrieve_response_code( $res ) . ' ' . ( is_array( $j ) ? (string) ( $j['message'] ?? '' ) : '' ) );
			}
			$saved += (int) ( $j['saved'] ?? 0 );
		}
		return $saved;
	}

	/** 最近 $days 天付款的订单全部再报一遍（只读订单）。 */
	public static function recent( int $days = self::DAYS ) {
		$ids = wc_get_orders(
			array(
				'limit'     => -1,
				'return'    => 'ids',
				'type'      => 'shop_order',
				'status'    => array_keys( wc_get_order_statuses() ),
				'date_paid' => '>' . ( time() - max( 1, $days ) * DAY_IN_SECONDS ),
			)
		);
		return self::push( $ids );
	}
}
