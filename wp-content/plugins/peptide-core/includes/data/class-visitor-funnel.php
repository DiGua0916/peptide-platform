<?php
/**
 * Observed eight-step visitor funnel. Legacy order-based reports remain separate.
 * Cohorts select visits, then use their accumulated progress; historical ranges
 * are not as-of snapshots. Middle steps are observations, not cumulative stages.
 * Native orders are classified once per reader, shared by comparison periods.
 *
 * @package PeptideCore
 */
namespace PeptideCore\Data;

use PeptideCore\Overview;
use PeptideCore\Store_Config;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Visitor_Funnel {

	public const KEYS = array( 'visitors', 'viewed', 'carted', 'checkout', 'address', 'shipping', 'payment', 'paid' );
	private const CLIENT_STEPS = array( 'checkout_address_completed' => 'address', 'checkout_shipping_selected' => 'shipping', 'checkout_payment_selected' => 'payment' );
	private array $orders = array();

	public static function zero(): array {
		return array_fill_keys( self::KEYS, 0 );
	}

	public static function layers( array $counts ): array {
		$first = (int) ( $counts['visitors'] ?? 0 );
		$previous = null;
		$out = array();
		foreach ( self::KEYS as $key ) {
			$count = (int) ( $counts[ $key ] ?? 0 );
			$out[] = array( 'key' => $key, 'count' => $count, 'step_rate' => $previous ? round( $count / $previous * 100, 2 ) : null, 'visitor_rate' => $first ? round( $count / $first * 100, 2 ) : null );
			$previous = $count;
		}
		return $out;
	}

	public static function compare( array $current, array $previous ): array {
		$change = array();
		foreach ( self::KEYS as $i => $key ) {
			$change[ $key ] = Range::change( $current['layers'][ $i ]['count'], $previous['layers'][ $i ]['count'] );
		}
		return array( 'unit' => 'visitors', 'current' => $current['layers'], 'previous' => $previous['layers'], 'change' => $change );
	}

	/** [from,to) integer milliseconds; live additionally bounds observation time. */
	public function period( int $from, int $to, string $basis = 'start_ms', string $group = '', ?int $now = null, ?Realtime_Filter $filter = null, ?array $group_map = null ): array {
		global $wpdb;
		if ( ! in_array( $basis, array( 'start_ms', 'last_ms' ), true ) || ! in_array( $group, array( '', 'device', 'browser' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid internal visitor funnel cohort.' );
		}
		if ( null !== $group_map && '' !== $group ) { throw new \InvalidArgumentException( 'Mapped and legacy groups are separate.' ); }
		$counts = self::zero();
		$returning = self::zero();
		$groups = array();
		if ( ! Schema::installed() ) { return $this->result( $counts, $returning, $groups ); }
		$visits = Schema::table( 'visits' );
		$visitors = Schema::table( 'visitors' );
		$events = Schema::table( 'events' );
		$gap = max( 1, (int) Store_Config::get( 'returning_hours' ) ) * HOUR_IN_SECONDS * 1000;
		$off = wp_timezone()->getOffset( new \DateTimeImmutable() ) * 1000;
		// Match Operations' documented returning-day/offset convention exactly.
		$ret = $wpdb->prepare( 'v.id IS NOT NULL AND s.start_ms >= ( FLOOR( ( v.first_ms + %d ) / 86400000 ) + 1 ) * 86400000 - %d + %d', $off, $off, $gap );
		$cohort = $wpdb->prepare( "s.{$basis} >= %d AND s.{$basis} < %d", $from, $to );
		if ( $filter ) { $filter->require_ready(); $cohort .= ' AND ' . $filter->visit_sql( 's' ); }
		// M6（FE-05 / NF-02）：开关开着时按 Data\Metrics 的漏斗规则算——不算测试版留下的访问；“支付成功”必须是同一次访问里填过地址的，
		// 付了款的人四层都算，所以每一步都不会比上一步多。关着和影子时下面的统计和以前完全一样，影子只是顺手按新规则再数一遍、记对账。
		$mode = self::mode(); $strict = 'on' === $mode;
		if ( $strict ) { $cohort .= ' AND s.is_test = 0'; }
		$select = array();
		foreach ( array( 'visitors' => 0, 'viewed' => 1, 'carted' => 2, 'checkout' => 3 ) as $key => $stage ) {
			$select[] = "COUNT(DISTINCT CASE WHEN s.stage >= {$stage} THEN s.visitor_id END) AS {$key}";
			$select[] = "COUNT(DISTINCT CASE WHEN s.stage >= {$stage} AND ({$ret}) THEN s.visitor_id END) AS r_{$key}";
		}
		$columns = implode( ',', $select );
		$row = $wpdb->get_row( "SELECT {$columns} FROM {$visits} s LEFT JOIN {$visitors} v ON v.id=s.visitor_id WHERE {$cohort}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $filter && $wpdb->last_error ) { throw new \RuntimeException( 'Filtered visitor funnel unavailable.' ); }
		foreach ( array_slice( self::KEYS, 0, 4 ) as $key ) {
			$counts[ $key ] = (int) ( $row[ $key ] ?? 0 );
			$returning[ $key ] = (int) ( $row[ 'r_' . $key ] ?? 0 );
		}
		$g = 'browser' === $group ? "COALESCE(v.browser,'')" : 's.device';
		if ( $group ) {
			$rows = $wpdb->get_results( "SELECT {$g} AS g,{$columns} FROM {$visits} s LEFT JOIN {$visitors} v ON v.id=s.visitor_id WHERE {$cohort} GROUP BY {$g}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $filter && $wpdb->last_error ) { throw new \RuntimeException( 'Filtered visitor funnel unavailable.' ); }
			foreach ( (array) $rows as $row ) {
				$key = '' === $row['g'] ? 'unknown' : (string) $row['g'];
				$groups[ $key ] = self::zero();
				foreach ( array_slice( self::KEYS, 0, 4 ) as $step ) { $groups[ $key ][ $step ] = (int) $row[ $step ]; }
			}
		}
		if ( null !== $group_map ) {
			$cursor = 0; $mapped = array();
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.id,s.visitor_id,s.stage FROM {$visits} s WHERE {$cohort} AND s.id > %d ORDER BY s.id LIMIT 500", $cursor ), ARRAY_A );
				if ( $wpdb->last_error ) { throw new \RuntimeException( 'Mapped visitor funnel unavailable.' ); }
				foreach ( (array) $rows as $row ) {
					$cursor = (int) $row['id']; $visitor = (int) $row['visitor_id']; $entry = $group_map[$cursor] ?? null;
					if ( ! $entry || $visitor <= 0 || $entry['visitor_id'] !== $visitor ) { continue; }
					$key = $entry['key'];
					foreach ( array( 'visitors' => 0, 'viewed' => 1, 'carted' => 2, 'checkout' => 3 ) as $step => $stage ) { if ( (int) $row['stage'] >= $stage ) { $mapped[$key][$step][$visitor] = true; } }
				}
			} while ( count( (array) $rows ) === 500 );
			foreach ( $mapped as $key => $steps ) { $groups[$key] = self::zero(); foreach ( array_slice( self::KEYS, 0, 4 ) as $step ) { $groups[$key][$step] = count( $steps[$step] ?? array() ); } }
		}
		$seen = array();
		$seen_returning = array();
		$seen_groups = array();
		// 结账页一打开就会带默认的配送 / 支付选择（origin=initial），不是顾客的动作。
		// “添加物流 / 添加支付”只在同一次访问里已经填好地址时才算，所以先记下来，整段事件读完再结算。
		$address_visits = array();
		$held = array();
		$address_raw = array(); // 这次访问里出现过填地址事件（不管访问后来挂的是不是测试单）。
		$legacy_paid = array(); // 旧规则下算“支付成功”的访客。
		$paid_held = array();   // 新规则下的付款：访问编号 => 访客 => [ 是不是回访, 分组 ]，整段事件读完再结算。
		$cursor = 0;
		$time = null === $now ? '' : $wpdb->prepare( ' AND e.occurred_ms <= %d', $now );
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.id,e.type,e.source,e.visit_id,e.visitor_id,e.order_id,s.order_id AS linked_order,{$g} AS g,({$ret}) AS returning_visit FROM {$events} e INNER JOIN {$visits} s ON s.id=e.visit_id AND s.visitor_id=e.visitor_id INNER JOIN {$visitors} v ON v.id=s.visitor_id WHERE {$cohort} AND e.id > %d AND ((e.source='client' AND e.type IN ('checkout_address_completed','checkout_shipping_selected','checkout_payment_selected')) OR (e.source='server' AND e.type='payment_success')) {$time} ORDER BY e.id LIMIT 500", $cursor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $filter && $wpdb->last_error ) { throw new \RuntimeException( 'Filtered visitor funnel unavailable.' ); }
			$need = array();
			foreach ( (array) $rows as $row ) {
				$id = (int) ( $row['order_id'] ?: ( 'client' === $row['source'] ? $row['linked_order'] : 0 ) );
				if ( $id > 0 && ! array_key_exists( $id, $this->orders ) ) { $need[ $id ] = $id; }
			}
			$this->load_orders( $need );
			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row['id'];
				$id = (int) ( $row['order_id'] ?: ( 'client' === $row['source'] ? $row['linked_order'] : 0 ) );
				$order = $this->orders[ $id ] ?? null;
				if ( 'off' !== $mode && 'checkout_address_completed' === $row['type'] ) { $address_raw[ (int) $row['visit_id'] ] = true; }
				if ( $id && ( ! $order || $order['test'] ) ) { continue; }
				if ( 'server' === $row['source'] ) {
					if ( ! $id || ! $order['paid'] || $order['visitor'] !== (int) $row['visitor_id'] || $order['visit'] !== (int) $row['visit_id'] ) { continue; }
					$step = 'paid';
				} else { $step = self::CLIENT_STEPS[ $row['type'] ]; }
				$visitor = (int) $row['visitor_id'];
				if ( 'paid' === $step && 'off' !== $mode ) {
					$legacy_paid[ $visitor ] = true;
					if ( $order['counted'] ) { $paid_held[ (int) $row['visit_id'] ][ $visitor ] = array( (bool) $row['returning_visit'], $group ? ( '' === $row['g'] ? 'unknown' : (string) $row['g'] ) : null ); }
					if ( $strict ) { continue; }
				}
				if ( 'address' === $step ) { $address_visits[ (int) $row['visit_id'] ] = true; }
				if ( in_array( $step, array( 'shipping', 'payment' ), true ) ) {
					$held[ (int) $row['visit_id'] ][ $step ][ $visitor ] = array( (bool) $row['returning_visit'], $group ? ( '' === $row['g'] ? 'unknown' : (string) $row['g'] ) : null );
					continue;
				}
				$seen[ $step ][ $visitor ] = true;
				if ( $row['returning_visit'] ) { $seen_returning[ $step ][ $visitor ] = true; }
				if ( $group ) {
					$key = '' === $row['g'] ? 'unknown' : (string) $row['g'];
					$seen_groups[ $key ][ $step ][ $visitor ] = true;
				}
				if ( null !== $group_map ) {
					$entry = $group_map[(int) $row['visit_id']] ?? null;
					if ( $entry && $visitor > 0 && $entry['visitor_id'] === $visitor ) { $seen_groups[$entry['key']][$step][$visitor] = true; }
				}
			}
		} while ( count( (array) $rows ) === 500 );
		foreach ( $held as $visit_id => $steps ) {
			if ( empty( $address_visits[ $visit_id ] ) ) { continue; } // 没填地址：默认选中的配送 / 支付不算步骤。
			foreach ( array( 'shipping', 'payment' ) as $step ) {
				if ( 'payment' === $step && empty( $steps['shipping'] ) ) { break; }
				foreach ( (array) ( $steps[ $step ] ?? array() ) as $visitor => [ $is_returning, $gkey ] ) {
					$seen[ $step ][ $visitor ] = true;
					if ( $is_returning ) { $seen_returning[ $step ][ $visitor ] = true; }
					if ( null !== $gkey ) { $seen_groups[ $gkey ][ $step ][ $visitor ] = true; }
					if ( null !== $group_map ) {
						$entry = $group_map[ $visit_id ] ?? null;
						if ( $entry && $visitor > 0 && $entry['visitor_id'] === $visitor ) { $seen_groups[ $entry['key'] ][ $step ][ $visitor ] = true; }
					}
				}
			}
		}
		if ( 'off' !== $mode ) {
			$tail = array_slice( self::KEYS, 4 ); $legacy = array(); $probe = array();
			foreach ( $tail as $step ) { $legacy[ $step ] = count( 'paid' === $step ? $legacy_paid : ( $seen[ $step ] ?? array() ) ); $probe[ $step ] = 'paid' === $step ? array() : ( $seen[ $step ] ?? array() ); }
			foreach ( $paid_held as $visit_id => $buyers ) {
				if ( empty( $address_raw[ $visit_id ] ) ) { continue; } // 同一次访问里没有填地址的付款：不进后四层（FE-05）。
				foreach ( $buyers as $visitor => [ $is_returning, $gkey ] ) {
					foreach ( $tail as $step ) {
						$probe[ $step ][ $visitor ] = true;
						if ( ! $strict ) { continue; }
						$seen[ $step ][ $visitor ] = true;
						if ( $is_returning ) { $seen_returning[ $step ][ $visitor ] = true; }
						if ( null !== $gkey ) { $seen_groups[ $gkey ][ $step ][ $visitor ] = true; }
						if ( null !== $group_map ) {
							$entry = $group_map[ $visit_id ] ?? null;
							if ( $entry && $visitor > 0 && $entry['visitor_id'] === $visitor ) { $seen_groups[ $entry['key'] ][ $step ][ $visitor ] = true; }
						}
					}
				}
			}
			// 对账（reports.visitor_funnel）：后四层在旧规则和新规则下的人数。实时窗口（last_ms）每次请求都在动，日志里不带起止时间。
			$context = array( 'basis' => $basis, 'scope' => $filter ? 'filtered' : 'all' );
			if ( 'start_ms' === $basis ) { $context += array( 'from' => wp_date( 'Y-m-d H:i', intdiv( $from, 1000 ) ), 'to' => wp_date( 'Y-m-d H:i', intdiv( $to - 1, 1000 ) ) ); }
			Metrics_Switch::report( 'reports.visitor_funnel', Metrics_Switch::diff( $legacy, array_map( 'count', $probe ) ), $context );
		}
		foreach ( array_slice( self::KEYS, 4 ) as $step ) {
			$counts[ $step ] = count( $seen[ $step ] ?? array() );
			$returning[ $step ] = count( $seen_returning[ $step ] ?? array() );
			foreach ( $groups as $key => $unused ) { $groups[ $key ][ $step ] = count( $seen_groups[ $key ][ $step ] ?? array() ); }
		}
		return $this->result( $counts, $returning, $groups );
	}

	private function load_orders( array $need ): void {
		if ( ! $need ) { return; }
		foreach ( $need as $id ) { $this->orders[ $id ] = null; }
		foreach ( wc_get_orders( array( 'post__in' => array_values( $need ), 'limit' => count( $need ), 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
			$id = $order->get_id();
			if ( ! isset( $need[ $id ] ) ) { continue; }
			$paid = $order->is_paid() || null !== $order->get_date_paid();
			// counted：Data\Metrics 的“已付款”（已付款状态，或已退款但付过款）；比旧规则少的是“付过款、后来取消 / 失败”的订单。开关关着时不用它。
			$this->orders[ $id ] = array( 'test' => self::is_test( $order ), 'paid' => $paid, 'counted' => 'off' === self::mode() ? $paid : Metrics::is_paid_order( $order ), 'visitor' => (int) $order->get_meta( Server_Events::ORDER_VISITOR_META ), 'visit' => (int) $order->get_meta( Server_Events::ORDER_VISIT_META ) );
		}
	}

	private function result( array $counts, array $returning, array $groups ): array {
		$wrap = static fn( array $values ) => array( 'unit' => 'visitors', 'layers' => self::layers( $values ) );
		return array( 'overall' => $wrap( $counts ), 'returning' => $wrap( $returning ), 'groups' => array_map( $wrap, $groups ) );
	}

	/** M6 的开关档位；不带自动加载的纯逻辑测试里没有 Metrics_Switch，一律当作关着。 */
	private static function mode(): string {
		return class_exists( Metrics_Switch::class ) ? Metrics_Switch::mode() : 'off';
	}

	/** M6（NF-02）：测试单规则按开关走 Metrics_Overlay::is_test_order()（关着时就是 Overview 的旧规则）；不带自动加载的纯逻辑测试里没有这个类，直接用旧规则。 */
	private static function is_test( $order ): bool {
		return class_exists( Metrics_Overlay::class ) ? Metrics_Overlay::is_test_order( $order ) : \PeptideCore\Overview::is_test_order( $order );
	}
}
