<?php
/**
 * 当前在跑的营销活动（HQ-07 店铺端，只读）。给投手的只读页面和总后台的状态接口用。
 *
 * 汇总七处优惠里“现在对顾客生效”的那些：满减活动、优惠码（含首单券，不含顾客用积分换的个人券）、
 * 运费折扣、赠品 / 捆绑、离开挽回 / 转盘。每条带来源：series = 总后台按系列统一下发的（带 _pc_series_campaign 标记），
 * store = 这家店自己建的。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

use PeptideCore\Coupon_Rules;
use PeptideCore\Exit_Intent;
use PeptideCore\First_Order_Offer;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

final class Campaigns {

	/** 系列统一下发的活动在文章 / 优惠码 meta 上的标记（值 = "kind/key"）；运费折扣、首单、离开挽回用数组里的 series_key。 */
	public const SERIES_META = Series_Campaigns::META;

	public const KINDS = array(
		'promotion'         => '满减活动',
		'coupon'            => '优惠码',
		'first_order'       => '首单优惠',
		'shipping_discount' => '运费折扣',
		'gift'              => '赠品',
		'bundle'            => '捆绑',
		'exit_intent'       => '离开挽回',
	);

	private static function source( int $post_id ): string {
		return $post_id && '' !== (string) get_post_meta( $post_id, self::SERIES_META, true ) ? 'series' : 'store';
	}

	/**
	 * @return array<int,array{kind:string,id:int,name:string,rule:string,ends:int,source:string}> ends = 结束时间（UTC 时间戳，0 = 长期）
	 */
	public static function running( ?int $now = null ): array {
		$now  = $now ?? time();
		$rows = array();

		foreach ( Promotions::active_ids( $now ) as $id ) {
			$c      = Promotions::config( $id );
			$rows[] = array( 'kind' => 'promotion', 'id' => (int) $id, 'name' => html_entity_decode( get_the_title( $id ) ), 'rule' => implode( '；', Promotions::rules_text( $c ) ), 'ends' => (int) $c['end'], 'source' => self::source( (int) $id ) );
		}

		$first = First_Order_Offer::settings();
		$ids   = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'DESC' ) );
		foreach ( $ids as $id ) {
			$coupon = new \WC_Coupon( (int) $id );
			// 顾客用积分换的个人券不是营销活动。
			if ( $coupon->get_meta( '_pc_loyalty_user' ) || 'active' !== Coupon_Rules::status( $coupon, $now ) ) {
				continue;
			}
			$expires = $coupon->get_date_expires();
			$rows[]  = array(
				'kind'   => $first['enabled'] && $first['coupon_id'] === (int) $id ? 'first_order' : 'coupon',
				'id'     => (int) $id,
				'name'   => Coupon_Rules::display_code( $coupon ),
				'rule'   => Coupon_Rules::rule_text( $coupon ),
				'ends'   => $expires ? (int) $expires->getTimestamp() : 0,
				'source' => self::source( (int) $id ),
			);
		}

		foreach ( Discounts::all() as $d ) {
			if ( 'active' === Discounts::status( $d, $now ) && null !== Discounts::value_now( $d, $now ) ) {
				$rows[] = array( 'kind' => 'shipping_discount', 'id' => (int) $d['id'], 'name' => (string) $d['name'], 'rule' => implode( '；', Discounts::rule_lines( $d ) ), 'ends' => (int) $d['end'], 'source' => '' === (string) ( $d['series_key'] ?? '' ) ? 'store' : 'series' );
			}
		}

		foreach ( Product_Offers::all( true ) as $id => $offer ) {
			$rows[] = array( 'kind' => 'bundle' === $offer['kind'] ? 'bundle' : 'gift', 'id' => (int) $id, 'name' => (string) $offer['title'], 'rule' => 'bundle' === $offer['kind'] ? ( 'fixed' === $offer['price_type'] ? '整组一口价' : '整组打折' ) : ( 'quantity' === $offer['basis'] ? '满件数送赠品' : '满金额送赠品' ), 'ends' => (int) $offer['end'], 'source' => self::source( (int) $id ) );
		}

		$exit = Exit_Intent::settings();
		if ( $exit['enabled'] ) {
			$types  = array( 'coupon' => '弹窗发优惠码 ' . strtoupper( (string) $exit['coupon'] ), 'wheel' => '抽奖转盘（' . count( (array) $exit['prizes'] ) . ' 个奖项）', 'message' => '自定义内容' );
			$rows[] = array( 'kind' => 'exit_intent', 'id' => 0, 'name' => (string) $exit['title'], 'rule' => $types[ $exit['type'] ] ?? (string) $exit['type'], 'ends' => 0, 'source' => '' === (string) ( $exit['series_key'] ?? '' ) ? 'store' : 'series' );
		}
		return $rows;
	}
}
