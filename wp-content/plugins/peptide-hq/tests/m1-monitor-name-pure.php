<?php
/**
 * M1 回归：轮询不能用店铺自己的名称覆盖总后台的店铺标签。
 * 2026-11-08 的事故：“轮询时跟随店铺名”把 6 个标签覆盖成了域名。纯逻辑：php -n -d extension=mbstring m1-monitor-name-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } }
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function apply_filters( string $hook, $value ) { return $value; }
	function add_filter( ...$a ): void {}
	function do_action( string $hook, ...$args ): void { $GLOBALS['pc_actions'][] = array( $hook, $args ); }
	function wp_json_encode( $v ): string { return json_encode( $v ); }
	function __( string $s, string $domain = '' ): string { return $s; }
	function sanitize_text_field( string $s ): string { return trim( strip_tags( $s ) ); }
	function number_format_i18n( $n, int $d = 0 ): string { return number_format( (float) $n, $d ); }
}
namespace PeptideHq {
	final class Sites {
		public static array $row = array( 'id' => 1, 'name' => '芒果', 'uuid' => 'u-1', 'status' => 'online', 'fails' => 0, 'last_full' => 0, 'server_id' => 0 );
		public static array $updates = array();
		public static function get( int $id ): ?array { return 1 === $id ? self::$row : null; }
		public static function key( int $id, bool $pending = false ): string { return $pending ? '' : 'key'; }
		public static function update( int $id, array $fields ): void { self::$updates[] = $fields; self::$row = array_replace( self::$row, $fields ); }
		public static function all(): array { return array( 1 => self::$row ); }
	}
	final class Site_Lifecycle { public static function active( int $id ): bool { return true; } }
	final class Site_Urls { public static function management( array $site ): string { return 'https://s0010.manager.example.test/'; } }
	final class Client {
		public static $status;
		public static function status( int $id ) { return self::$status; }
		public static function summary( int $id, int $days ) { return array( 'days' => array(), 'currency' => 'USD' ); }
		public static function config( int $id, array $config ) { return array(); }
		public static function request_raw() { return new \WP_Error( 'unused' ); }
	}
	final class Stats { public static function store( int $id, array $days, string $currency ): void {} public static function average_orders( int $id, int $days ): float { return 0.0; } }
	final class Servers { public static function ip_for_site( int $id ): string { return ''; } }
	final class Crypto { public static function encrypt( string $s ): string { return $s; } }
	final class Alerts { public static array $log = array(); public static function raise( int $s, string $t, string $r, string $m ): bool { self::$log[] = "raise:$t"; return true; } public static function resolve( int $s, string $t, array $keep = array() ): void { self::$log[] = "resolve:$t"; } }
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-monitor.php';
}
namespace {
	use PeptideHq\Client;
	use PeptideHq\Monitor;
	use PeptideHq\Sites;
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	Client::$status = array( 'uuid' => 'u-1', 'name' => 'vialcoveplus.com', 'domains' => array(), 'last_order' => 0, 'coming_soon' => false, 'config' => array() );
	check( 'poll succeeds', true === Monitor::poll( 1 ) );
	check( 'HQ label is not overwritten by the store blogname', '芒果' === Sites::$row['name'] );
	foreach ( Sites::$updates as $fields ) { check( 'no update touches name', ! array_key_exists( 'name', $fields ) ); }
	check( 'store-reported name still stored in info', 'vialcoveplus.com' === json_decode( Sites::$row['info'], true )['name'] );
	check( 'store marked online', 'online' === Sites::$row['status'] && 0 === Sites::$row['fails'] );
	check( 'summary days handed to modules', 'pchq_summary_days' === ( $GLOBALS['pc_actions'][0][0] ?? '' ) && 1 === $GLOBALS['pc_actions'][0][1][0] && is_array( $GLOBALS['pc_actions'][0][1][1] ) );

	Client::$status = new WP_Error( 'pchq_offline', 'timeout' );
	Monitor::poll( 1 ); Monitor::poll( 1 );
	check( 'two failures mark offline and keep label', 'offline' === Sites::$row['status'] && '芒果' === Sites::$row['name'] );

	echo "m1-monitor-name-pure: {$checks} checks passed\n";
}
