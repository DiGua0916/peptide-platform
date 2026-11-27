<?php
/**
 * 运费险实测看板（价格文档 §3.4，店主 2026-11-27）。
 *
 * 按拍板决议先跑满 30 天，三项核心指标：
 *   ① 保费勾选留存率   选了险的订单 ÷ 需要配送的订单。目标 ≥ 68%。
 *   ② 结账转化率        付款成功的订单 ÷ 建单总数（含未付、已取消）。看开险前后有没有掉。
 *   ③ 赔付占保费池比例  已赔付金额 ÷ 收到的保费总额。安全线 < 30%。
 *
 * 数据来源是订单上的三项元数据，不另外建表：
 *   _pc_ship_protect  yes / no   下单时有没有选（Protection::stamp_order 写）
 *   _pc_ship_premium  这一单收了多少保费（同上）
 *   _pc_ship_claim    这一单赔了多少（扣关、丢件时由运营手工填，默认没有就是 0）
 *
 * 指标只读，不改任何订单。算不出来时返回 null 而不是 0 —— 没有数据和「真的是 0」
 * 是两回事，看板上要分得清，否则第一天就会显示「留存率 0%」把人吓着。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

defined( 'ABSPATH' ) || exit;

final class Protection_Stats {

	public const META_CHOSEN  = '_pc_ship_protect';
	public const META_PREMIUM = '_pc_ship_premium';
	public const META_CLAIM   = '_pc_ship_claim';

	/** 目标线，只用于看板上标红 / 标绿，不参与计算。 */
	public const TARGET_KEEP  = 0.68;
	public const LIMIT_PAYOUT = 0.30;

	// ---------- 纯函数 ----------

	/**
	 * 把一批订单的原始数字算成三项指标。纯函数，给测试用。
	 *
	 * @param array{orders:int,shipping_orders:int,protected_orders:int,paid_orders:int,premium:float,claims:float} $raw
	 * @return array{keep_rate:?float,paid_rate:?float,payout_rate:?float,premium:float,claims:float,net:float,
	 *               keep_ok:?bool,payout_ok:?bool,orders:int,shipping_orders:int,protected_orders:int,paid_orders:int}
	 */
	public static function derive( array $raw ): array {
		$orders   = max( 0, (int) ( $raw['orders'] ?? 0 ) );
		$ship     = max( 0, (int) ( $raw['shipping_orders'] ?? 0 ) );
		$kept     = max( 0, (int) ( $raw['protected_orders'] ?? 0 ) );
		$paid     = max( 0, (int) ( $raw['paid_orders'] ?? 0 ) );
		$premium  = round( max( 0.0, (float) ( $raw['premium'] ?? 0 ) ), 2 );
		$claims   = round( max( 0.0, (float) ( $raw['claims'] ?? 0 ) ), 2 );

		$keep   = $ship > 0 ? round( $kept / $ship, 4 ) : null;
		$prate  = $orders > 0 ? round( $paid / $orders, 4 ) : null;
		$payout = $premium > 0 ? round( $claims / $premium, 4 ) : ( $claims > 0 ? null : null );

		return array(
			'orders'           => $orders,
			'shipping_orders'  => $ship,
			'protected_orders' => $kept,
			'paid_orders'      => $paid,
			'premium'          => $premium,
			'claims'           => $claims,
			'net'              => round( $premium - $claims, 2 ),
			'keep_rate'        => $keep,
			'paid_rate'        => $prate,
			'payout_rate'      => $payout,
			'keep_ok'          => null === $keep ? null : $keep >= self::TARGET_KEEP,
			'payout_ok'        => null === $payout ? null : $payout < self::LIMIT_PAYOUT,
		);
	}

	/** 把比率写成看板上那行字；没有数据时说「暂无数据」，不冒充 0%。 */
	public static function rate_text( ?float $rate ): string {
		return null === $rate ? '暂无数据' : number_format( $rate * 100, 1 ) . '%';
	}

	// ---------- 读订单 ----------

	/**
	 * 最近 N 天的原始数字。只读，不改订单。
	 *
	 * @param int $days 回看多少天，1–365
	 */
	public static function collect( int $days = 30 ): array {
		$days = max( 1, min( 365, $days ) );
		$raw  = array( 'orders' => 0, 'shipping_orders' => 0, 'protected_orders' => 0, 'paid_orders' => 0, 'premium' => 0.0, 'claims' => 0.0 );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return self::derive( $raw ) + array( 'days' => $days );
		}
		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$page          = 1;
		do {
			$orders = wc_get_orders(
				array(
					'limit'        => 200,
					'page'         => $page,
					'status'       => 'any',
					'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
					'return'       => 'objects',
				)
			);
			foreach ( (array) $orders as $o ) {
				if ( ! $o instanceof \WC_Order ) {
					continue;
				}
				$raw['orders']++;
				if ( in_array( $o->get_status(), $paid_statuses, true ) ) {
					$raw['paid_orders']++;
				}
				$chosen = (string) $o->get_meta( self::META_CHOSEN );
				if ( '' === $chosen ) {
					continue; // 开险之前的老订单不进分母，否则留存率会被稀释得毫无意义
				}
				$raw['shipping_orders']++;
				if ( 'yes' === $chosen ) {
					$raw['protected_orders']++;
					$raw['premium'] += (float) $o->get_meta( self::META_PREMIUM );
				}
				$raw['claims'] += max( 0.0, (float) $o->get_meta( self::META_CLAIM ) );
			}
			$page++;
		} while ( ! empty( $orders ) && count( (array) $orders ) === 200 && $page <= 50 );

		return self::derive( $raw ) + array( 'days' => $days );
	}

	/** 看板上那几行字，给后台页面直接用。 */
	public static function lines( ?array $m = null ): array {
		$m = $m ?? self::collect();
		return array(
			sprintf( '① 保费勾选留存率　%s（目标 ≥ %s）　%s',
				self::rate_text( $m['keep_rate'] ), self::rate_text( self::TARGET_KEEP ),
				null === $m['keep_ok'] ? '' : ( $m['keep_ok'] ? '达标' : '未达标' ) ),
			sprintf( '② 建单到付款转化率　%s（%d / %d 单）',
				self::rate_text( $m['paid_rate'] ), $m['paid_orders'], $m['orders'] ),
			sprintf( '③ 赔付占保费池　%s（安全线 < %s）　%s',
				self::rate_text( $m['payout_rate'] ), self::rate_text( self::LIMIT_PAYOUT ),
				null === $m['payout_ok'] ? '' : ( $m['payout_ok'] ? '安全' : '超线' ) ),
			sprintf( '　　保费收入 $%s，已赔付 $%s，净 $%s（最近 %d 天）',
				number_format( $m['premium'], 2 ), number_format( $m['claims'], 2 ), number_format( $m['net'], 2 ), $m['days'] ),
		);
	}
}
