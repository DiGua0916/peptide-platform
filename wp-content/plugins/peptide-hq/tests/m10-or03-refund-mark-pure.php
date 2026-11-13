<?php
/**
 * M10（HQ-13、OR-03）纯逻辑测试：订单上报幂等、三级报表合计相等、全额 / 部分 / 撤销三种退款、
 * 每日净额重算可重复执行、店铺连不上时的重试。内存里的假数据库和假店铺，不需要 WordPress、数据库或网络。
 * 运行：php -n -d extension=mbstring m10-or03-refund-mark-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'DAY_IN_SECONDS', 86400 );
	date_default_timezone_set( 'UTC' );
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = array() ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_code(): string { return $this->code; }
	}
	class WP_REST_Request {
		public function __construct( private array $params, private array $json ) {}
		public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
		public function get_json_params() { return $this->json; }
	}
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function get_option( string $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['options'][ $k ] = $v; return true; }
	function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function add_action( ...$a ): void { $GLOBALS['actions'][] = $a; }
	function is_admin(): bool { return false; }
	function user_can( int $user_id, string $cap ): bool { return in_array( $user_id, $GLOBALS['admins'], true ); }
	function wp_timezone(): \DateTimeZone { return new \DateTimeZone( 'UTC' ); }
	function wp_date( string $format, ?int $ts = null ): string { return gmdate( $format, $ts ?? time() ); }

	/** 只认识 M10 用到的那几条 SQL；其他 SQL 直接报错。 */
	final class Memory_Db {
		public int $insert_id = 0;
		public array $tables  = array( 'wp_pchq_orders' => array(), 'wp_pchq_refunds' => array(), 'wp_pchq_daily' => array(), 'wp_pchq_ad_spend' => array() );
		public function prepare( string $sql, ...$args ): string {
			return (string) preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$args ) { $v = array_shift( $args ); return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'"; }, $sql );
		}
		private function hit( array $row, array $where ): bool {
			foreach ( $where as $k => $v ) { if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) { return false; } }
			return true;
		}
		public function insert( string $table, array $row ) {
			$row['id'] = ++$this->insert_id;
			if ( 'wp_pchq_refunds' === $table ) { $row += array( 'voided_at' => 0, 'voided_by' => 0 ); }
			$this->tables[ $table ][] = $row;
			return 1;
		}
		public function update( string $table, array $fields, array $where ) {
			$n = 0;
			foreach ( $this->tables[ $table ] as $i => $row ) { if ( $this->hit( $row, $where ) ) { $this->tables[ $table ][ $i ] = array_replace( $row, $fields ); ++$n; } }
			return $n;
		}
		public function replace( string $table, array $row ) {
			foreach ( $this->tables[ $table ] as $i => $old ) { if ( $this->hit( $old, array( 'site_id' => $row['site_id'], 'day' => $row['day'] ) ) ) { unset( $this->tables[ $table ][ $i ] ); } }
			$this->tables[ $table ][] = $row;
			return 1;
		}
		public function delete( string $table, array $where ) {
			foreach ( $this->tables[ $table ] as $i => $old ) { if ( $this->hit( $old, $where ) ) { unset( $this->tables[ $table ][ $i ] ); } }
			return 1;
		}
		public function get_row( string $sql, $format = null ): ?array {
			if ( preg_match( '/^SELECT \* FROM (\S+) WHERE site_id = (\d+) AND store_order_id = (\d+)$/', $sql, $m ) ) { $where = array( 'site_id' => $m[2], 'store_order_id' => $m[3] ); }
			elseif ( preg_match( '/^SELECT \* FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) ) { $where = array( 'id' => $m[2] ); }
			elseif ( preg_match( "/^SELECT revenue, gross_cents, refunded_cents FROM (\S+) WHERE site_id = (\d+) AND day = '([\d-]+)'$/", $sql, $m ) ) { $where = array( 'site_id' => $m[2], 'day' => $m[3] ); }
			else { throw new \RuntimeException( 'Unhandled get_row: ' . $sql ); }
			foreach ( $this->tables[ $m[1] ] as $row ) { if ( $this->hit( $row, $where ) ) { return $row; } }
			return null;
		}
		public function get_var( string $sql ) {
			if ( preg_match( '/^SELECT COALESCE\(SUM\(amount_cents\),0\) FROM (\S+) WHERE site_id = (\d+) AND store_order_id = (\d+) AND voided_at = 0$/', $sql, $m ) ) {
				$sum = 0;
				foreach ( $this->tables[ $m[1] ] as $row ) { if ( $this->hit( $row, array( 'site_id' => $m[2], 'store_order_id' => $m[3], 'voided_at' => 0 ) ) ) { $sum += $row['amount_cents']; } }
				return $sum;
			}
			if ( preg_match( '/^SELECT COUNT\(\*\) FROM (\S+) WHERE refund_sync = 1$/', $sql, $m ) ) { return count( array_filter( $this->tables[ $m[1] ], static fn( $r ) => 1 === (int) $r['refund_sync'] ) ); }
			throw new \RuntimeException( 'Unhandled get_var: ' . $sql );
		}
		public function get_col( string $sql ): array {
			if ( preg_match( '/^SELECT DISTINCT store_order_id FROM (\S+) WHERE site_id = (\d+) AND store_order_id IN \(([\d,]+)\)$/', $sql, $m ) ) {
				$in  = array_map( 'intval', explode( ',', $m[3] ) );
				$out = array();
				foreach ( $this->tables[ $m[1] ] as $row ) { if ( (int) $row['site_id'] === (int) $m[2] && in_array( (int) $row['store_order_id'], $in, true ) ) { $out[ (int) $row['store_order_id'] ] = (int) $row['store_order_id']; } }
				return array_values( $out );
			}
			throw new \RuntimeException( 'Unhandled get_col: ' . $sql );
		}
		public function get_results( string $sql, $format = null ): array {
			if ( preg_match( "/^SELECT site_id, order_day, SUM\(refunded_cents\) AS refunded FROM (\S+) WHERE refunded_cents > 0 AND paid = 1 AND order_day <> ''(?: AND site_id = (\d+))? GROUP BY site_id, order_day$/", $sql, $m ) ) {
				$out = array();
				foreach ( $this->tables[ $m[1] ] as $r ) {
					if ( $r['refunded_cents'] > 0 && 1 === (int) $r['paid'] && '' !== $r['order_day'] && ( empty( $m[2] ) || (int) $m[2] === (int) $r['site_id'] ) ) {
						$k         = $r['site_id'] . '|' . $r['order_day'];
						$out[ $k ] = array( 'site_id' => $r['site_id'], 'order_day' => $r['order_day'], 'refunded' => ( $out[ $k ]['refunded'] ?? 0 ) + $r['refunded_cents'] );
					}
				}
				return array_values( $out );
			}
			if ( preg_match( '/^SELECT site_id, day FROM (\S+) WHERE refunded_cents > 0(?: AND site_id = (\d+))?$/', $sql, $m ) ) {
				return array_values( array_filter( $this->tables[ $m[1] ], static fn( $r ) => $r['refunded_cents'] > 0 && ( empty( $m[2] ) || (int) $m[2] === (int) $r['site_id'] ) ) );
			}
			if ( preg_match( '/^SELECT site_id, COUNT\(\*\) AS orders, .+ FROM (\S+) WHERE paid = 1 AND is_test = 0(?: AND paid_at >= (\d+))?(?: AND paid_at <= (\d+))? GROUP BY site_id$/', $sql, $m ) ) {
				$out = array();
				foreach ( $this->tables[ $m[1] ] as $r ) {
					if ( 1 !== (int) $r['paid'] || 0 !== (int) $r['is_test'] || ( ! empty( $m[2] ) && $r['paid_at'] < (int) $m[2] ) || ( ! empty( $m[3] ) && $r['paid_at'] > (int) $m[3] ) ) { continue; }
					$s         = $r['site_id'];
					$out[ $s ] = array( 'site_id' => $s, 'orders' => ( $out[ $s ]['orders'] ?? 0 ) + 1, 'gross' => ( $out[ $s ]['gross'] ?? 0 ) + $r['total_cents'], 'refunded' => ( $out[ $s ]['refunded'] ?? 0 ) + $r['refunded_cents'], 'currency' => $r['currency'] );
				}
				return array_values( $out );
			}
			if ( preg_match( '/^SELECT \* FROM (\S+) WHERE site_id = (\d+) AND store_order_id = (\d+) ORDER BY id DESC$/', $sql, $m ) ) {
				return array_reverse( array_values( array_filter( $this->tables[ $m[1] ], fn( $r ) => $this->hit( $r, array( 'site_id' => $m[2], 'store_order_id' => $m[3] ) ) ) ) );
			}
			if ( preg_match( "/^SELECT site_id, SUM\(spend_cents\) AS spend FROM (\S+) WHERE day BETWEEN '([\d-]+)' AND '([\d-]+)' GROUP BY site_id$/", $sql, $m ) ) {
				$out = array();
				foreach ( $this->tables[ $m[1] ] as $r ) { if ( $r['day'] >= $m[2] && $r['day'] <= $m[3] ) { $out[ $r['site_id'] ] = array( 'site_id' => $r['site_id'], 'spend' => ( $out[ $r['site_id'] ]['spend'] ?? 0 ) + $r['spend_cents'] ); } }
				return array_values( $out );
			}
			if ( preg_match( '/^SELECT site_id, store_order_id FROM (\S+) WHERE refund_sync = 1 ORDER BY updated_at ASC LIMIT 20$/', $sql, $m ) ) {
				return array_values( array_filter( $this->tables[ $m[1] ], static fn( $r ) => 1 === (int) $r['refund_sync'] ) );
			}
			throw new \RuntimeException( 'Unhandled get_results: ' . $sql );
		}
		public function query( string $sql ) {
			if ( preg_match( '/^INSERT INTO (\S+) \(([^)]+)\)\s+VALUES \((.*?)\)\s+ON DUPLICATE KEY UPDATE /s', $sql, $m ) ) {
				preg_match_all( "/'(?:[^']|'')*'|[^,\s][^,]*/", $m[3], $v );
				$values = array_map( static fn( $x ) => "'" === $x[0] ? str_replace( "''", "'", substr( $x, 1, -1 ) ) : (int) trim( $x ), $v[0] );
				$row    = array_combine( array_map( 'trim', explode( ',', $m[2] ) ), $values );
				foreach ( $this->tables[ $m[1] ] as $i => $old ) {
					if ( $this->hit( $old, array( 'site_id' => $row['site_id'], 'store_order_id' => $row['store_order_id'] ) ) ) { $this->tables[ $m[1] ][ $i ] = array_replace( $old, $row ); return 2; }
				}
				$this->tables[ $m[1] ][] = array( 'id' => ++$this->insert_id ) + $row + array( 'refunded_cents' => 0, 'refund_state' => 'none', 'refund_sync' => 0, 'refund_error' => '' );
				return 1;
			}
			throw new \RuntimeException( 'Unhandled query: ' . $sql );
		}
	}
	$GLOBALS['wpdb']    = new Memory_Db();
	$GLOBALS['options'] = array( 'pchq_m10_db' => '1' );
	$GLOBALS['admins']  = array( 1 );
	/** 假店铺：记下总后台写回来的退款合计和状态；可以设为离线。 */
	$GLOBALS['store']   = array( 'offline' => false, 'orders' => array(), 'calls' => 0 );
}
namespace PeptideHq {
	final class Db { public static function table( string $name ): string { return 'wp_pchq_' . $name; } }
	final class Register { public const NS = 'peptide-hq/v1'; }
	final class Mail { public static function verify() { return true; } }
	final class Monitor { public const CRON = 'pchq_poll'; public const POLL_ONE = 'pchq_poll_one'; }
	final class Site_Lifecycle { public static function active( int $id ): bool { return true; } }
	final class Design_Push { public static function friendly( \WP_Error $e ): string { return $e->get_error_message(); } }
	final class Series_Db { public static function has_table( string $t ): bool { return true; } public static function add_columns( string $t, array $c ): array { return array(); } }
	final class Sites { public static function all(): array { return array( 1 => array( 'id' => 1, 'name' => 'Store 1' ), 2 => array( 'id' => 2, 'name' => 'Store 2' ), 3 => array( 'id' => 3, 'name' => 'Store 3' ) ); } }
	final class Series {
		public static function ready(): bool { return true; }
		public static function all( bool $x = false ): array { return array( 1 => array( 'id' => 1, 'name' => '系列 1' ), 2 => array( 'id' => 2, 'name' => '系列 2' ) ); }
		public static function site_map(): array { return array( 1 => array( 'series_id' => 1, 'buyer_name' => 'Buyer A' ), 2 => array( 'series_id' => 1, 'buyer_name' => 'Buyer B' ), 3 => array( 'series_id' => 2, 'buyer_name' => 'Buyer A' ) ); }
		public static function site( int $id ): array { return self::site_map()[ $id ] ?? array( 'series_id' => 0, 'buyer_name' => '' ); }
	}
	final class Stats { public static function per_site( string $from, string $to ): array { return array( 1 => array( 'visitors' => 400 ), 2 => array( 'visitors' => 100 ) ); } }
	final class Client {
		public static function request( int $site_id, string $method, string $route, array $query = array(), ?array $body = null, int $timeout = 20 ) {
			++$GLOBALS['store']['calls'];
			if ( $GLOBALS['store']['offline'] ) { return new \WP_Error( 'pchq_offline', 'store offline' ); }
			if ( '/peptide/v1/hq/order-refund' === $route ) {
				$GLOBALS['store']['orders'][ $site_id . ':' . $body['order_id'] ] = array( 'refunded_cents' => $body['refunded_cents'], 'state' => $body['state'] );
				return array( 'order_id' => $body['order_id'], 'refunded_cents' => $body['refunded_cents'], 'state' => $body['state'], 'changed' => true );
			}
			throw new \RuntimeException( 'Unexpected store route ' . $route );
		}
	}
}
namespace {
	foreach ( array( 'orders', 'refunds' ) as $file ) { require dirname( __DIR__ ) . '/includes/class-' . $file . '.php'; }
	use PeptideHq\Orders;
	use PeptideHq\Refunds;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
	$db    = $GLOBALS['wpdb'];
	$today = gmdate( 'Y-m-d' );
	$paid  = strtotime( $today . ' 10:00:00 UTC' );
	$order = static fn( int $id, int $cents, array $extra = array() ): array => array_merge( array( 'store_order_id' => $id, 'order_number' => (string) $id, 'status' => 'processing', 'paid' => true, 'is_test' => false, 'currency' => 'USD', 'total_cents' => $cents, 'created_at' => $GLOBALS['paid'] - 60, 'paid_at' => $GLOBALS['paid'], 'order_day' => $GLOBALS['today'], 'series_id' => 1, 'email_hash' => str_repeat( 'a', 64 ), 'utm_source' => 'facebook', 'ad_id' => '1201' ), $extra );
	$send  = static fn( int $site, array $orders ) => Orders::handle_report( new WP_REST_Request( array( '_pchq_site' => $site ), array( 'orders' => $orders ) ) );
	$strip = static fn( array $rows ): array => array_map( static fn( $r ) => array_diff_key( $r, array( 'reported_at' => 1, 'updated_at' => 1 ) ), $rows );
	$row   = static fn( int $site, int $id ): array => Orders::get( $site, $id );

