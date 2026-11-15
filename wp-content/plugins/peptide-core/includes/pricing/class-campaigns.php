<?php
/**
 * 当前在跑的营销活动（HQ-07 店铺端，只读）。给投手的只读页面和总后台的状态接口用。
 *
 * 汇总七处优惠里“现在对顾客生效”的那些：满减活动、优惠码（含首单券，不含顾客用积分换的个人券）、
 * 运费折扣、赠品 / 捆绑、离开挽回 / 转盘。每条带来源：series = 总后台按系列统一下发的（带 _pc_series_campaign 标记），
 * store = 这家店自己建的。
 *
 * 每条另带 plain：写成大白话的条件、优惠、适用商品、适用顾客、怎么用、能否叠加、有效期和网站上的写法
 * （文字由 Campaign_Text 生成，这里负责查商品名、专辑名这些要读数据库的内容）。plain 里没有内部编号和顾客邮箱。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

use PeptideCore\Coupon_Rules;
use PeptideCore\Coupons;
use PeptideCore\Customer_Segments;
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

	/** 只读页面上的分组顺序和标题。 */
	public const GROUPS = array(
		'promotion'         => '满减活动（自动生效）',
		'first_order'       => '首单优惠',
		'coupon'            => '优惠码',
		'shipping_discount' => '运费折扣（自动生效）',
		'gift'              => '满额赠品（自动生效）',
		'bundle'            => '捆绑组合',
		'exit_intent'       => '离开挽回弹窗 / 抽奖转盘',
	);

	private static function source( int $post_id ): string {
		return $post_id && '' !== (string) get_post_meta( $post_id, self::SERIES_META, true ) ? 'series' : 'store';
	}

	// ---------- 要读数据库的小工具 ----------

	private static function symbol(): string {
		return html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
	}

	/** 店铺时间，到分钟。 */
	public static function when( int $ts ): string {
		return wp_date( 'Y-m-d H:i', $ts );
	}

	/** 在售商品的名字（已下架、已删除的不写）。 */
	private static function product_names( array $ids ): array {
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$out[] = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			}
		}
		return $out;
	}

	private static function category_names( array $ids ): array {
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$out[] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		return $out;
	}

	private static function period( int $start, int $end, int $now ): string {
		return Campaign_Text::period( $start, $end, $now, array( self::class, 'when' ) );
	}

	// ---------- 每一种活动写成大白话 ----------

	private static function promotion_plain( int $id, array $c, int $now ): array {
		$site = Promotions::rules_text( $c );
		if ( '' !== trim( (string) $c['text'] ) ) {
			array_unshift( $site, trim( (string) $c['text'] ) );
		}
		return array(
			'kind'   => self::KINDS['promotion'],
			'pairs'  => Campaign_Text::promotion_tiers( $c, self::symbol() ),
			'scope'  => Campaign_Text::scope( (string) $c['scope'], self::category_names( (array) $c['categories'] ), self::product_names( (array) $c['products'] ), ! empty( $c['exclude_sale'] ) ),
			'who'    => Campaign_Text::who( (array) ( $c['customer_rule'] ?? array() ), Customer_Segments::catalog() ),
			'how'    => '自动生效：购物车里满足条件就直接减，顾客不用输入任何东西',
			'stack'  => array( Campaign_Text::promotion_stack( $c ), '一件商品只参加一个满减活动：指定商品的活动优先，其次是专辑的，最后是全店的' ),
			'limits' => array(),
			'period' => self::period( (int) $c['start'], (int) $c['end'], $now ),
			'site'   => $site,
		);
	}

	private static function coupon_plain( \WC_Coupon $coupon, bool $first, array $running, int $now ): array {
		$id        = $coupon->get_id();
		$code      = Coupon_Rules::display_code( $coupon );
		$min_spend = (float) get_post_meta( $id, Coupon_Rules::MIN_SPEND_META, true );
		$text      = Campaign_Text::coupon(
			array(
				'type'          => $coupon->get_discount_type(),
				'amount'        => (float) $coupon->get_amount(),
				'free_shipping' => (bool) $coupon->get_free_shipping(),
				'min_spend'     => $min_spend > 0 ? $min_spend : (float) $coupon->get_minimum_amount(),
				'min_qty'       => (int) get_post_meta( $id, Coupon_Rules::MIN_QTY_META, true ),
				'first_order'   => 'yes' === get_post_meta( $id, Coupon_Rules::FIRST_META, true ),
				'limit_items'   => (int) $coupon->get_limit_usage_to_x_items(),
				'per_user'      => (int) $coupon->get_usage_limit_per_user(),
				'total'         => (int) $coupon->get_usage_limit(),
				'used'          => (int) $coupon->get_usage_count(),
				'individual'    => (bool) $coupon->get_individual_use(),
				'strategy'      => Coupon_Rules::strategy( $coupon ),
				'has_bundle'    => ! empty( $running['bundle'] ),
				'has_gift'      => ! empty( $running['gift'] ),
			),
			self::symbol()
		);

		$products   = (array) $coupon->get_product_ids();
		$categories = (array) $coupon->get_product_categories();
		$scope      = Campaign_Text::scope( $products ? 'products' : ( $categories ? 'categories' : 'all' ), self::category_names( $categories ), self::product_names( $products ), (bool) $coupon->get_exclude_sale_items() );
		$excluded   = self::product_names( (array) $coupon->get_excluded_product_ids() );
		if ( $excluded ) {
			$scope .= '。这些商品不参加：' . Campaign_Text::names( $excluded, '个商品' );
		}

		$rule = (array) get_post_meta( $id, Customer_Segments::COUPON_META, true );
		$who  = Campaign_Text::who( $rule, Customer_Segments::catalog() );
		if ( 'all' === ( $rule['customers'] ?? 'all' ) && $coupon->get_email_restrictions() ) {
			$who = '只给指定的 ' . count( (array) $coupon->get_email_restrictions() ) . ' 位顾客（按邮箱），其他人用不了';
		}

		$link = add_query_arg( Coupons::APPLY_ARG, rawurlencode( $code ), home_url( '/' ) );
		$how  = array( '顾客在购物车或结账页输入优惠码 ' . $code );
		if ( $first ) {
			array_unshift( $how, '新顾客在网站顶部的首单券条上点一下就领到' );
		}
		$how[] = '广告链接可以直接带上它：在任意页面网址后面加 ?' . Coupons::APPLY_ARG . '=' . $code . '（例如 ' . $link . '），顾客点进来自动带上这张券';

		$expires = $coupon->get_date_expires();
		return array(
			'kind'   => $first ? self::KINDS['first_order'] : self::KINDS['coupon'],
			'pairs'  => array( array( implode( '；', $text['need'] ), implode( '；', $text['get'] ) ) ),
			'scope'  => $scope,
			'who'    => $who,
			'how'    => implode( '。', $how ),
			'stack'  => $text['stack'],
			'limits' => $text['limits'],
			'period' => self::period( Coupon_Rules::start( $coupon ), $expires ? (int) $expires->getTimestamp() : 0, $now ),
			'site'   => array( $code . '：' . Coupon_Rules::rule_text( $coupon ) ),
		);
	}

	private static function shipping_plain( array $d, int $now ): array {
		$symbol = self::symbol();
		$get    = array( Campaign_Text::shipping_get( (string) $d['kind'], (float) Discounts::value_now( $d, $now ), $symbol ) );
		if ( 'stages' === $d['mode'] ) {
			foreach ( (array) $d['stages'] as $stage ) {
				if ( (int) $stage['start'] > $now ) {
					$get[] = self::when( (int) $stage['start'] ) . ' 起改为：' . Campaign_Text::shipping_get( (string) $d['kind'], (float) $stage['value'], $symbol );
				}
			}
		}
		$need = array();
		if ( ! empty( $d['member_day'] ) ) {
			$days = Campaign_Text::member_days( (array) $d['member_days'] );
			if ( '' !== $days ) {
				$need[] = $days;
			}
		}
		return array(
			'kind'   => self::KINDS['shipping_discount'],
			'pairs'  => array( array( $need ? implode( '；', $need ) : '没有门槛：订单有运费就减', implode( '；', $get ) ) ),
			'scope'  => '全店商品（按整单的运费算）',
			'who'    => Campaign_Text::who( $d, Customer_Segments::catalog() ),
			'how'    => '自动生效：结账页的运费直接显示折后的价格',
			'stack'  => array( '同时满足几个运费折扣时不叠加，顾客拿到的是运费最低的那一个', '和满减活动、优惠码互不影响，可以同时享受' ),
			'limits' => array(),
			'period' => self::period( (int) $d['start'], (int) $d['end'], $now ),
			'site'   => Discounts::rule_lines( $d ),
		);
	}

	private static function offer_plain( array $offer, int $now ): array {
		$symbol = self::symbol();
		if ( 'bundle' === $offer['kind'] ) {
			$slots = array();
			foreach ( (array) $offer['slots'] as $slot ) {
				$slots[] = array( 'label' => (string) ( $slot['label'] ?? '' ), 'qty' => (int) ( $slot['qty'] ?? 1 ), 'choices' => self::product_names( (array) ( $slot['choices'] ?? array() ) ) );
			}
			$text = Campaign_Text::bundle( $offer, $slots, $symbol );
			$need = implode( '；', $text['need'] );
			$how  = '顾客在商品页选好组合，点一次按钮整组加进购物车';
		} else {
			$gift = self::product_names( array( (int) $offer['gift_product'] ) );
			$text = Campaign_Text::gift( $offer, self::product_names( (array) $offer['qualifiers'] ), (string) ( $gift[0] ?? '' ), $symbol );
			$need = $text['need'];
			$how  = '自动生效：满足条件时赠品自动出现在购物车里，价格是 0';
		}
		return array(
			'kind'   => self::KINDS[ 'bundle' === $offer['kind'] ? 'bundle' : 'gift' ],
			'pairs'  => array( array( $need, $text['get'] ) ),
			'scope'  => '',
			'who'    => '所有顾客',
			'how'    => $how,
			'stack'  => array( Campaign_Text::offer_stack( (string) $offer['kind'] ) ),
			'limits' => array(),
			'period' => self::period( (int) $offer['start'], (int) $offer['end'], $now ),
			'site'   => array(),
		);
	}

	private static function exit_plain( array $exit ): array {
		$text = Campaign_Text::exit_intent( $exit );
		$site = array_values( array_filter( array( trim( (string) $exit['title'] ), trim( (string) $exit['text'] ) ) ) );
		return array(
			'kind'   => $text['kind'],
			'pairs'  => array( array( implode( '；', $text['need'] ), implode( '；', $text['get'] ) ) ),
			'scope'  => '',
			'who'    => '网站的访客（后台员工看不到弹窗）',
			'how'    => $text['how'],
			'stack'  => array(),
			'limits' => array(),
			'period' => '一直开着，直到后台关掉',
			'site'   => $site,
		);
	}

	// ---------- 汇总 ----------

	/**
	 * @return array<int,array{kind:string,id:int,name:string,rule:string,ends:int,source:string,plain:array}>
	 *         ends = 结束时间（UTC 时间戳，0 = 长期）；plain 见文件开头。
	 */
	public static function running( ?int $now = null ): array {
		$now  = $now ?? time();
		$rows = array();

		// 赠品 / 捆绑先查：优惠码那一栏要知道店里现在有没有在跑的赠品和捆绑。
		$offers  = Product_Offers::all( true );
		$running = array( 'gift' => false, 'bundle' => false );
		foreach ( $offers as $offer ) {
			$running[ 'bundle' === $offer['kind'] ? 'bundle' : 'gift' ] = true;
		}

		foreach ( Promotions::active_ids( $now ) as $id ) {
			$c      = Promotions::config( $id );
			$rows[] = array( 'kind' => 'promotion', 'id' => (int) $id, 'name' => html_entity_decode( get_the_title( $id ) ), 'rule' => implode( '；', Promotions::rules_text( $c ) ), 'ends' => (int) $c['end'], 'source' => self::source( (int) $id ), 'plain' => self::promotion_plain( (int) $id, $c, $now ) );
		}

		$first = First_Order_Offer::settings();
		$ids   = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'DESC' ) );
		foreach ( $ids as $id ) {
			$coupon = new \WC_Coupon( (int) $id );
			// 顾客用积分换的个人券不是营销活动。
			if ( $coupon->get_meta( '_pc_loyalty_user' ) || 'active' !== Coupon_Rules::status( $coupon, $now ) ) {
				continue;
			}
			$expires  = $coupon->get_date_expires();
			$is_first = $first['enabled'] && $first['coupon_id'] === (int) $id;
			$rows[]   = array(
				'kind'   => $is_first ? 'first_order' : 'coupon',
				'id'     => (int) $id,
				'name'   => Coupon_Rules::display_code( $coupon ),
				'rule'   => Coupon_Rules::rule_text( $coupon ),
				'ends'   => $expires ? (int) $expires->getTimestamp() : 0,
				'source' => self::source( (int) $id ),
				'plain'  => self::coupon_plain( $coupon, (bool) $is_first, $running, $now ),
			);
		}

		foreach ( Discounts::all() as $d ) {
			if ( 'active' === Discounts::status( $d, $now ) && null !== Discounts::value_now( $d, $now ) ) {
				$rows[] = array( 'kind' => 'shipping_discount', 'id' => (int) $d['id'], 'name' => (string) $d['name'], 'rule' => implode( '；', Discounts::rule_lines( $d ) ), 'ends' => (int) $d['end'], 'source' => '' === (string) ( $d['series_key'] ?? '' ) ? 'store' : 'series', 'plain' => self::shipping_plain( $d, $now ) );
			}
		}

		foreach ( $offers as $id => $offer ) {
			$rows[] = array( 'kind' => 'bundle' === $offer['kind'] ? 'bundle' : 'gift', 'id' => (int) $id, 'name' => (string) $offer['title'], 'rule' => 'bundle' === $offer['kind'] ? ( 'fixed' === $offer['price_type'] ? '整组一口价' : '整组打折' ) : ( 'quantity' === $offer['basis'] ? '满件数送赠品' : '满金额送赠品' ), 'ends' => (int) $offer['end'], 'source' => self::source( (int) $id ), 'plain' => self::offer_plain( $offer, $now ) );
		}

		$exit = Exit_Intent::settings();
		if ( $exit['enabled'] ) {
			$types  = array( 'coupon' => '弹窗发优惠码 ' . strtoupper( (string) $exit['coupon'] ), 'wheel' => '抽奖转盘（' . count( (array) $exit['prizes'] ) . ' 个奖项）', 'message' => '自定义内容' );
			$rows[] = array( 'kind' => 'exit_intent', 'id' => 0, 'name' => (string) $exit['title'], 'rule' => $types[ $exit['type'] ] ?? (string) $exit['type'], 'ends' => 0, 'source' => '' === (string) ( $exit['series_key'] ?? '' ) ? 'store' : 'series', 'plain' => self::exit_plain( $exit ) );
		}
		return $rows;
	}

	/**
	 * 按只读页面的顺序分组：[ 分组键 => [ 行… ] ]，没有活动的分组不出现。
	 *
	 * @return array<string,array<int,array>>
	 */
	public static function grouped( ?int $now = null ): array {
		$out = array();
		foreach ( self::running( $now ) as $row ) {
			$out[ $row['kind'] ][] = $row;
		}
		return array_filter( array_replace( array_fill_keys( array_keys( self::GROUPS ), array() ), $out ) );
	}
}
