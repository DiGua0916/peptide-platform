<?php
/**
 * 店铺应用的开 / 关（2026-11-08）。总后台停用某个应用时先用它把应用关掉（见 Hq_Connector::apps_route）。
 *
 * 复用每个应用自己的保存和校验；改完重新读一次应用中心的状态，没生效就如实报错。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Admin\Apps_Page;

defined( 'ABSPATH' ) || exit;

final class App_Switch {

	/** @return true|\WP_Error */
	public static function set( string $key, bool $on ) {
		$apps = Apps_Page::catalog();
		if ( ! isset( $apps[ $key ] ) ) {
			return new \WP_Error( 'pc_app_unknown', '店铺里没有这个应用。' );
		}
		if ( (bool) $apps[ $key ]['on'] === $on ) {
			return true;
		}
		$done = self::apply( $key, $on );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = Apps_Page::catalog();
		if ( (bool) ( $after[ $key ]['on'] ?? ! $on ) !== $on ) {
			return new \WP_Error( 'pc_app_not_applied', $on ? '没有开启成功：这个应用还缺少必要的设置，请点“设置”在店铺后台补全后再开。' : '没有关闭成功，请到店铺后台的应用中心查看。' );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function apply( string $key, bool $on ) {
		switch ( $key ) {
			case 'postal-assist':
				$s            = Postal_Assist::settings();
				$s['enabled'] = $on;
				update_option( Postal_Assist::OPTION, Postal_Assist::normalize( $s ), false );
				return true;

			case 'campaign-countdown':
				$s = Campaign_Countdown::settings();
				if ( $on ) {
					$id      = (int) $s['promotionId'];
					$choices = array_column( Campaign_Countdown::candidates( $id ), null, 'id' );
					if ( ! $id || ! isset( $choices[ $id ] ) || ! in_array( $choices[ $id ]['status'], array( 'active', 'upcoming' ), true ) ) {
						return new \WP_Error( 'pc_app_setup', '活动倒计时还没有关联进行中或即将开始的活动，请点“设置”先选择活动。' );
					}
				}
				$s['enabled'] = $on;
				$clean        = Campaign_Countdown::normalize( $s );
				if ( null === $clean ) {
					return new \WP_Error( 'pc_app_setup', '活动倒计时的设置无效，请点“设置”重新保存。' );
				}
				update_option( Campaign_Countdown::OPTION, $clean, false );
				return true;

			case 'first-order-offer':
				$s = First_Order_Offer::settings();
				if ( $on ) {
					if ( ! function_exists( 'wc_coupons_enabled' ) || ! wc_coupons_enabled() ) {
						return new \WP_Error( 'pc_app_setup', '店铺还没有开启优惠券功能，不能开启首单优惠。' );
					}
					if ( ! $s['coupon_id'] || '' !== First_Order_Offer::coupon_issue( class_exists( '\WC_Coupon' ) ? new \WC_Coupon( $s['coupon_id'] ) : null, true ) ) {
						return new \WP_Error( 'pc_app_setup', '首单优惠还没有可用的优惠券，请点“设置”先选择或创建。' );
					}
				}
				$s['enabled'] = $on;
				update_option( First_Order_Offer::OPTION, First_Order_Offer::normalize( $s ), false );
				return true;

			case 'exit-intent':
				$s            = Exit_Intent::settings();
				$s['enabled'] = $on;
				$errors       = Exit_Intent::save( $s );
				if ( $on && $errors ) {
					return new \WP_Error( 'pc_app_setup', '离开意图增收还不能开启：' . implode( '；', array_map( 'strval', $errors ) ) . ' 请点“设置”补全。' );
				}
				return true;
		}
		$stores = array(
			'product-broadcast' => Broadcast\Store::class,
			'activity-listener' => Activity\Store::class,
			'cart-feedback'     => Cart_Feedback\Store::class,
			'recently-viewed'   => Recent\Store::class,
		);
		if ( isset( $stores[ $key ] ) && method_exists( $stores[ $key ], 'set_enabled' ) ) {
			$res = call_user_func( array( $stores[ $key ], 'set_enabled' ), $on );
			return is_wp_error( $res ) ? $res : true;
		}
		return new \WP_Error( 'pc_app_unsupported', '这个应用还不支持在总后台开关，请点“设置”到店铺后台操作。' );
	}
}