	// ---- 纯函数 ----
	check( 'money parses', 2550 === Orders::parse_money( '25.5' ) && 123456 === Orders::parse_money( '$1,234.56' ) && 700 === Orders::parse_money( '7' ) );
	check( 'bad money refused', -1 === Orders::parse_money( '' ) && -1 === Orders::parse_money( '-5' ) && -1 === Orders::parse_money( '1.234' ) && -1 === Orders::parse_money( 'abc' ) );
	check( 'money formats', '$1,234.50' === Orders::money( 123450 ) && '-$5.00' === Orders::money( -500 ) && 'EUR 2.00' === Orders::money( 200, 'EUR' ) );
	check( 'valid day', Orders::valid_day( '2026-11-10' ) && ! Orders::valid_day( '2026-02-30' ) && ! Orders::valid_day( '2026-1-1' ) );
	check( 'refund state', 'none' === Refunds::state( 1000, 0 ) && 'partial' === Refunds::state( 1000, 1 ) && 'full' === Refunds::state( 1000, 1000 ) );
	check( 'check passes a valid mark', null === Refunds::check( 1000, 0, 1000, '2026-11-10', true, '2026-11-10' ) );
	check( 'check refuses unpaid, zero, too much, bad or future date', null !== Refunds::check( 1000, 0, 100, '2026-11-10', false, '2026-11-10' ) && null !== Refunds::check( 1000, 0, 0, '2026-11-10', true, '2026-11-10' ) && null !== Refunds::check( 1000, 600, 401, '2026-11-10', true, '2026-11-10' ) && null !== Refunds::check( 1000, 0, 100, '2026-13-01', true, '2026-11-10' ) && null !== Refunds::check( 1000, 0, 100, '2026-11-11', true, '2026-11-10' ) );
	check( 'daily: fresh row gets netted', array( 'revenue' => 180.0, 'gross_cents' => 23000, 'refunded_cents' => 5000 ) === Refunds::adjust_daily( 230.0, 0, 0, 5000 ) );
	check( 'daily: adjusted row unchanged', null === Refunds::adjust_daily( 180.0, 23000, 5000, 5000 ) );
	check( 'daily: refund changed', array( 'revenue' => 150.0, 'gross_cents' => 23000, 'refunded_cents' => 8000 ) === Refunds::adjust_daily( 180.0, 23000, 5000, 8000 ) );
	check( 'daily: void restores gross', array( 'revenue' => 230.0, 'gross_cents' => 23000, 'refunded_cents' => 0 ) === Refunds::adjust_daily( 180.0, 23000, 5000, 0 ) );
	check( 'daily: nothing to do', null === Refunds::adjust_daily( 230.0, 0, 0, 0 ) && null === Refunds::adjust_daily( 230.0, 23000, 0, 0 ) );
	check( 'order shape rejects bad ids and amounts', null === Orders::clean_order( array( 'store_order_id' => 0, 'total_cents' => 1 ) ) && null === Orders::clean_order( array( 'store_order_id' => 5, 'total_cents' => -1 ) ) && null === Orders::clean_order( array( 'store_order_id' => '5', 'total_cents' => 1 ) ) && null === Orders::clean_order( 'x' ) );
	check( 'order shape drops unknown fields and bad hash', array( 'order_day' => '', 'email_hash' => '' ) === array_intersect_key( Orders::clean_order( array( 'store_order_id' => 5, 'total_cents' => 1, 'email_hash' => 'plain@example.test', 'order_day' => 'yesterday', 'billing_name' => 'X' ) ), array( 'email_hash' => 1, 'order_day' => 1 ) ) && ! array_key_exists( 'billing_name', Orders::clean_order( array( 'store_order_id' => 5, 'total_cents' => 1, 'billing_name' => 'X' ) ) ) );

