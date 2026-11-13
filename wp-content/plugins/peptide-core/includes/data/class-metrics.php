<?php
/**
 * 统一指标入口（M6，NF-02）。店铺后台、投手首页、订单上报和总后台报表都从这里取数，口径见 docs/metrics.md
 * （和开发文档第 6.1 节一致）。签名写在 docs/CONTRACT.md 的「M6 / M7」一节，改签名前先改那里。
 *
 * 约定：
 * - 时间段一律是 [from, to)，Unix 秒；店铺时区的换算由调用方用 Data\Range 或 Metrics::window() 做好。
 * - 金额一律是美分整数（*_cents），币种是订单币种；比率是百分数，保留 2 位，分母为 0 时是 null。
 * - 访问按 start_ms 落在时间段内算（和现有报表相同的“访问批次”口径）；订单按付款时间算。
 *
 * 七个指标：
 * - 访客        去重的 pc_track_visitors，只算 is_test = 0；爬虫在写入时就被 Tracker::is_automated() 挡掉，不会产生访客行。
 * - 转化率      支付成功访客 ÷ 访客。支付成功访客 = 访问走到已付款（stage ≥ 5）、同一次访问里有填地址的记录、订单是已付款的非测试单。
 * - 订单        收到过支付成功通知（有付款时间、状态是已付款或已退款）的订单，去掉测试单（_pc_test_order / 收件信息全 1 / 测试付款方式 / 演示商品）。
 * - 销售额      Σ 订单金额 − Σ _pc_refund_cents（总后台标记的退款）。
 * - 客单价      销售额 ÷ 订单数。
 * - 弃单数      Recovery_Items 去重后的弃单（一位顾客一条），不含已作废和测试单。
 * - 召回激活率  召回成功 ÷ 发过召回邮件的弃单。
 *
 * 分成两层：纯计算（cents / rate / sum_orders / funnel_counts / abandoned_from_items / layers，不碰数据库，
 * tests/m6-metrics-pure.php 直接测）和读数（order_facts / visitors / funnel / abandoned / summary / daily）。
 * 这个类只读，不写任何数据，也不看开关：要不要用它的结果由 Data\Metrics_Switch 决定。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

use PeptideCore\Overview;
use PeptideCore\Recovery_Items;
use PeptideCore\Test_Orders;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Metrics {

	/** 口径版本。口径变了就加 1，并在 docs/metrics.md 写明变了什么。 */
	public const VERSION = 1;

	/** 总后台回写的已标记退款（美分，OR-03）。 */
	public const REFUND_META = '_pc_refund_cents';

	/** 八层访客漏斗的 key，顺序固定（和 Visitor_Funnel::KEYS 相同）。 */
	public const FUNNEL_KEYS = array( 'visitors', 'viewed', 'carted', 'checkout', 'address', 'shipping', 'payment', 'paid' );

	/** 漏斗里靠浏览器事件判断的三步：事件类型 => 步骤。 */
	public const STEP_EVENTS = array(
		'checkout_address_completed' => 'address',
		'checkout_shipping_selected' => 'shipping',
		'checkout_payment_selected'  => 'payment',
	);

	private const STAGE_CHECKOUT = 3;
	private const STAGE_PAID     = 5;
	private const ABANDONED_TTL  = 300;

	/** 同一个请求里重复取同一个时间段时不再查库。 */
	private static array $cache = array();

	/** 清掉请求内缓存（测试用）。 */
	public static function flush(): void {
		self::$cache = array();
	}

	// ---------- 纯计算 ----------

	/** 金额（元，字符串或小数）→ 美分整数。 */
	public static function cents( $amount ): int {
		return (int) round( (float) $amount * 100 );
	}

	/** 美分 → 元（保留 2 位），给还在用小数的旧页面。 */
	public static function money( int $cents ): float {
		return round( $cents / 100, 2 );
	}

	/** 百分比，保留 2 位；分母不是正数时返回 null。 */
	public static function rate( int $part, int $whole ): ?float {
		return $whole > 0 ? round( $part / $whole * 100, 2 ) : null;
	}

	/**
	 * 把一批订单事实加总。只算 paid 的；test 的只计数不计金额。
	 *
	 * @param array<int,array<string,mixed>> $facts Metrics::order_fact() 的结果。
	 * @return array{orders:int,gross_cents:int,refund_cents:int,sales_cents:int,aov_cents:int,test_orders:int}
	 */
	public static function sum_orders( array $facts ): array {
		$orders = 0;
		$gross  = 0;
		$refund = 0;
		$tests  = 0;
		foreach ( $facts as $fact ) {
			if ( empty( $fact['paid'] ) ) {
				continue;
			}
			if ( ! empty( $fact['test'] ) ) {
				++$tests;
				continue;
			}
			++$orders;
			$gross  += (int) ( $fact['gross_cents'] ?? 0 );
			$refund += (int) ( $fact['refund_cents'] ?? 0 );
		}
		$sales = $gross - $refund;
		return array(
			'orders'       => $orders,
			'gross_cents'  => $gross,
			'refund_cents' => $refund,
			'sales_cents'  => $sales,
			'aov_cents'    => $orders > 0 ? (int) round( $sales / $orders ) : 0,
			'test_orders'  => $tests,
		);
	}

	/**
	 * 八层漏斗的人数（按不同访客计）。每一层都是上一层的子集，所以每一步不会大于上一步（FE-05）。
	 *
	 * - 访问 / 看商品 / 加购 / 发起结账：访问的 stage ≥ 0 / 1 / 2 / 3。
	 * - 填地址：发起结账的那次访问里有 checkout_address_completed。
	 * - 选物流：填了地址，并且同一次访问里有 checkout_shipping_selected；或者这次访问付了款。
	 * - 选支付：选了物流，并且同一次访问里有 checkout_payment_selected；或者这次访问付了款。
	 * - 支付成功：stage ≥ 5、填过地址、关联订单是已付款的非测试单，且订单记的访客 / 访问就是这一次。
	 * 没有填地址的记录时，后面四层都不算（结账页一打开就有默认的物流和支付，不是顾客的动作）。
	 * 访问关联的订单是测试单或已经不存在时，这次访问只算到“发起结账”。
	 *
	 * @param array<int,array<string,mixed>>                    $visits   访问编号 => visitor、stage、order（访问上最后挂的订单号）、
	 *                                                                    orders（可选：这次访问的付款事件里出现过的订单号）。
	 * @param array<int,array<string,bool>>                     $evidence 访问编号 => [ address / shipping / payment => true ]。
	 * @param array<int,array<string,mixed>>                    $orders   订单号 => [ test, counted, visitor, visit ]。
	 * @return array<string,int>
	 */
	public static function funnel_counts( array $visits, array $evidence, array $orders ): array {
		$sets = array_fill_keys( self::FUNNEL_KEYS, array() );
		foreach ( $visits as $visit_id => $visit ) {
			$visitor = (int) ( $visit['visitor'] ?? 0 );
			if ( $visitor <= 0 ) {
				continue;
			}
			$stage                        = (int) ( $visit['stage'] ?? 0 );
			$sets['visitors'][ $visitor ] = true;
			if ( $stage >= 1 ) {
				$sets['viewed'][ $visitor ] = true;
			}
			if ( $stage >= 2 ) {
				$sets['carted'][ $visitor ] = true;
			}
			if ( $stage < self::STAGE_CHECKOUT ) {
				continue;
			}
			$sets['checkout'][ $visitor ] = true;

			// 这次访问关联过的订单：付款事件里的订单 + 访问上最后挂的那张（同一次访问里先付了一单、又下了一张没付的，
			// 访问上只留后一张的订单号，所以不能只看它）。
			$order_ids = isset( $visit['orders'] ) ? array_map( 'intval', (array) $visit['orders'] ) : array();
			if ( (int) ( $visit['order'] ?? 0 ) > 0 ) {
				$order_ids[] = (int) $visit['order'];
			}
			$real = false; // 有没有一张不是测试单、也还存在的订单。
			$paid = false; // 有没有一张算数的已付款订单，而且订单上记的访客 / 访问就是这一次。
			foreach ( array_unique( $order_ids ) as $order_id ) {
				$order = $orders[ $order_id ] ?? null;
				if ( null === $order || ! empty( $order['test'] ) ) {
					continue;
				}
				$real = true;
				if ( $stage >= self::STAGE_PAID && ! empty( $order['counted'] ) && (int) ( $order['visit'] ?? 0 ) === (int) $visit_id && (int) ( $order['visitor'] ?? 0 ) === $visitor ) {
					$paid = true;
				}
			}
			if ( $order_ids && ! $real ) {
				continue; // 关联的订单全是测试单，或都已经不存在：只算到“发起结账”。
			}
			foreach ( self::tail_steps( $evidence[ (int) $visit_id ] ?? array(), $paid ) as $step ) {
				$sets[ $step ][ $visitor ] = true;
			}
		}
		return array_map( 'count', $sets );
	}

	/**
	 * 一次访问在漏斗后四层走到了哪几步（FE-05：每一步都要有上一步）。带分组的漏斗报表（Visitor_Funnel 之外自己数事件的那几张）
	 * 开关开着时用同一条规则。
	 *
	 * - 没有填地址的记录：一步都不算（哪怕付了款）。
	 * - 填了地址：算“填地址”；有选物流事件或这次访问付了款：算“选物流”；在此基础上有选支付事件或付了款：算“选支付”；付了款：算“支付成功”。
	 *
	 * @param array<string,bool> $evidence 这次访问里有没有 address / shipping / payment 事件。
	 * @param bool               $paid     这次访问有没有算数的已付款订单。
	 * @return string[] address / shipping / payment / paid 里走到的那几步，按顺序。
	 */
	public static function tail_steps( array $evidence, bool $paid ): array {
		if ( empty( $evidence['address'] ) ) {
			return array();
		}
		$out = array( 'address' );
		if ( ! $paid && empty( $evidence['shipping'] ) ) {
			return $out;
		}
		$out[] = 'shipping';
		if ( ! $paid && empty( $evidence['payment'] ) ) {
			return $out;
		}
		$out[] = 'payment';
		if ( $paid ) {
			$out[] = 'paid';
		}
		return $out;
	}

	/**
	 * 人数 → 页面用的层（和 Visitor_Funnel::layers() 同一个结构）。
	 *
	 * @return array<int,array{key:string,count:int,step_rate:?float,visitor_rate:?float}>
	 */
	public static function layers( array $counts ): array {
		$first    = (int) ( $counts['visitors'] ?? 0 );
		$previous = null;
		$out      = array();
		foreach ( self::FUNNEL_KEYS as $key ) {
			$count    = (int) ( $counts[ $key ] ?? 0 );
			$out[]    = array(
				'key'          => $key,
				'count'        => $count,
				'step_rate'    => $previous ? round( $count / $previous * 100, 2 ) : null,
				'visitor_rate' => $first ? round( $count / $first * 100, 2 ) : null,
			);
			$previous = $count;
		}
		return $out;
	}

	/**
	 * 弃单数和召回激活率，从 Recovery_Items::list() 的行算（一位顾客一条）。只算创建时间在 [from_ms, to_ms) 内、没有作废的。
	 *
	 * - count     弃单数：待召回 + 召回中 + 召回失败 + 召回成功；
	 * - open      还要跟进的：待召回 + 召回中；
	 * - emailed   发过召回邮件的：召回中 + 召回失败 + 召回成功；
	 * - recovered 召回成功；rate = recovered ÷ emailed。
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array{count:int,open:int,failed:int,emailed:int,recovered:int,recovered_cents:int,rate:?float}
	 */
	public static function abandoned_from_items( array $items, int $from_ms, int $to_ms ): array {
		$out = array( 'count' => 0, 'open' => 0, 'failed' => 0, 'emailed' => 0, 'recovered' => 0, 'recovered_cents' => 0, 'rate' => null );
		foreach ( $items as $item ) {
			$created = (int) ( $item['created_ms'] ?? 0 );
			$status  = (string) ( $item['status'] ?? '' );
			if ( $created < $from_ms || $created >= $to_ms || ! in_array( $status, array( 'pending', 'recalling', 'failed', 'success' ), true ) ) {
				continue;
			}
			++$out['count'];
			if ( 'pending' === $status ) {
				++$out['open'];
				continue;
			}
			++$out['emailed'];
			if ( 'recalling' === $status ) {
				++$out['open'];
			} elseif ( 'failed' === $status ) {
				++$out['failed'];
			} else {
				++$out['recovered'];
				$later                    = $item['paid_later'] ?? null;
				$out['recovered_cents'] += self::cents( is_object( $later ) && method_exists( $later, 'get_total' ) ? $later->get_total() : ( $item['total'] ?? 0 ) );
			}
		}
		$out['rate'] = self::rate( $out['recovered'], $out['emailed'] );
		return $out;
	}

	// ---------- 订单 ----------

	/** 测试单：收件信息全 1（Test_Orders），或者测试付款方式 / 演示商品（Overview 的旧规则）。 */
	public static function is_test_order( \WC_Order $order ): bool {
		if ( class_exists( Test_Orders::class ) && Test_Orders::is_test_order( $order ) ) {
			return true;
		}
		return Overview::is_test_order( $order );
	}

	/** 收到过支付成功通知：状态是已付款；或者已退款但有付款时间。待付款、失败、取消、保留都不算。 */
	public static function is_paid_order( \WC_Order $order ): bool {
		$status = (string) $order->get_status();
		if ( in_array( $status, wc_get_is_paid_statuses(), true ) ) {
			return true;
		}
		return 'refunded' === $status && null !== $order->get_date_paid();
	}

	/** 付款时间（Unix 秒）；没有记录时退回创建时间。 */
	public static function paid_at( \WC_Order $order ): int {
		$paid = $order->get_date_paid();
		if ( $paid ) {
			return (int) $paid->getTimestamp();
		}
		$created = $order->get_date_created();
		return $created ? (int) $created->getTimestamp() : 0;
	}

	/**
	 * 一张订单在指标里的样子。F3 的订单上报（core/class-order-report.php）用同一份事实，店铺和总后台的数字才对得上。
	 *
	 * @return array{id:int,status:string,paid:bool,test:bool,counted:bool,paid_at:int,created_at:int,gross_cents:int,refund_cents:int,net_cents:int,wc_refund_cents:int,currency:string,visitor:int,visit:int,buyer:string}
	 */
	public static function order_fact( \WC_Order $order ): array {
		$gross    = max( 0, self::cents( $order->get_total() ) );
		$refund   = min( $gross, max( 0, (int) $order->get_meta( self::REFUND_META ) ) );
		$paid     = self::is_paid_order( $order );
		$test     = self::is_test_order( $order );
		$created  = $order->get_date_created();
		$customer = (int) $order->get_customer_id();
		$email    = strtolower( trim( (string) $order->get_billing_email() ) );
		return array(
			'id'              => (int) $order->get_id(),
			'status'          => (string) $order->get_status(),
			'paid'            => $paid,
			'test'            => $test,
			'counted'         => $paid && ! $test,
			'paid_at'         => $paid ? self::paid_at( $order ) : 0,
			'created_at'      => $created ? (int) $created->getTimestamp() : 0,
			'gross_cents'     => $gross,
			'refund_cents'    => $refund,
			'net_cents'       => $gross - $refund,
			// 只用来解释新旧差异：WooCommerce 自己记的退款。系统内不做退款（Q16），正常应该是 0。
			'wc_refund_cents' => max( 0, self::cents( $order->get_total_refunded() ) ),
			'currency'        => (string) $order->get_currency(),
			'visitor'         => (int) $order->get_meta( Server_Events::ORDER_VISITOR_META ),
			'visit'           => (int) $order->get_meta( Server_Events::ORDER_VISIT_META ),
			'buyer'           => $customer > 0 ? 'u' . $customer : ( '' !== $email ? 'e' . $email : '' ),
		);
	}

	/**
	 * 付款时间在 [from, to) 内的已付款订单的事实（含测试单，test = true，由 sum_orders() 排除）。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function order_facts( int $from, int $to ): array {
		$key = 'orders|' . $from . '|' . $to;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$facts   = array();
		$objects = array();
		if ( $to > $from && function_exists( 'wc_get_orders' ) ) {
			$statuses = array_map( static fn( $s ) => 'wc-' . $s, array_values( array_unique( array_merge( wc_get_is_paid_statuses(), array( 'refunded' ) ) ) ) );
			$seen     = array();
			$orders   = wc_get_orders(
				array(
					'type'      => 'shop_order',
					'status'    => $statuses,
					'date_paid' => $from . '...' . ( $to - 1 ),
					'limit'     => -1,
					'return'    => 'objects',
				)
			);
			foreach ( is_array( $orders ) ? $orders : array() as $order ) {
				if ( ! $order instanceof \WC_Order || isset( $seen[ $order->get_id() ] ) ) {
					continue;
				}
				$seen[ $order->get_id() ] = true;
				$fact                     = self::order_fact( $order );
				if ( $fact['paid'] && $fact['paid_at'] >= $from && $fact['paid_at'] < $to ) {
					$facts[]   = $fact;
					$objects[] = $order;
				}
			}
			// 状态是已付款但没有付款时间的订单（导入的、手工改状态的）：按创建时间归属。
			$ids = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => $statuses,
					'date_created' => $from . '...' . ( $to - 1 ),
					'limit'        => -1,
					'return'       => 'ids',
				)
			);
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				$id = $id instanceof \WC_Order ? (int) $id->get_id() : (int) $id; // 有的环境不认 return => ids，给回来的是订单对象。
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order || null !== $order->get_date_paid() ) {
					continue; // 有付款时间的已经按付款时间算过（或者不在这个时间段付的款）。
				}
				$fact = self::order_fact( $order );
				if ( $fact['paid'] ) {
					$facts[]   = $fact;
					$objects[] = $order;
				}
			}
		}
		self::$cache[ $key ]                               = $facts;
		self::$cache[ 'objects|' . $from . '|' . $to ] = $objects;
		return $facts;
	}

	/**
	 * 和 order_facts() 同一批订单，但返回订单对象（含测试单，由调用方排除）。还在按订单对象算的旧报表在开关开着时用这一份，
	 * 这样它们和上面的合计是同一批订单（按付款时间归属）。
	 *
	 * @return \WC_Order[]
	 */
	public static function paid_orders( int $from, int $to ): array {
		self::order_facts( $from, $to );
		return self::$cache[ 'objects|' . $from . '|' . $to ] ?? array();
	}

	/**
	 * 时间段内的订单数、销售额、客单价。
	 *
	 * @return array{orders:int,gross_cents:int,refund_cents:int,sales_cents:int,aov_cents:int,test_orders:int}
	 */
	public static function orders( int $from, int $to ): array {
		return self::sum_orders( self::order_facts( $from, $to ) );
	}

	// ---------- 访客和漏斗 ----------

	/** 访客数：时间段内有访问的不同访客（is_test = 0）。追踪表没装时是 0。 */
	public static function visitors( int $from, int $to ): int {
		global $wpdb;
		if ( $to <= $from || ! Schema::installed() ) {
			return 0;
		}
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT s.visitor_id) FROM {$visits} s INNER JOIN {$visitors} v ON v.id = s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.is_test = 0 AND v.is_test = 0", $from * 1000, $to * 1000 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 八层访客漏斗。counts 是每层人数，layers 是页面用的结构。
	 *
	 * @return array{unit:string,counts:array<string,int>,layers:array}
	 */
	public static function funnel( int $from, int $to ): array {
		$key = 'funnel|' . $from . '|' . $to;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$counts = array_fill_keys( self::FUNNEL_KEYS, 0 );
		if ( $to > $from && Schema::installed() ) {
			$visits   = self::read_visits( $from * 1000, $to * 1000 );
			$evidence = $visits ? self::read_evidence( $from * 1000, $to * 1000 ) : array();
			$counts   = self::funnel_counts( $visits, $evidence, self::visit_orders( $visits ) );
		}
		self::$cache[ $key ] = array( 'unit' => 'visitors', 'counts' => $counts, 'layers' => self::layers( $counts ) );
		return self::$cache[ $key ];
	}

	/** 支付成功访客数（漏斗最后一层）。 */
	public static function paid_visitors( int $from, int $to ): int {
		return (int) self::funnel( $from, $to )['counts']['paid'];
	}

	/** 转化率 = 支付成功访客 ÷ 访客（百分数）；没有访客时是 null。 */
	public static function conversion_rate( int $from, int $to ): ?float {
		$counts = self::funnel( $from, $to )['counts'];
		return self::rate( (int) $counts['paid'], (int) $counts['visitors'] );
	}

	/** @return array<int,array{visitor:int,stage:int,order:int,orders:int[]}> */
	private static function read_visits( int $from_ms, int $to_ms ): array {
		global $wpdb;
		$visits   = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$events   = Schema::table( 'events' );
		$out      = array();
		$cursor   = 0;
		$any_paid = false;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.visitor_id, s.stage, s.order_id FROM {$visits} s INNER JOIN {$visitors} v ON v.id = s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.is_test = 0 AND v.is_test = 0 AND s.id > %d ORDER BY s.id LIMIT 1000", $from_ms, $to_ms, $cursor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = is_array( $rows ) ? $rows : array();
			foreach ( $rows as $row ) {
				$cursor         = (int) $row['id'];
				$out[ $cursor ] = array( 'visitor' => (int) $row['visitor_id'], 'stage' => (int) $row['stage'], 'order' => (int) $row['order_id'], 'orders' => array() );
				$any_paid       = $any_paid || (int) $row['stage'] >= self::STAGE_PAID;
			}
		} while ( 1000 === count( $rows ) );
		if ( $any_paid ) {
			// 走到已付款的访问：付款事件里的订单号。访问上的 order_id 只留最后挂的那一张，先付款、后又下了一张没付的单时会指向后一张。
			$paid = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT e.visit_id, e.order_id FROM {$events} e INNER JOIN {$visits} s ON s.id = e.visit_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.stage >= %d AND e.source = 'server' AND e.type = 'payment_success' AND e.order_id > 0", $from_ms, $to_ms, self::STAGE_PAID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $paid ) ? $paid : array() as $row ) {
				if ( isset( $out[ (int) $row['visit_id'] ] ) ) {
					$out[ (int) $row['visit_id'] ]['orders'][] = (int) $row['order_id'];
				}
			}
		}
		return $out;
	}

	/** 走到结账的访问里，填地址 / 选物流 / 选支付三种事件有没有出现过。 @return array<int,array<string,bool>> */
	private static function read_evidence( int $from_ms, int $to_ms ): array {
		global $wpdb;
		$visits = Schema::table( 'visits' );
		$events = Schema::table( 'events' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT e.visit_id, e.type FROM {$events} e INNER JOIN {$visits} s ON s.id = e.visit_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.stage >= %d AND e.type IN ('checkout_address_completed','checkout_shipping_selected','checkout_payment_selected')", $from_ms, $to_ms, self::STAGE_CHECKOUT ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out    = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$step = self::STEP_EVENTS[ (string) $row['type'] ] ?? '';
			if ( '' !== $step ) {
				$out[ (int) $row['visit_id'] ][ $step ] = true;
			}
		}
		return $out;
	}

	/** 访问关联的订单：是不是测试单、算不算数、订单上记的访客 / 访问。 @return array<int,array<string,mixed>> */
	private static function visit_orders( array $visits ): array {
		$need = array();
		foreach ( $visits as $visit ) {
			if ( $visit['stage'] < self::STAGE_CHECKOUT ) {
				continue;
			}
			foreach ( array_merge( array( (int) $visit['order'] ), (array) ( $visit['orders'] ?? array() ) ) as $order_id ) {
				if ( $order_id > 0 ) {
					$need[ (int) $order_id ] = (int) $order_id;
				}
			}
		}
		$out = array();
		if ( ! $need || ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		foreach ( array_chunk( array_values( $need ), 200 ) as $batch ) {
			// post__in：CPT 原生支持，HPOS 的 OrdersTableQuery 会映射成 id（和 Visitor_Funnel 同一个写法）。
			$orders = wc_get_orders( array( 'post__in' => $batch, 'limit' => count( $batch ), 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) );
			foreach ( is_array( $orders ) ? $orders : array() as $order ) {
				if ( ! $order instanceof \WC_Order || ! isset( $need[ $order->get_id() ] ) ) {
					continue;
				}
				$fact                      = self::order_fact( $order );
				$out[ (int) $fact['id'] ] = array( 'test' => $fact['test'], 'counted' => $fact['counted'], 'visitor' => $fact['visitor'], 'visit' => $fact['visit'] );
			}
		}
		return $out;
	}

	// ---------- 弃单 ----------

	/**
	 * 弃单数和召回激活率（Recovery_Items 的去重规则）。读一次要查几百张订单，所以结果缓存 5 分钟。
	 *
	 * @return array{count:int,open:int,failed:int,emailed:int,recovered:int,recovered_cents:int,rate:?float}
	 */
	public static function abandoned( int $from, int $to ): array {
		$zero = self::abandoned_from_items( array(), 0, 0 );
		if ( $to <= $from || ! class_exists( Recovery_Items::class ) ) {
			return $zero;
		}
		$name   = 'pc_m6_abandoned_' . md5( $from . '|' . $to );
		$cached = get_transient( $name );
		if ( is_array( $cached ) && isset( $cached['count'] ) ) {
			return $cached;
		}
		try {
			$list = Recovery_Items::list( array( 'from_ms' => $from * 1000, 'to_ms' => $to * 1000 ), 1, 100000 );
		} catch ( \Throwable $e ) {
			return $zero; // 弃单读不出来时不让调用它的页面跟着出错。
		}
		$out = self::abandoned_from_items( (array) ( $list['items'] ?? array() ), $from * 1000, $to * 1000 );
		set_transient( $name, $out, self::ABANDONED_TTL );
		return $out;
	}

	// ---------- 汇总 ----------

	/**
	 * 预设时间段（today / yesterday / 7d / 30d，店铺时区，和概览页同一个算法）→ [from, to)。
	 *
	 * @return array{from:int,to:int,bucket:string}
	 */
	public static function window( string $preset, ?int $now = null ): array {
		$window = Overview::window( in_array( $preset, Overview::RANGES, true ) ? $preset : 'today', $now );
		return array( 'from' => (int) $window[0], 'to' => (int) $window[1], 'bucket' => (string) $window[2] );
	}

	/**
	 * 一个时间段的全部指标。投手首页（F5）和概览页用这个。
	 *
	 * @return array<string,mixed> 见 docs/CONTRACT.md「M6 / M7」。
	 */
	public static function summary( int $from, int $to ): array {
		$orders    = self::orders( $from, $to );
		$funnel    = self::funnel( $from, $to );
		$abandoned = self::abandoned( $from, $to );
		$visitors  = (int) $funnel['counts']['visitors'];
		$paid      = (int) $funnel['counts']['paid'];
		return array(
			'version'          => self::VERSION,
			'from'             => $from,
			'to'               => $to,
			'currency'         => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'visitors'         => $visitors,
			'paid_visitors'    => $paid,
			'conversion_rate'  => self::rate( $paid, $visitors ),
			'orders'           => $orders['orders'],
			'gross_cents'      => $orders['gross_cents'],
			'refund_cents'     => $orders['refund_cents'],
			'sales_cents'      => $orders['sales_cents'],
			'aov_cents'        => $orders['aov_cents'],
			'test_orders'      => $orders['test_orders'],
			'abandoned'        => $abandoned['count'],
			'abandoned_open'   => $abandoned['open'],
			'recall_emailed'   => $abandoned['emailed'],
			'recall_recovered' => $abandoned['recovered'],
			'recall_rate'      => $abandoned['rate'],
			'recovered_cents'  => $abandoned['recovered_cents'],
			'funnel'           => $funnel['layers'],
		);
	}

	/** 预设时间段的全部指标：summary( window( $preset ) )，多带一个 preset。 */
	public static function preset( string $preset, ?int $now = null ): array {
		$window = self::window( $preset, $now );
		return array( 'preset' => in_array( $preset, Overview::RANGES, true ) ? $preset : 'today' ) + self::summary( $window['from'], $window['to'] );
	}

	/**
	 * 按店铺时区的自然日拆开：每天一行（访客、支付成功访客、转化率、订单、销售额）。总后台的每日汇总（F3）用这个，不含弃单。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function daily( int $from, int $to ): array {
		$rows = array();
		if ( $to <= $from ) {
			return $rows;
		}
		$by_day = array();
		foreach ( self::order_facts( $from, $to ) as $fact ) {
			$by_day[ wp_date( 'Y-m-d', (int) $fact['paid_at'] ) ][] = $fact;
		}
		$cursor = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
		for ( $i = 0; $i < 400 && $cursor->getTimestamp() < $to; $i++ ) {
			$next   = $cursor->modify( '+1 day' );
			$day    = $cursor->format( 'Y-m-d' );
			$sum    = self::sum_orders( $by_day[ $day ] ?? array() );
			$counts = self::funnel( max( $from, $cursor->getTimestamp() ), min( $to, $next->getTimestamp() ) )['counts'];
			$rows[] = array(
				'date'            => $day,
				'visitors'        => (int) $counts['visitors'],
				'paid_visitors'   => (int) $counts['paid'],
				'conversion_rate' => self::rate( (int) $counts['paid'], (int) $counts['visitors'] ),
				'orders'          => $sum['orders'],
				'gross_cents'     => $sum['gross_cents'],
				'refund_cents'    => $sum['refund_cents'],
				'sales_cents'     => $sum['sales_cents'],
				'aov_cents'       => $sum['aov_cents'],
			);
			$cursor = $next;
		}
		return $rows;
	}
}
