<?php
/**
 * 购买意向（2026-11-10，店主要求“看出谁最可能下单、对哪块感兴趣”）。
 *
 * 只用本店自己记录的行为打分（0–100），不把顾客数据发给任何外部 AI 服务：
 * - 走到哪一步：进结账、填完地址、选了支付、点了下单 —— 权重最大；
 * - 购物车：有东西、金额越大越高；
 * - 兴趣深度：看了几个商品详情、在商品页停了多久、选过规格、点过加购；
 * - 回头客：以前来过；
 * - 正在浏览的比挂在后台的高。
 * 同时给出“最关注”的商品 / 版块和一句白话判断，供实时访客卡片和排行用。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Intent {

	/** 统计最近这么久的行为（毫秒）。 */
	private const WINDOW_MS = 6 * HOUR_IN_SECONDS * 1000;

	/**
	 * @param array $visitor  visitor_label() 的结果
	 * @param array $cart     Carts::view() 的结果
	 * @param string $presence active / idle / away
	 */
	public static function analyse( array $visitor, array $cart, string $presence, int $now_ms ): array {
		global $wpdb;
		$events = Schema::table( 'events' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT type, page_type, path, product_id, data, occurred_ms FROM {$events} WHERE visitor_id = %d AND occurred_ms >= %d AND type <> 'heartbeat' ORDER BY occurred_ms ASC LIMIT 2000", (int) $visitor['id'], $now_ms - self::WINDOW_MS ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$has      = array();
		$products = array();
		$modules  = array();
		$pages    = 0;
		$last_pv  = null;
		foreach ( (array) $rows as $e ) {
			$t         = (string) $e['type'];
			$has[ $t ] = ( $has[ $t ] ?? 0 ) + 1;
			$d         = json_decode( (string) $e['data'], true );
			$d         = is_array( $d ) ? $d : array();
			$pid       = (int) $e['product_id'];
			$name      = html_entity_decode( (string) ( $d['product_name'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( 'page_view' === $t ) {
				++$pages;
				// 商品页停留：到下一次打开页面为止（最多算 10 分钟）。
				if ( $last_pv && $last_pv['pid'] ) {
					self::dwell( $products, $last_pv, min( 600000, (int) $e['occurred_ms'] - $last_pv['ms'] ) );
				}
				$last_pv = array( 'pid' => 'product' === $e['page_type'] ? $pid : 0, 'name' => $name, 'ms' => (int) $e['occurred_ms'] );
			}
			if ( $pid && in_array( $t, array( 'product_view', 'product_click', 'product_intent', 'variant_select', 'add_to_cart_attempt', 'add_to_cart' ), true ) ) {
				if ( ! isset( $products[ $pid ] ) ) {
					$products[ $pid ] = array( 'id' => $pid, 'name' => $name, 'score' => 0, 'dwell_ms' => 0, 'views' => 0, 'carted' => false );
				}
				if ( '' === $products[ $pid ]['name'] && '' !== $name ) {
					$products[ $pid ]['name'] = $name;
				}
				$w = array( 'product_view' => 3, 'product_click' => 2, 'product_intent' => 2, 'variant_select' => 3, 'add_to_cart_attempt' => 4, 'add_to_cart' => 6 )[ $t ];
				$products[ $pid ]['score'] += $w;
				if ( 'product_view' === $t ) {
					++$products[ $pid ]['views'];
				}
				if ( 'add_to_cart' === $t ) {
					$products[ $pid ]['carted'] = true;
				}
			}
			if ( 'module_click' === $t ) {
				$key             = (string) ( $d['module_type'] ?? $d['module_id'] ?? 'module' );
				$modules[ $key ] = ( $modules[ $key ] ?? 0 ) + 1;
			}
		}
		if ( $last_pv && $last_pv['pid'] ) {
			self::dwell( $products, $last_pv, min( 600000, max( 0, $now_ms - $last_pv['ms'] ) ) );
		}

		$score   = 0;
		$reasons = array();
		$parts   = array(); // 得分构成（2026-11-10 店主：要看清分数差在哪）：名称、得分、满分、说明。
		$c       = static fn( string $t ): int => (int) ( $has[ $t ] ?? 0 );

		// 1. 结账进度（满分 45）
		$got = 0; $note = '还没进结账页';
		if ( $c( 'checkout_submit_attempt' ) || $c( 'payment_attempt' ) ) {
			$got = 45; $note = '点过「下单」/ 去支付';
		} elseif ( $c( 'checkout_payment_selected' ) ) {
			$got = 38; $note = '选好了支付方式，还没点下单（满分要点下单）';
		} elseif ( $c( 'checkout_address_completed' ) ) {
			$got = 32; $note = '地址填完了，还没选支付 / 点下单';
		} elseif ( $c( 'checkout_view' ) ) {
			$got = 24; $note = '进了结账页，地址没填完';
		}
		$score += $got;
		if ( $got ) { $reasons[] = $note; }
		$parts[] = array( 'name' => '结账进度', 'got' => $got, 'max' => 45, 'note' => $note );
		if ( $c( 'payment_failed' ) ) {
			$reasons[] = '付款失败过 ' . $c( 'payment_failed' ) . ' 次';
		}

		// 2. 购物车（满分 25：有货 15 + 金额每 $50 加 1，最多 10）
		$cart_amount = self::amount( (string) ( $cart['subtotal_text'] ?? '' ) );
		$got = 0; $note = '购物车是空的';
		if ( 'active' === ( $cart['status'] ?? '' ) && (int) ( $cart['count'] ?? 0 ) > 0 ) {
			$bonus = min( 10, (int) floor( $cart_amount / 50 ) );
			$got   = 15 + $bonus;
			$note  = '购物车 ' . (int) $cart['count'] . ' 件' . ( $cart['subtotal_text'] ? '（' . $cart['subtotal_text'] . '）' : '' ) . ( $bonus < 10 ? '；金额到 $500 才拿满' : '' );
			$reasons[] = '购物车 ' . (int) $cart['count'] . ' 件' . ( $cart['subtotal_text'] ? '（' . $cart['subtotal_text'] . '）' : '' );
		} elseif ( $c( 'add_to_cart' ) ) {
			$got = 10; $note = '加购过，但购物车现在是空的'; $reasons[] = '加购过';
		} elseif ( $c( 'add_to_cart_attempt' ) ) {
			$got = 6; $note = '点过加入购物车（没成功）'; $reasons[] = '点过加入购物车';
		}
		$score += $got;
		$parts[] = array( 'name' => '购物车', 'got' => $got, 'max' => 25, 'note' => $note );

		// 3. 兴趣深度
		$viewed = count( array_filter( $products, static fn( $p ) => $p['views'] > 0 ) );
		$dwell  = (int) array_sum( array_column( $products, 'dwell_ms' ) );
		$got    = $viewed ? min( 12, $viewed * 3 ) : 0;
		$score += $got;
		if ( $viewed ) { $reasons[] = '看了 ' . $viewed . ' 个商品详情'; }
		$parts[] = array( 'name' => '看商品详情', 'got' => $got, 'max' => 12, 'note' => $viewed ? '看了 ' . $viewed . ' 个（每个 3 分，4 个满分）' : '最近 6 小时没打开商品详情' );
		$got = $dwell >= 60000 ? min( 10, (int) floor( $dwell / 60000 ) * 3 ) : 0;
		$score += $got;
		if ( $got ) { $reasons[] = '商品页停留 ' . self::duration( $dwell ); }
		$parts[] = array( 'name' => '商品页停留', 'got' => $got, 'max' => 10, 'note' => $dwell ? '共 ' . self::duration( $dwell ) . '（每分钟 3 分，满 4 分钟满分）' : '没有商品页停留' );
		$got = $c( 'variant_select' ) ? 5 : 0;
		$score += $got;
		if ( $got ) { $reasons[] = '选过规格'; }
		$parts[] = array( 'name' => '选规格', 'got' => $got, 'max' => 5, 'note' => $got ? '选过规格' : '没选过规格' );
		$got = $pages >= 5 ? 3 : 0;
		$score += $got;
		$parts[] = array( 'name' => '浏览页数', 'got' => $got, 'max' => 3, 'note' => '最近 6 小时 ' . $pages . ' 页（5 页以上得分）' );

		// 4. 回头客、在线状态
		$got = (int) ( $visitor['visits'] ?? 0 ) > 1 ? 6 : 0;
		$score += $got;
		if ( $got ) { $reasons[] = '第 ' . (int) $visitor['visits'] . ' 次来'; }
		$parts[] = array( 'name' => '回头客', 'got' => $got, 'max' => 6, 'note' => $got ? '第 ' . (int) $visitor['visits'] . ' 次来' : '第一次来' );
		$got = 'active' === $presence ? 4 : ( 'away' === $presence ? -3 : 0 );
		$score += $got;
		$parts[] = array( 'name' => '此刻在线', 'got' => $got, 'max' => 4, 'note' => 'active' === $presence ? '正在浏览' : ( 'away' === $presence ? '已经离开（扣 3 分）' : '页面开着但没操作' ) );
		if ( $c( 'payment_success' ) ) {
			$score = max( $score, 95 );
			$reasons[] = '已经付款';
		}
		$score = max( 0, min( 100, $score ) );

		uasort( $products, static fn( $a, $b ) => array( $b['carted'], $b['score'], $b['dwell_ms'] ) <=> array( $a['carted'], $a['score'], $a['dwell_ms'] ) );
		$top_products = array_slice( array_values( array_filter( $products, static fn( $p ) => '' !== $p['name'] ) ), 0, 3 );
		arsort( $modules );
		$module_labels = Labels::modules();
		$top_module    = $modules ? (string) array_key_first( $modules ) : '';

		$level = $score >= 60 ? 'high' : ( $score >= 30 ? 'mid' : 'low' );
		return array(
			'score'        => $score,
			'level'        => $level,
			'level_text'   => array( 'high' => '很可能下单', 'mid' => '有兴趣', 'low' => '随便看看' )[ $level ],
			'reasons'      => array_slice( $reasons, 0, 4 ),
			'products'     => array_map( static fn( $p ) => array( 'id' => $p['id'], 'name' => $p['name'], 'carted' => $p['carted'], 'dwell' => $p['dwell_ms'] ? self::duration( $p['dwell_ms'] ) : '' ), $top_products ),
			'module'       => '' !== $top_module ? ( $module_labels[ $top_module ] ?? $top_module ) : '',
			'pages'        => $pages,
			'advice'       => self::advice( $level, $has, $cart, $top_products ),
			'parts'        => $parts, // 满分合计 110，封顶 100。
		);
	}

	private static function dwell( array &$products, array $pv, int $ms ): void {
		if ( $ms <= 0 ) {
			return;
		}
		$pid = (int) $pv['pid'];
		if ( ! isset( $products[ $pid ] ) ) {
			$products[ $pid ] = array( 'id' => $pid, 'name' => (string) $pv['name'], 'score' => 0, 'dwell_ms' => 0, 'views' => 0, 'carted' => false );
		}
		$products[ $pid ]['dwell_ms'] += $ms;
		$products[ $pid ]['score']    += min( 6, (int) floor( $ms / 30000 ) );
	}

	/** 一句白话建议：店主现在该做什么。 */
	private static function advice( string $level, array $has, array $cart, array $top ): string {
		$name = $top ? $top[0]['name'] : '';
		if ( ! empty( $has['payment_failed'] ) ) {
			return '付款失败了，可以发付款帮助信或在 WhatsApp 上联系。';
		}
		if ( ! empty( $has['checkout_address_completed'] ) || ! empty( $has['checkout_payment_selected'] ) ) {
			return '已经填了结账信息，没付款就会进弃单，留意召回。';
		}
		if ( 'high' === $level || ( 'active' === ( $cart['status'] ?? '' ) && (int) ( $cart['count'] ?? 0 ) > 0 ) ) {
			return '购物车有货，适合弹优惠或提醒满减' . ( $name ? '（主要看 ' . $name . '）' : '' ) . '。';
		}
		if ( 'mid' === $level && $name ) {
			return '在比较 ' . $name . '，详情页的检测报告和评价最能打动他。';
		}
		return '还在随便逛，暂时不用管。';
	}

	private static function amount( string $text ): float {
		return preg_match( '/([0-9][0-9,]*\.?[0-9]*)/', str_replace( ',', '', $text ), $m ) ? (float) $m[1] : 0.0;
	}

	public static function duration( int $ms ): string {
		$s = (int) round( $ms / 1000 );
		return $s >= 60 ? floor( $s / 60 ) . ' 分 ' . ( $s % 60 ) . ' 秒' : $s . ' 秒';
	}
}