	// ---- 上报：按（店铺，订单号）覆盖写入，重复上报结果不变 ----
	$batch1 = array( $order( 100, 15000 ), $order( 101, 8000 ), $order( 102, 5000, array( 'is_test' => true ) ), $order( 103, 9900, array( 'status' => 'pending', 'paid' => false, 'paid_at' => 0 ) ) );
	$res    = $send( 1, $batch1 );
	check( 'first report accepted', array( 100, 101, 102, 103 ) === $res['accepted'] && array() === $res['rejected'] );
	$send( 2, array( $order( 200, 20000 ) ) );
	$send( 3, array( $order( 300, 10000, array( 'series_id' => 2 ) ), $order( 301, 4000, array( 'series_id' => 77 ) ) ) );
	check( 'seven orders stored', 7 === count( $db->tables['wp_pchq_orders'] ) );
	check( 'unknown series falls back to the store series', 2 === $row( 3, 301 )['series_id'] );
	$before = serialize( $strip( $db->tables['wp_pchq_orders'] ) );
	$send( 1, $batch1 );
	$send( 2, array( $order( 200, 20000 ) ) );
	check( 'repeat report changes nothing', $before === serialize( $strip( $db->tables['wp_pchq_orders'] ) ) );
	check( 'same order id in two stores stays separate', is_array( $send( 2, array( $order( 100, 111 ) ) ) ) && 15000 === $row( 1, 100 )['total_cents'] && 111 === $row( 2, 100 )['total_cents'] );
	$db->tables['wp_pchq_orders'] = array_values( array_filter( $db->tables['wp_pchq_orders'], static fn( $r ) => ! ( 2 === (int) $r['site_id'] && 100 === (int) $r['store_order_id'] ) ) );
	check( 'oversized batch refused', is_wp_error( $send( 1, array_fill( 0, 51, $order( 1, 1 ) ) ) ) && is_wp_error( $send( 1, array() ) ) );
	$mixed = $send( 1, array( $order( 104, 100 ), array( 'store_order_id' => 0 ) ) );
	check( 'invalid order rejected, valid one kept', array( 104 ) === $mixed['accepted'] && 'invalid' === $mixed['rejected'][0]['code'] );
	$db->tables['wp_pchq_orders'] = array_values( array_filter( $db->tables['wp_pchq_orders'], static fn( $r ) => 104 !== (int) $r['store_order_id'] ) );

