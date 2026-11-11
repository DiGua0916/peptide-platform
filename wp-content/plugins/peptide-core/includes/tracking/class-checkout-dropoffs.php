<?php
/**
 * 仪表盘漏斗“发起结账”点进去看是谁（2026-11-10，店主要求：定位到发起结账却没下单的客户，看为什么没下单）。
 *
 * 口径和仪表盘漏斗一样（Data\Visitor_Funnel）：时间段内开始的访问，走到结账（stage ≥ 3）的不同访客，一人一行。
 * 每人汇总这段时间里那几次访问的事件：
 * - 走到哪一步：打开结账页 / 填完地址 / 点了下单 / 下了单 / 付款失败 / 已付款；
 * - 结账页停了多久（同一个页面实例里第一条到最后一条事件，心跳也算）；
 * - 离开结账页之后做了什么（回去看商品 / 直接走了）；
 * - 购物车、运费、合计、填过的联系信息（WooCommerce 会话里有就显示）；
 * - 一句白话判断“为什么没下单”。
 * 只读，不改任何数据。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

use PeptideCore\Recovery_Items;

defined( 'ABSPATH' ) || exit;

final class Checkout_Dropoffs {

	/** 走到的最远一步（数字越大越靠后）。 */
	public const STEPS = array(
		'checkout' => '打开结账页',
		'address'  => '填完地址',
		'submit'   => '点了下单',
		'order'    => '下了单没付款',
		'failed'   => '付款失败',
		'paid'     => '已付款',
	);
	private const RANK = array( 'checkout' => 1, 'address' => 2, 'submit' => 3, 'order' => 4, 'failed' => 5, 'paid' => 6 );

	/** 漏斗里能点的层 => 至少走到哪一步（添加物流 / 支付在结账页一打开就有默认值，和“填完地址”同一批人）。 */
	public const FUNNEL_FILTER = array( 'checkout' => 'checkout', 'address' => 'address', 'shipping' => 'address', 'payment' => 'address', 'paid' => 'paid' );

	/**
	 * @return array{rows:array,summary:array}
	 */
	public static function list( int $from_ms, int $to_ms ): array {
		if ( ! Schema::installed() ) {
			return array( 'rows' => array(), 'summary' => array() );
		}
		global $wpdb;
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$events   = Schema::table( 'events' );
		$groups   = $wpdb->get_results( $wpdb->prepare( "SELECT visitor_id, GROUP_CONCAT(id) AS ids FROM {$visits} WHERE start_ms >= %d AND start_ms < %d AND stage >= 3 GROUP BY visitor_id LIMIT 500", $from_ms, $to_ms ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows     = array();
		foreach ( (array) $groups as $g ) {
			$vid = (int) $g['visitor_id'];
			$ids = implode( ',', array_map( 'intval', explode( ',', (string) $g['ids'] ) ) );
			$v   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$visitors} WHERE id = %d", $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $v ) {
				continue;
			}
			$ev    = $wpdb->get_results( "SELECT type, source, occurred_ms, page_instance_id, page_type, path, product_id, order_id, data FROM {$events} WHERE visit_id IN ({$ids}) ORDER BY occurred_ms ASC, id ASC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$visit = $wpdb->get_row( "SELECT * FROM {$visits} WHERE id IN ({$ids}) ORDER BY stage DESC, start_ms ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows[] = self::row( $v, (array) $visit, (array) $ev );
		}
		usort( $rows, static fn( $a, $b ) => $b['checkout_ms'] <=> $a['checkout_ms'] );
		return array( 'rows' => $rows, 'summary' => self::summary( $rows ) );
	}

	private static function row( array $v, array $visit, array $ev ): array {
		$step        = 'checkout';
		$checkout_ms = 0;
		$pages       = array(); // 结账页实例 => [开始, 最后]
		$order_id    = 0;
		$fail_msg    = '';
		$pay_method  = '';
		$ship_method = '';
		$after       = array( 'products' => array(), 'pages' => 0 );
		$last_ck_end = 0;
		$last_ms     = 0;
		$hidden_end  = false;
		foreach ( $ev as $e ) {
			$t  = (string) $e['type'];
			$ms = (int) $e['occurred_ms'];
			$d  = json_decode( (string) $e['data'], true );
			$d  = is_array( $d ) ? $d : array();
			if ( 'checkout' === $e['page_type'] || 'checkout_view' === $t ) {
				$pi = (string) $e['page_instance_id'];
				if ( '' !== $pi ) {
					$pages[ $pi ] = array( $pages[ $pi ][0] ?? $ms, $ms );
				}
			}
			if ( 'checkout_view' === $t && ! $checkout_ms ) {
				$checkout_ms = $ms;
			}
			if ( 'checkout_payment_selected' === $t && ! empty( $d['method'] ) ) {
				$pay_method = (string) $d['method'];
			}
			if ( 'checkout_shipping_selected' === $t && ! empty( $d['method'] ) ) {
				$ship_method = (string) $d['method'];
			}
			$rank = 0;
			if ( 'checkout_address_completed' === $t ) {
				$rank = 2;
			} elseif ( 'checkout_submit_attempt' === $t ) {
				$rank = 3;
			} elseif ( in_array( $t, array( 'order_placed', 'payment_attempt' ), true ) ) {
				$rank = 4;
			} elseif ( 'payment_failed' === $t ) {
				$rank     = 5;
				$fail_msg = (string) ( $d['message'] ?? $d['reason'] ?? $d['error'] ?? '' );
			} elseif ( 'payment_success' === $t ) {
				$rank = 6;
			}
			if ( (int) $e['order_id'] ) {
				$order_id = (int) $e['order_id'];
			}
			if ( $rank > self::RANK[ $step ] ) {
				$step = array_search( $rank, self::RANK, true );
			}
			if ( 'heartbeat' !== $t ) {
				$last_ms    = $ms;
				$hidden_end = in_array( $t, array( 'page_exit', 'visibility_change' ), true );
			}
		}
		if ( ! $order_id && ! empty( $visit['order_id'] ) ) {
			$order_id = (int) $visit['order_id'];
		}
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order && ( $order->is_paid() || $order->get_date_paid() ) ) {
			$step = 'paid';
		} elseif ( $order && self::RANK[ $step ] < 4 ) {
			$step = 'order';
		}
		// 结账页停留：每个结账页实例第一条到最后一条事件。
		$dwell = 0;
		foreach ( $pages as [ $a, $b ] ) {
			$dwell      += max( 0, $b - $a );
			$last_ck_end = max( $last_ck_end, $b );
		}
		// 离开结账页之后：又看了哪些页面 / 商品。
		$seen = array();
		foreach ( $ev as $e ) {
			if ( (int) $e['occurred_ms'] <= $last_ck_end || 'checkout' === $e['page_type'] ) {
				continue;
			}
			if ( 'page_view' === $e['type'] ) {
				++$after['pages'];
			}
			$pid = (int) $e['product_id'];
			if ( $pid && 'product_view' === $e['type'] && ! isset( $seen[ $pid ] ) ) {
				$seen[ $pid ]         = true;
				$after['products'][] = html_entity_decode( (string) get_the_title( $pid ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		// 购物车 / 会话里的顾客资料。
		$cart    = Carts::view( $v, true );
		$session = '' !== (string) $v['cart_key'] && class_exists( Recovery_Items::class ) ? Recovery_Items::session_data( (string) $v['cart_key'] ) : array();
		$billing = (array) ( $session['billing'] ?? array() );
		$totals  = (array) ( $session['totals'] ?? array() );
		$contact = array(
			'name'    => trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ),
			'email'   => (string) ( $billing['email'] ?? '' ),
			'phone'   => (string) ( $billing['phone'] ?? '' ),
			'country' => '',
			'city'    => (string) ( $billing['city'] ?? '' ),
		);
		if ( $order ) {
			$contact['name']  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $contact['name'];
			$contact['email'] = $order->get_billing_email() ?: $contact['email'];
			$contact['phone'] = $order->get_billing_phone() ?: $contact['phone'];
			$contact['city']  = $order->get_billing_city() ?: $contact['city'];
		}
		// 国家：只有顾客真的填过地址 / 下过单才可信（没填时 Woo 会话里是默认值）。
		if ( self::RANK[ $step ] >= 2 ) {
			$c                  = $order ? $order->get_billing_country() : (string) ( $billing['country'] ?? '' );
			$contact['country'] = $c ? ( WC()->countries->countries[ $c ] ?? $c ) : '';
		}
		if ( ! $order && '' === $contact['email'] && '' === $contact['name'] ) {
			$contact['city'] = '';
		}

		$query = array();
		if ( ! empty( $visit['attribution_query'] ) ) {
			parse_str( (string) $visit['attribution_query'], $query );
		}
		$devices = array( 'mobile' => '手机', 'desktop' => '电脑', 'tablet' => '平板' );
		$row     = array(
			'visitor_id'  => (int) $v['id'],
			// 访客数据 → 客户生命周期，直接打开走到结账的那次访问。
			'visitor_url' => admin_url( 'admin.php?page=pc-visitors&visitor=' . (int) $v['id'] . ( ! empty( $visit['id'] ) ? '&visit=' . (int) $visit['id'] : '' ) ),
			'device'      => $devices[ $v['device'] ] ?? (string) $v['device'],
			'browser'     => (string) $v['browser'],
			'visits'      => (int) $v['visits'],
			'source'      => self::source( $visit, $query ),
			'campaign'    => (string) ( $query['utm_campaign'] ?? '' ),
			'landing'     => (string) ( $visit['landing_path'] ?? '' ),
			'checkout_ms' => $checkout_ms ?: (int) ( $visit['start_ms'] ?? 0 ),
			'last_ms'     => $last_ms,
			'dwell_ms'    => $dwell,
			'step'        => $step,
			'step_text'   => self::STEPS[ $step ],
			'step_rank'   => self::RANK[ $step ],
			'pay_method'  => $pay_method,
			'ship_method' => $ship_method,
			'cart'        => $cart,
			'shipping'    => isset( $totals['shipping_total'] ) ? (float) $totals['shipping_total'] : null,
			'total'       => $order ? (float) $order->get_total() : ( isset( $totals['total'] ) ? (float) $totals['total'] : null ),
			'currency'    => $order ? $order->get_currency() : get_woocommerce_currency(),
			'contact'     => $contact,
			'order_id'    => $order ? $order->get_id() : 0,
			'order_no'    => $order ? $order->get_order_number() : '',
			'order_url'   => $order ? admin_url( 'admin.php?page=pc-recovery&view=' . $order->get_id() ) : '',
			'after'       => $after,
			'left_site'   => $hidden_end,
			'fail_msg'    => $fail_msg,
		);
		[ $row['reason_key'], $row['reason'] ] = self::reason( $row );
		// 测试单（收件信息全 1）：和仪表盘漏斗一样，只算在“发起结账”里，后面几层不算（2026-11-10）。
		$row['is_test'] = ( $order && self::is_test( $order ) )
			|| ( class_exists( \PeptideCore\Test_Orders::class ) && \PeptideCore\Test_Orders::is_test_customer( (array) ( $session['customer'] ?? array() ) ) );
		if ( $row['is_test'] ) {
			$row['step_rank']  = 1;
			$row['reason_key'] = 'test';
			$row['reason']     = '测试单';
		}
		return $row;
	}

	/** 一句白话：为什么没下单。 */
	private static function reason( array $r ): array {
		$dwell = self::duration( (int) $r['dwell_ms'] );
		$then  = '';
		if ( $r['after']['products'] ) {
			$then = '，之后回去看了 ' . implode( '、', array_slice( $r['after']['products'], 0, 3 ) );
		} elseif ( $r['after']['pages'] ) {
			$then = '，之后又逛了 ' . (int) $r['after']['pages'] . ' 个页面';
		} elseif ( $r['left_site'] ) {
			$then = '，然后离开了网站';
		}
		switch ( $r['step'] ) {
			case 'paid':
				return array( 'paid', '已经付款下单了' );
			case 'failed':
				return array( 'failed', '下单后付款失败' . ( $r['fail_msg'] ? '（' . $r['fail_msg'] . '）' : '' ) . '，可以发付款帮助信或在 WhatsApp 联系' );
			case 'order':
				return array( 'order', '下了单但没付款（订单 #' . $r['order_no'] . '），已进弃单，会按召回规则发邮件' );
			case 'submit':
				return array( 'submit', '点了“下单”但没提交成功：多半是信息没填全或格式不对（邮编、电话等），在结账页停了 ' . $dwell . $then );
			case 'address':
				return array( 'address', '地址都填完了，但没点下单，在结账页停了 ' . $dwell . '。可能是在看运费 / 总价，或者在犹豫付款方式' . $then );
		}
		if ( $r['dwell_ms'] < 20000 ) {
			return array( 'bounce', '打开结账页' . $dwell . '就走了，什么都没填。多半只是想看运费 / 总价' . $then );
		}
		return array( 'browse', '在结账页停了 ' . $dwell . '，但地址没填完就走了' . $then );
	}

	public const REASONS = array(
		'bounce'  => '打开结账页很快就走了',
		'browse'  => '停了一会儿但没填完地址',
		'address' => '填完地址没点下单',
		'submit'  => '点了下单没提交成功',
		'order'   => '下了单没付款',
		'failed'  => '付款失败',
		'paid'    => '已付款',
		'test'    => '测试单',
	);

	private static function summary( array $rows ): array {
		$out = array_fill_keys( array_keys( self::REASONS ), 0 );
		$dw  = array();
		foreach ( $rows as $r ) {
			++$out[ $r['reason_key'] ];
			if ( 'paid' !== $r['step'] && empty( $r['is_test'] ) ) {
				$dw[] = (int) $r['dwell_ms'];
			}
		}
		sort( $dw );
		return array(
			'reasons'      => $out,
			'median_dwell' => $dw ? $dw[ (int) floor( ( count( $dw ) - 1 ) / 2 ) ] : 0,
			'total'        => count( $rows ),
		);
	}

	private static function source( array $visit, array $query ): string {
		$src = trim( (string) ( $visit['utm_source'] ?? '' ) . ' / ' . (string) ( $visit['utm_medium'] ?? '' ), ' /' );
		if ( '' !== $src ) {
			return $src;
		}
		if ( isset( $query['fbclid'] ) ) {
			return 'Facebook';
		}
		$ref = (string) ( $visit['referrer_host'] ?? '' );
		return '' !== $ref ? $ref : '直接访问';
	}

	public static function duration( int $ms ): string {
		$s = (int) round( $ms / 1000 );
		if ( $s < 1 ) {
			return '不到 1 秒';
		}
		if ( $s >= 3600 ) {
			return floor( $s / 3600 ) . ' 小时 ' . floor( ( $s % 3600 ) / 60 ) . ' 分';
		}
		return $s >= 60 ? floor( $s / 60 ) . ' 分 ' . ( $s % 60 ) . ' 秒' : $s . ' 秒';
	}

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( \PeptideCore\Data\Metrics_Overlay::class ) ? \PeptideCore\Data\Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}
}
