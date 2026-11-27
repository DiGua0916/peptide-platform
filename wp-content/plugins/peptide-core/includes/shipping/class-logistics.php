<?php
/**
 * 物流引擎（价格文档第三章，店主 2026-11-27）：按货物类别自动算重、超重加收、渠道规避、货代灾备池。
 *
 * 地基是商品上的两项元数据（§3.1，已随定案表写入）：
 *   _pc_unit_weight_g  单盒精确毛重
 *   _pc_cargo_class    渠道合规属性，四选一：
 *     LYO_125G       纯冻干粉标准件：港联、陆联、国际专线全通
 *     LIQ_3ML_105G   微量溶媒：陆联禁发
 *     LIQ_10ML_260G  重型水剂：陆联禁发 + 触发超重阶梯计费
 *     HGH_125G       生长激素敏感件：陆联禁发，锁香港联邦
 *
 * 做三件事（都在 Plans 给出运费之后）：
 *
 * 1. 超重附加费（§3.3.2）：包裹总毛重 = 外箱包材 60g + Σ(数量 × 单盒重)。
 *    重型水剂满 2 盒、或总毛重超过 1000g 时，按每 500g 加收 $10（Heavy Cold-Chain Handling）。
 *    订单满 $500 时豁免第一档，给大客户让一点。
 *
 * 2. 陆联规避（§3.3.3）：车里有生长激素或任意重型水剂时，直接把「陆联」那条运费从结账页拿掉，
 *    只留港联。从源头杜绝顾客花钱买加急却被陆路安检退件。哪条算陆联由设置里的关键词判定
 *    （ground_keywords），避免把判断写死在代码里。
 *
 * 3. 履约路由标签（§3.3.3 情况 B）：下单时在订单上盖一个 _pc_route 标签，告诉代发仓这票该怎么走。
 *
 * 另外存一份货代灾备池（§3.2）：3–5 家货代的首重 / 续重报价和当前启用哪家。
 * 这份只用于后台算内部成本和毛利预警，不参与前台报价 —— 换货代时顾客看到的运费不变，不惊扰老客。
 *
 * 默认关闭（enabled = false）。部署代码不会改变任何现有行为，要在设置里显式打开。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

defined( 'ABSPATH' ) || exit;

final class Logistics {

	public const OPTION     = 'peptide_core_logistics';
	public const ORDER_META = '_pc_route';

	/** 货物类别。 */
	public const LYO   = 'LYO_125G';
	public const LIQ3  = 'LIQ_3ML_105G';
	public const LIQ10 = 'LIQ_10ML_260G';
	public const HGH   = 'HGH_125G';

	/** 类别 => 默认单盒毛重（克）。商品上没写 _pc_unit_weight_g 时按这个兜底。 */
	public const CLASS_WEIGHT = array(
		self::LYO   => 125,
		self::LIQ3  => 105,
		self::LIQ10 => 260,
		self::HGH   => 125,
	);

	/** 这些类别不能走陆联。 */
	public const NO_GROUND = array( self::LIQ3, self::LIQ10, self::HGH );

	/** 外箱缓冲包材固定基础重（克）。 */
	public const BOX_G = 60;

	public static function register_hooks(): void {
		// Plans::only_plan_rates 在 100，这里必须在它之后。
		add_filter( 'woocommerce_package_rates', array( self::class, 'apply' ), 110, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'stamp_route' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'stamp_route' ), 20 );
	}

	// ---------- 设置 ----------

	public static function defaults(): array {
		return array(
			'enabled'             => false, // 部署不改行为，要显式打开
			'heavy_fee'           => 10.0,  // 每超一档加收
			'heavy_step_g'        => 500,   // 一档多少克
			'heavy_trigger_g'     => 1000,  // 总毛重超过这个数开始算超重
			'heavy_liquid_boxes'  => 2,     // 重型水剂满几盒就算超重
			'vip_waiver_subtotal' => 500.0, // 订单满这个数豁免第一档；0 = 不豁免
			'surcharge_label'     => 'Heavy Cold-Chain Handling',
			'ground_keywords'     => array( '3-5', '3–5', 'ultra', 'ground', '陆联' ),
			'active_forwarder'    => 'A',
			'forwarders'          => array(
				// 只供后台算成本，不进前台报价。首重 / 续重单位：人民币。
				'A' => array( 'name' => '主力货代 A', 'hk_first' => 365.0, 'hk_add' => 40.0,  'cn_first' => 0.0,   'cn_add' => 0.0,   'intl_first' => 0.0,   'intl_add' => 0.0 ),
				'B' => array( 'name' => '备选货代 B', 'hk_first' => 420.0, 'hk_add' => 100.0, 'cn_first' => 530.0, 'cn_add' => 100.0, 'intl_first' => 300.0, 'intl_add' => 100.0 ),
				'C' => array( 'name' => '灾备货代 C', 'hk_first' => 420.0, 'hk_add' => 120.0, 'cn_first' => 550.0, 'cn_add' => 100.0, 'intl_first' => 300.0, 'intl_add' => 90.0 ),
			),
			'fx'                  => 6.45, // 人民币兑美元，算内部成本用
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$s     = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		$s['forwarders'] = is_array( $s['forwarders'] ) && $s['forwarders'] ? $s['forwarders'] : self::defaults()['forwarders'];
		$s['ground_keywords'] = array_values( array_filter( array_map( 'strval', (array) $s['ground_keywords'] ) ) );
		return $s;
	}

	public static function enabled(): bool {
		return ! empty( self::settings()['enabled'] );
	}

	// ---------- 纯函数：算重与分类 ----------

	/** 一个商品的货物类别；没标的按纯冻干粉算。 */
	public static function cargo_class( $product ): string {
		$c = $product instanceof \WC_Product ? (string) $product->get_meta( '_pc_cargo_class' ) : '';
		return isset( self::CLASS_WEIGHT[ $c ] ) ? $c : self::LYO;
	}

	/** 一个商品的单盒毛重（克）：优先用商品上写的，没有就按类别兜底。 */
	public static function unit_weight( $product ): int {
		$w = $product instanceof \WC_Product ? (int) $product->get_meta( '_pc_unit_weight_g' ) : 0;
		return $w > 0 ? $w : self::CLASS_WEIGHT[ self::cargo_class( $product ) ];
	}

	/**
	 * 把购物车明细收成算费要用的几个数。纯函数，给测试用。
	 *
	 * @param array<int,array{class:string,weight:int,qty:int}> $lines
	 * @return array{weight:int,counts:array<string,int>,boxes:int}
	 */
	public static function summarize( array $lines ): array {
		$weight = self::BOX_G;
		$counts = array( self::LYO => 0, self::LIQ3 => 0, self::LIQ10 => 0, self::HGH => 0 );
		$boxes  = 0;
		foreach ( $lines as $l ) {
			$qty = max( 0, (int) ( $l['qty'] ?? 0 ) );
			if ( ! $qty ) {
				continue;
			}
			$cls = isset( self::CLASS_WEIGHT[ $l['class'] ?? '' ] ) ? $l['class'] : self::LYO;
			$w   = (int) ( $l['weight'] ?? 0 );
			$w   = $w > 0 ? $w : self::CLASS_WEIGHT[ $cls ];
			$weight         += $w * $qty;
			$counts[ $cls ] += $qty;
			$boxes          += $qty;
		}
		return array( 'weight' => $weight, 'counts' => $counts, 'boxes' => $boxes );
	}

	/** 从 WooCommerce 包裹读出同样的几个数。 */
	public static function summarize_package( array $package ): array {
		$lines = array();
		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			$p = $item['data'] ?? null;
			if ( ! $p instanceof \WC_Product || ! $p->needs_shipping() ) {
				continue;
			}
			$lines[] = array(
				'class'  => self::cargo_class( $p ),
				'weight' => self::unit_weight( $p ),
				'qty'    => (int) ( $item['quantity'] ?? 1 ),
			);
		}
		return self::summarize( $lines );
	}

	// ---------- 纯函数：超重附加费 ----------

	/**
	 * 超重附加费（§3.3.2）。
	 *
	 * 触发条件二选一：重型水剂满 N 盒，或总毛重超过触发线。
	 * 收费：超出触发线的部分每满一档收一次，不足一档也算一档（向上取整）；
	 * 只因盒数触发、重量还没过线时，收一档。
	 * 订单满 VIP 门槛时豁免第一档。
	 *
	 * @param array $s 设置
	 */
	public static function surcharge( int $weight_g, int $liquid10_boxes, float $subtotal, array $s ): float {
		$step    = max( 1, (int) $s['heavy_step_g'] );
		$trigger = max( 0, (int) $s['heavy_trigger_g'] );
		$by_box  = (int) $s['heavy_liquid_boxes'] > 0 && $liquid10_boxes >= (int) $s['heavy_liquid_boxes'];
		$by_wt   = $weight_g > $trigger;
		if ( ! $by_box && ! $by_wt ) {
			return 0.0;
		}
		$steps = $by_wt ? (int) ceil( ( $weight_g - $trigger ) / $step ) : 0;
		$steps = max( $steps, 1 ); // 只因盒数触发时也至少一档
		$waive = (float) $s['vip_waiver_subtotal'];
		if ( $waive > 0 && $subtotal >= $waive ) {
			$steps--; // 大客户豁免第一档
		}
		return $steps > 0 ? round( $steps * (float) $s['heavy_fee'], 2 ) : 0.0;
	}

	// ---------- 纯函数：渠道规避 ----------

	/** 这一车能不能走陆联：含生长激素或任意水剂就不能。 */
	public static function ground_allowed( array $counts ): bool {
		foreach ( self::NO_GROUND as $cls ) {
			if ( (int) ( $counts[ $cls ] ?? 0 ) > 0 ) {
				return false;
			}
		}
		return true;
	}

	/** 一条运费是不是陆联。按设置里的关键词判定，不写死。 */
	public static function is_ground( string $label, array $keywords ): bool {
		$l = strtolower( $label );
		foreach ( $keywords as $k ) {
			$k = strtolower( trim( (string) $k ) );
			if ( '' !== $k && false !== strpos( $l, $k ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 履约路由标签（§3.3.3）：告诉代发仓这票怎么发。
	 * 纯冻干粉 + 顺手一盒轻溶媒 = 要么拆单、要么整票走港联直飞，所以单独标出来。
	 */
	public static function route_label( array $counts ): string {
		$hgh   = (int) ( $counts[ self::HGH ] ?? 0 );
		$liq10 = (int) ( $counts[ self::LIQ10 ] ?? 0 );
		$liq3  = (int) ( $counts[ self::LIQ3 ] ?? 0 );
		if ( $hgh > 0 ) {
			return 'HK_ONLY_HGH';
		}
		if ( $liq10 > 0 ) {
			return 'HK_ONLY_HEAVY_LIQUID';
		}
		if ( $liq3 > 0 ) {
			return 'SPLIT_BA3_TO_HK_OR_HK_VIP_FLIGHT';
		}
		return 'STANDARD';
	}

	// ---------- 纯函数：货代成本（后台毛利预警用，不进前台） ----------

	/** 计费重的档数：每 500g 一档，向上取整，至少一档。$extra_g 是陆联的资料重。 */
	public static function billable_steps( int $weight_g, int $extra_g = 0 ): int {
		return max( 1, (int) ceil( ( $weight_g + $extra_g ) / 500 ) );
	}

	/**
	 * 当前启用货代发这一票的内部成本（美元）。
	 *
	 * @param string $channel hk | cn | intl
	 */
	public static function forwarder_cost( int $weight_g, string $channel, array $s ): float {
		$f = $s['forwarders'][ $s['active_forwarder'] ] ?? null;
		if ( ! is_array( $f ) ) {
			return 0.0;
		}
		$channel = in_array( $channel, array( 'hk', 'cn', 'intl' ), true ) ? $channel : 'hk';
		$first   = (float) ( $f[ $channel . '_first' ] ?? 0 );
		$add     = (float) ( $f[ $channel . '_add' ] ?? 0 );
		if ( $first <= 0 ) {
			return 0.0; // 这家货代不走这条线
		}
		$extra = 'cn' === $channel ? 200 : 0; // 陆联要把资料重算进计费重
		$steps = self::billable_steps( $weight_g, $extra );
		$fx    = max( 0.01, (float) $s['fx'] );
		return round( ( $first + $add * ( $steps - 1 ) ) / $fx, 2 );
	}

	// ---------- 挂钩 ----------

	/**
	 * 在 Plans 给出运费之后：拿掉不能走的渠道，给剩下的加上超重费。
	 *
	 * @param array $rates
	 * @param array $package
	 * @return array
	 */
	public static function apply( $rates, $package = array() ) {
		if ( ! is_array( $rates ) || ! $rates || ! self::enabled() ) {
			return $rates;
		}
		$s   = self::settings();
		$sum = self::summarize_package( (array) $package );
		if ( ! $sum['boxes'] ) {
			return $rates;
		}
		// 1. 不能走陆联时，把陆联那几条拿掉；但不能把顾客的选项清空。
		if ( ! self::ground_allowed( $sum['counts'] ) ) {
			$keep = array();
			foreach ( $rates as $key => $rate ) {
				if ( ! self::is_ground( (string) $rate->get_label(), $s['ground_keywords'] ) ) {
					$keep[ $key ] = $rate;
				}
			}
			if ( $keep ) { // 全被滤掉就不动，宁可多给选项也不能让人没法下单
				$rates = $keep;
			}
		}
		// 2. 超重附加费
		$subtotal = 0.0;
		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			$subtotal += (float) ( $item['line_subtotal'] ?? 0 );
		}
		$fee = self::surcharge( $sum['weight'], (int) $sum['counts'][ self::LIQ10 ], $subtotal, $s );
		if ( $fee > 0 ) {
			foreach ( $rates as $rate ) {
				$rate->set_cost( round( (float) $rate->get_cost() + $fee, 2 ) );
				$rate->add_meta_data( '_pc_heavy_fee', (string) $fee );
				$rate->add_meta_data( '_pc_parcel_g', (string) $sum['weight'] );
			}
		}
		return $rates;
	}

	/** 下单时盖履约路由标签。 */
	public static function stamp_route( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::enabled() ) {
			return;
		}
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$p = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			if ( ! $p instanceof \WC_Product ) {
				continue;
			}
			$lines[] = array(
				'class'  => self::cargo_class( $p ),
				'weight' => self::unit_weight( $p ),
				'qty'    => (int) $item->get_quantity(),
			);
		}
		if ( ! $lines ) {
			return;
		}
		$sum = self::summarize( $lines );
		$order->update_meta_data( self::ORDER_META, self::route_label( $sum['counts'] ) );
		$order->update_meta_data( '_pc_parcel_g', $sum['weight'] );
	}
}