	// ---- 总后台合计：只算已付款、非测试单 ----
	$totals = Orders::site_totals();
	check( 'store 1 totals exclude test and unpaid', array( 'orders' => 2, 'gross_cents' => 23000, 'refunded_cents' => 0, 'currency' => 'USD' ) === $totals[1] );
	check( 'store 2 and 3 totals', 20000 === $totals[2]['gross_cents'] && 14000 === $totals[3]['gross_cents'] && 2 === $totals[3]['orders'] );

	// ---- 三级报表：系列 → 投手 → 店铺，合计相等 ----
	Orders::set_spend( 1, $today, 10000, 1 );
	Orders::set_spend( 2, $today, 5000, 1 );
	$levels_equal = static function ( array $rep ): bool {
		$keys = array( 'orders', 'gross_cents', 'refunded_cents', 'net_cents', 'spend_cents', 'visitors' );
		$add  = static function ( array $rows ) use ( $keys ): array { $t = array_fill_keys( $keys, 0 ); foreach ( $rows as $r ) { foreach ( $keys as $k ) { $t[ $k ] += $r[ $k ]; } } return $t; };
		$pick = static fn( array $m ): array => array_intersect_key( $m, array_flip( $keys ) );
		$stores = array();
		$buyers = array();
		foreach ( $rep['series'] as $s ) {
			if ( $pick( $s['metrics'] ) != $add( array_column( $s['buyers'], 'metrics' ) ) ) { return false; }
			foreach ( $s['buyers'] as $b ) {
				if ( $pick( $b['metrics'] ) != $add( $b['stores'] ) ) { return false; }
				$buyers[] = $b['metrics'];
				$stores   = array_merge( $stores, $b['stores'] );
			}
		}
		return $pick( $rep['total'] ) == $add( array_column( $rep['series'], 'metrics' ) ) && $pick( $rep['total'] ) == $add( $buyers ) && $pick( $rep['total'] ) == $add( $stores );
	};
	$rep = Orders::report( $today, $today );
	check( 'report totals', 5 === $rep['total']['orders'] && 57000 === $rep['total']['gross_cents'] && 57000 === $rep['total']['net_cents'] && 15000 === $rep['total']['spend_cents'] && 3.8 === $rep['total']['roas'] );
	check( 'two series', 2 === count( $rep['series'] ) && 43000 === $rep['series'][0]['metrics']['net_cents'] && 14000 === $rep['series'][1]['metrics']['net_cents'] );
	check( 'series 1 has two buyers', array( 'Buyer A', 'Buyer B' ) === array_column( $rep['series'][0]['buyers'], 'buyer_name' ) && 2.3 === $rep['series'][0]['buyers'][0]['metrics']['roas'] && 4.0 === $rep['series'][0]['buyers'][1]['metrics']['roas'] );
	check( 'no spend means no ROAS', null === $rep['series'][1]['metrics']['roas'] );
	check( 'three levels add up', $levels_equal( $rep ) );
	check( 'yesterday is empty', 0 === Orders::report( gmdate( 'Y-m-d', time() - 86400 ), gmdate( 'Y-m-d', time() - 86400 ) )['total']['orders'] );

