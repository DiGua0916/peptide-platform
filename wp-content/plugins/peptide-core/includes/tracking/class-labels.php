<?php
/**
 * 访客数据页面的中文名称（2026-11-09）：把追踪记录里的内部标识翻成店主看得懂的话。
 *
 * - 模块：装修积木类型（product_collection → 商品区）和固定区域（header → 页头导航）；
 * - 配送方式：pc_plans:<方案编号>-<费率> → 方案名称；
 * - 支付方式：网关编号 → 结账页显示的名称；
 * - 商品：编号 → 商品名（一次查询，带缓存）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

use PeptideCore\Modules\Registry;
use PeptideCore\Shipping\Plans;

defined( 'ABSPATH' ) || exit;

final class Labels {

	/** 常见积木类型的中文名；没列到的用积木注册表里的标题。 */
	private const MODULES = array(
		'site_header'        => '页头导航',
		'header'             => '页头导航',
		'site_footer'        => '页脚',
		'footer'             => '页脚',
		'product_collection' => '商品区',
		'product_grid'       => '商品区',
		'demo-catalog'       => '商品目录',
		'hero'               => '首屏大图',
		'carousel'           => '轮播',
		'faq'                => '常见问题',
		'media-text'         => '图文介绍',
		'report-feature'     => '检测报告',
		'first-order-offer'  => '首单优惠弹窗',
		'promo_bar'          => '促销条',
		'announcement'       => '公告条',
		'video'              => '视频',
		'reviews'            => '顾客评价',
		'newsletter'         => '订阅栏',
	);

	/** @var array<int,string> */
	private static array $products = array();

	/** 积木类型 → 中文名。 */
	public static function module( string $type, string $id = '' ): string {
		$type = strtolower( trim( $type ) );
		if ( isset( self::MODULES[ $type ] ) ) {
			return self::MODULES[ $type ];
		}
		if ( '' !== $type && class_exists( Registry::class ) ) {
			$def = Registry::get( $type );
			if ( is_array( $def ) && '' !== (string) ( $def['title'] ?? '' ) ) {
				return (string) $def['title'];
			}
		}
		return '' !== $type ? $type : ( '' !== $id ? $id : '模块' );
	}

	/** 全部已知积木类型的中文名，给前端时间线用。 */
	public static function modules(): array {
		$out = self::MODULES;
		if ( class_exists( Registry::class ) ) {
			foreach ( Registry::all() as $type => $def ) {
				if ( ! isset( $out[ $type ] ) && is_array( $def ) && '' !== (string) ( $def['title'] ?? '' ) ) {
					$out[ (string) $type ] = (string) $def['title'];
				}
			}
		}
		return $out;
	}

	/** 配送费率编号 → 方案名（pc_plans:3-r86cf → 第 3 个方案的名称）。 */
	public static function shipping(): array {
		$out = array();
		if ( class_exists( Plans::class ) ) {
			foreach ( Plans::all() as $id => $plan ) {
				$name = trim( (string) ( $plan['name'] ?? '' ) );
				if ( '' !== $name ) {
					$out[ 'pc_plans:' . (int) $id ] = $name;
				}
			}
		}
		return $out;
	}

	/**
	 * 支付网关编号 → 结账页上的名称。
	 * 先取当前注册的网关；再用订单里记录的“支付方式名称”兜底（店铺后台副本上，总后台下发的多账户网关不一定注册，
	 * 但顾客下过的单里存着当时的名称）。
	 */
	public static function payments(): array {
		$out = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$title = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
				$out[ (string) $id ] = '' !== $title ? $title : (string) $id;
			}
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$rows = $wpdb->get_results( "SELECT payment_method, MAX(payment_method_title) AS title FROM {$table} WHERE payment_method <> '' AND payment_method_title <> '' GROUP BY payment_method", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$id = (string) $r['payment_method'];
				if ( ! isset( $out[ $id ] ) || $out[ $id ] === $id ) {
					$out[ $id ] = trim( wp_strip_all_tags( (string) $r['title'] ) );
				}
			}
		}
		// 多账户 Panapay：编号后面是账户哈希，没有名字时至少说清是“在线支付”。
		foreach ( array_keys( $out ) as $id ) {
			if ( $out[ $id ] === $id && str_starts_with( $id, 'pc_panapay' ) ) {
				$out[ $id ] = '在线支付';
			}
		}
		return $out;
	}

	/** 订单状态编号 → 中文名（pending → 待付款）。 */
	public static function order_statuses(): array {
		$out = array(
			'pending'    => '待付款',
			'processing' => '处理中',
			'on-hold'    => '保留',
			'completed'  => '已完成',
			'cancelled'  => '已取消',
			'refunded'   => '已退款',
			'failed'     => '失败',
			'draft'      => '草稿',
			'checkout-draft' => '结账草稿',
			'trash'      => '已删除',
		);
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $key => $label ) {
				$k = preg_replace( '/^wc-/', '', (string) $key );
				if ( ! isset( $out[ $k ] ) ) {
					$out[ $k ] = (string) $label;
				}
			}
		}
		return $out;
	}

	/** 时间线类型的白话名（覆盖翻译文件里的说法）。 */
	public static function type_labels_zh(): array {
		return array(
			'page_view'                  => '打开页面',
			'page_exit'                  => '离开页面',
			'heartbeat'                  => '页面还开着',
			'visibility_change'          => '切换前后台',
			'product_impression'         => '看到商品',
			'product_click'              => '点击商品',
			'product_view'               => '看商品详情',
			'product_intent'             => '对商品感兴趣',
			'variant_select'             => '选规格',
			'add_to_cart_attempt'        => '点了加入购物车',
			'add_to_cart'                => '加购成功',
			'cart_open'                  => '打开购物车',
			'cart_close'                 => '关闭购物车',
			'cart_view'                  => '看购物车页',
			'cart_change_attempt'        => '改购物车',
			'cart_change_response'       => '购物车已更新',
			'module_click'               => '点击页面模块',
			'checkout_view'              => '进入结账页',
			'checkout_step_applicability' => '结账要填的步骤',
			'checkout_address_completed' => '地址填完整了',
			'checkout_shipping_selected' => '选了配送方式',
			'checkout_payment_selected'  => '选了支付方式',
			'checkout_submit_attempt'    => '点了「下单」',
			'payment_attempt'            => '开始去支付',
			'order_placed'               => '订单已生成',
			'payment_success'            => '付款成功',
			'payment_failed'             => '付款失败',
			'order_status'               => '订单状态变化',
			'login'                      => '登录账号',
			'register'                   => '注册账号',
			'visit_classification'       => '来源判定',
			'video_play'                 => '播放视频',
			'video_pause'                => '暂停视频',
			'video_progress'             => '视频进度',
			'video_seek'                 => '拖动视频',
			'video_end'                  => '看完视频',
		);
	}

	/**
	 * 商品名（含规格）。查不到的返回 '#编号'。
	 *
	 * @param int[] $ids
	 * @return array<int,string>
	 */
	public static function products( array $ids ): array {
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i > 0 ) ) );
		$need = array_diff( $ids, array_keys( self::$products ) );
		if ( $need && function_exists( 'wc_get_products' ) ) {
			foreach ( array_chunk( $need, 100 ) as $chunk ) {
				foreach ( $chunk as $id ) {
					self::$products[ $id ] = '#' . $id;
				}
				foreach ( wc_get_products( array( 'include' => $chunk, 'limit' => count( $chunk ), 'type' => array( 'simple', 'variable', 'variation', 'grouped', 'external' ), 'status' => 'any' ) ) as $product ) {
					// 规格商品的 get_name() 已经带规格（“GHK-CU - 50mg*10vials”），不再重复拼接。
					$name = trim( wp_strip_all_tags( (string) $product->get_name() ) );
					self::$products[ $product->get_id() ] = '' !== $name ? mb_substr( $name, 0, 120 ) : '#' . $product->get_id();
				}
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::$products[ $id ] ?? ( '#' . $id );
		}
		return $out;
	}
}