	// ---- 每日数字：轮询写入的是毛额 ----
	$db->tables['wp_pchq_daily'][] = array( 'site_id' => 1, 'day' => $today, 'orders' => 3, 'revenue' => 280.0, 'visitors' => 400, 'gross_cents' => 0, 'refunded_cents' => 0 );
	$daily = static fn(): array => $GLOBALS['wpdb']->tables['wp_pchq_daily'][0];

	// ---- 权限：不是管理员不能标记 ----
	$nope = Refunds::mark( 1, 100, 5000, $today, '', 2 );
	check( 'non-admin refused and nothing written', is_wp_error( $nope ) && array() === $db->tables['wp_pchq_refunds'] && 0 === $row( 1, 100 )['refunded_cents'] && 0 === $GLOBALS['store']['calls'] );

	// ---- 部分退款 ----
	$r1 = Refunds::mark( 1, 100, 5000, $today, ' chargeback <b>x</b> ', 1 );
	check( 'partial refund marked and pushed', is_array( $r1 ) && true === $r1['pushed'] && 5000 === $row( 1, 100 )['refunded_cents'] && 'partial' === $row( 1, 100 )['refund_state'] && 0 === $row( 1, 100 )['refund_sync'] );
	check( 'store order shows partial', array( 'refunded_cents' => 5000, 'state' => 'partial' ) === $GLOBALS['store']['orders']['1:100'] );
	check( 'note stored clean', 'chargeback x' === $db->tables['wp_pchq_refunds'][0]['note'] && 1 === $db->tables['wp_pchq_refunds'][0]['created_by'] );
	check( 'daily revenue is net', 230.0 === $daily()['revenue'] && 28000 === $daily()['gross_cents'] && 5000 === $daily()['refunded_cents'] );
	$rep = Orders::report( $today, $today );
	check( 'report nets the partial refund at every level', 52000 === $rep['total']['net_cents'] && 5000 === $rep['total']['refunded_cents'] && 38000 === $rep['series'][0]['metrics']['net_cents'] && 18000 === $rep['series'][0]['buyers'][0]['metrics']['net_cents'] && 1.8 === $rep['series'][0]['buyers'][0]['metrics']['roas'] && 20000 === $rep['series'][0]['buyers'][1]['metrics']['net_cents'] && $levels_equal( $rep ) );
	check( 'order count unchanged by a refund', 5 === $rep['total']['orders'] );

	// ---- 超额被拒；第二笔凑成全额 ----
	check( 'over-refund refused', is_wp_error( Refunds::mark( 1, 100, 10001, $today, '', 1 ) ) && 5000 === $row( 1, 100 )['refunded_cents'] );
	check( 'unknown order refused', is_wp_error( Refunds::mark( 1, 999, 100, $today, '', 1 ) ) );
	check( 'unpaid order refused', is_wp_error( Refunds::mark( 1, 103, 100, $today, '', 1 ) ) );
	$r2 = Refunds::mark( 1, 100, 10000, $today, 'rest', 1 );
	check( 'second mark makes it full', is_array( $r2 ) && 15000 === $row( 1, 100 )['refunded_cents'] && 'full' === $row( 1, 100 )['refund_state'] && 'full' === $GLOBALS['store']['orders']['1:100']['state'] );
	check( 'daily nets both marks', 130.0 === $daily()['revenue'] && 15000 === $daily()['refunded_cents'] );

	// ---- 轮询覆盖每日数字后，净额能重新算出来；反复执行结果不变 ----
	$db->tables['wp_pchq_daily'][0] = array_replace( $daily(), array( 'revenue' => 280.0, 'gross_cents' => 0, 'refunded_cents' => 0 ) );
	Refunds::apply_daily();
	check( 'net restored after a poll overwrite', 130.0 === $daily()['revenue'] && 28000 === $daily()['gross_cents'] && 15000 === $daily()['refunded_cents'] );
	$snapshot = serialize( $db->tables );
	Refunds::apply_daily();
	Refunds::apply_daily( 1 );
	check( 'apply_daily is repeatable', $snapshot === serialize( $db->tables ) );

	// ---- 撤销：记录保留，销售额恢复 ----
	$v1 = Refunds::void( $r1['id'], 1 );
	check( 'void first mark leaves the second', is_array( $v1 ) && 10000 === $row( 1, 100 )['refunded_cents'] && 'partial' === $row( 1, 100 )['refund_state'] && array( 'refunded_cents' => 10000, 'state' => 'partial' ) === $GLOBALS['store']['orders']['1:100'] );
	check( 'void twice refused', is_wp_error( Refunds::void( $r1['id'], 1 ) ) && is_wp_error( Refunds::void( 9999, 1 ) ) && is_wp_error( Refunds::void( $r2['id'], 2 ) ) );
	Refunds::void( $r2['id'], 1 );
	check( 'all voided: order back to none', 0 === $row( 1, 100 )['refunded_cents'] && 'none' === $row( 1, 100 )['refund_state'] && array( 'refunded_cents' => 0, 'state' => 'none' ) === $GLOBALS['store']['orders']['1:100'] );
	check( 'daily back to gross', 280.0 === $daily()['revenue'] && 0 === $daily()['refunded_cents'] );
	$rep = Orders::report( $today, $today );
	check( 'report restored after void', 57000 === $rep['total']['net_cents'] && 0 === $rep['total']['refunded_cents'] && 3.8 === $rep['total']['roas'] && $levels_equal( $rep ) );
	$history = Refunds::for_order( 1, 100 );
	check( 'both records kept with who and when', 2 === count( $history ) && $history[0]['voided_at'] > 0 && 1 === $history[0]['voided_by'] && $history[1]['voided_at'] > 0 && 5000 === $history[1]['amount_cents'] );

	// ---- 全额退款一次标完 ----
	$r3 = Refunds::mark( 2, 200, 20000, $today, 'full', 1 );
	check( 'full refund in one mark', is_array( $r3 ) && 'full' === $row( 2, 200 )['refund_state'] && array( 'refunded_cents' => 20000, 'state' => 'full' ) === $GLOBALS['store']['orders']['2:200'] );
	$rep = Orders::report( $today, $today );
	check( 'full refund at every level', 37000 === $rep['total']['net_cents'] && 23000 === $rep['series'][0]['metrics']['net_cents'] && 0 === $rep['series'][0]['buyers'][1]['metrics']['net_cents'] && 0.0 === $rep['series'][0]['buyers'][1]['metrics']['roas'] && 14000 === $rep['series'][1]['metrics']['net_cents'] && $levels_equal( $rep ) );
	check( 'no further mark on a fully refunded order', is_wp_error( Refunds::mark( 2, 200, 1, $today, '', 1 ) ) );

	// ---- 店铺连不上：总后台先记下，之后重试写回 ----
	$GLOBALS['store']['offline'] = true;
	$r4 = Refunds::mark( 3, 300, 2500, $today, '', 1 );
	check( 'offline store: marked in HQ, pending write-back', is_array( $r4 ) && false === $r4['pushed'] && '' !== $r4['message'] && 2500 === $row( 3, 300 )['refunded_cents'] && 1 === $row( 3, 300 )['refund_sync'] && ! isset( $GLOBALS['store']['orders']['3:300'] ) && 1 === Refunds::pending_count() );
	Refunds::retry_pending();
	check( 'still pending while offline', 1 === Refunds::pending_count() );
	$GLOBALS['store']['offline'] = false;
	Refunds::retry_pending();
	check( 'written back once the store returns', 0 === Refunds::pending_count() && array( 'refunded_cents' => 2500, 'state' => 'partial' ) === $GLOBALS['store']['orders']['3:300'] && '' === $row( 3, 300 )['refund_error'] );

	// ---- 店铺重新上报：金额变了，退款状态跟着重算；上报不会冲掉退款 ----
	$send( 3, array( $order( 300, 2500, array( 'series_id' => 2 ) ) ) );
	check( 'report keeps refund and recomputes state', 2500 === $row( 3, 300 )['refunded_cents'] && 'full' === $row( 3, 300 )['refund_state'] && 1 === $row( 3, 300 )['refund_sync'] );

	check( 'refund code never creates a WooCommerce refund', false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-refunds.php' ), 'wc_create_refund' ) );

	echo 'PASS ', $checks, " in-memory M10 order and refund checks; no WordPress, database, or network.\n";
}
