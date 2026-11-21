<?php
/**
 * 2026-11-21：店铺换顾客域名后，邮件中心给新域名准备 orders@ 发件地址并核对 SPF / DKIM（Mail_Domains）。
 *
 * 行为级：真的载入 Mail_Domains、Mail、Domain_Verify，Cloudflare 和店铺表用内存替身。覆盖：
 *   1. diff_records：引号、末尾点号写法不同也算同一条；缺的那条点名；
 *   2. 离线检查：新域名没有发件连接 → 不通过，明说会退回默认发信邮箱；
 *   3. 11-17 那种 CDN 行没记 zone 编号 → apply() 自己按域名查 zone、建连接、记 mail_ok；每次 Cloudflare 调用都带上域名（多账号）；
 *   4. 重复 apply 不重复建连接（幂等）；
 *   5. 在线检查：DKIM 缺一条 → 不通过并点名；补齐 → 通过；
 *   6. 店铺换域名（pchq_site_moved）只排一次单次任务；域名没变不排；
 *   7. backfill 会处理没有 zone 编号的已完成域名。
 *
 * 不碰数据库、不发网络请求、不需要 WordPress。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );

	$GLOBALS['opts'] = array();
	$GLOBALS['scheduled'] = array();

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['scheduled'][ $hook . json_encode( $args ) ] ?? false; }
	function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['scheduled'][ $hook . json_encode( $args ) ] = $ts; return true; }
	function add_action( ...$a ) {}
	function sanitize_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? (string) $e : ''; }

	class WP_Error {
		public function __construct( private string $code = '', private string $msg = '', private $data = null ) {}
		public function get_error_message() { return $this->msg; }
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
}

namespace PeptideHq {

	final class Site_Urls {
		public static function public_url( array $site ): string { return (string) ( $site['public_url'] ?? '' ); }
	}
	final class Sites {
		public static array $rows = array();
		public static function get( int $id ): ?array { return self::$rows[ $id ] ?? null; }
	}
	/** Cloudflare 替身：按路径回答，记下每次调用和带的域名。 */
	final class Cdn {
		public static array $calls = array();
		public static array $zone_records = array();
		public static array $expected = array();
		public static function domains(): array { $d = get_option( 'pchq_cdn_domains', array() ); return is_array( $d ) ? $d : array(); }
		private static function set_domain( string $domain, array $fields ): void {
			$all = self::domains();
			$all[ $domain ] = array_merge( $all[ $domain ] ?? array(), $fields );
			update_option( 'pchq_cdn_domains', $all );
		}
		public static function request( string $method, string $path, ?array $body = null, string $domain = '' ) {
			self::$calls[] = array( $method, $path, $domain );
			if ( str_starts_with( $path, '/zones?name=' ) ) { return array( array( 'id' => 'zone-' . substr( $path, 12 ) ) ); }
			if ( str_ends_with( $path, '/email/sending/subdomains?per_page=50' ) ) { return array( array( 'id' => 'sub1', 'name' => $domain ) ); }
			if ( 'POST' === $method && str_ends_with( $path, '/email/sending/subdomains' ) ) { return array( 'id' => 'sub1', 'name' => $domain ); }
			if ( str_ends_with( $path, '/dns' ) ) { return self::$expected; }
			if ( 'GET' === $method && str_contains( $path, '/dns_records' ) ) { return self::$zone_records; }
			if ( 'POST' === $method && str_contains( $path, '/dns_records' ) ) { self::$zone_records[] = $body; return $body; }
			return new \WP_Error( 'unexpected', $method . ' ' . $path );
		}
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/class-domain-verify.php';
	require_once dirname( __DIR__ ) . '/includes/class-mail.php';
	require_once dirname( __DIR__ ) . '/includes/class-mail-domains.php';

	use PeptideHq\Cdn;
	use PeptideHq\Mail;
	use PeptideHq\Mail_Domains as MD;
	use PeptideHq\Sites;

	$D = 'lumornea.com';
	$expected = array(
		array( 'type' => 'MX', 'name' => 'cf-bounce.' . $D, 'content' => 'route1.mx.cloudflare.net.', 'priority' => 10 ),
		array( 'type' => 'TXT', 'name' => 'cf-bounce.' . $D, 'content' => '"v=spf1 include:_spf.mx.cloudflare.net ~all"' ),
		array( 'type' => 'TXT', 'name' => 'cf-bounce._domainkey.' . $D, 'content' => 'v=DKIM1; p=MIIBIjAN' ),
		array( 'type' => 'TXT', 'name' => '_dmarc.' . $D, 'content' => 'v=DMARC1; p=none' ),
	);

	// 1. diff_records
	$have = array(
		array( 'type' => 'MX', 'name' => 'cf-bounce.' . $D, 'content' => 'route1.mx.cloudflare.net' ),
		array( 'type' => 'TXT', 'name' => 'cf-bounce.' . $D, 'content' => 'v=spf1 include:_spf.mx.cloudflare.net ~all' ),
		array( 'type' => 'TXT', 'name' => '_dmarc.' . $D . '.', 'content' => '"v=DMARC1; p=none"' ),
	);
	$miss = MD::diff_records( $expected, $have );
	check( 'diff: only DKIM missing', array( 'TXT cf-bounce._domainkey.' . $D ) === $miss );
	check( 'diff: nothing missing when complete', array() === MD::diff_records( $expected, array_merge( $have, array( $expected[2] ) ) ) );

	// 2. 离线检查：没有发件连接
	$GLOBALS['opts'] = array(
		'fluentmail-settings' => array(
			'misc'        => array( 'default_connection' => 'base' ),
			'connections' => array( 'base' => array( 'title' => 'VialCove', 'provider_settings' => array( 'provider' => 'cloudflare', 'sender_email' => 'orders@vialcovepeptides.com', 'sender_name' => 'VialCove' ) ) ),
			'mappings'    => array( 'orders@vialcovepeptides.com' => 'base' ),
		),
		'pchq_cdn_domains' => array( $D => array( 'site_id' => 16, 'stage' => 'done' ) ), // 和线上 11-17 后一样：没有 zone_id
	);
	$c = MD::check( $D, false );
	check( 'offline: not ok without sender', false === $c['ok'] && false === $c['sender'] );
	check( 'offline: says it falls back to default sender', str_contains( $c['message'], '默认发信邮箱' ) && str_contains( $c['message'], 'orders@' . $D ) );
	$site = array( 'id' => 16, 'name' => '光辉', 'public_url' => 'https://www.lumornea.com/', 'dual_domain' => true );
	$f = Mail::site_sender( $site );
	check( 'before: site_sender falls back', 'orders@vialcovepeptides.com' === $f['email'] && false === $f['own_domain'] );

	// 3. apply 没有 zone_id 的域名
	Cdn::$expected = $expected;
	Cdn::$zone_records = array();
	Cdn::$calls = array();
	MD::apply( $D );
	$row = Cdn::domains()[ $D ];
	check( 'apply: zone id resolved and stored', 'zone-' . $D === ( $row['zone_id'] ?? '' ) );
	check( 'apply: mail_ok recorded', ! empty( $row['mail_ok'] ) && '' === $row['mail_error'] );
	check( 'apply: mapping created', MD::has_connection( $D ) );
	$f = Mail::site_sender( $site );
	check( 'after: site_sender uses own domain with brand name', 'orders@' . $D === $f['email'] && true === $f['own_domain'] && 'Lumornea' === $f['name'] );
	check( 'apply: every Cloudflare call names the domain', array() === array_filter( Cdn::$calls, static fn( $c ) => $c[2] !== $D ) );
	check( 'apply: DNS records created', 4 === count( Cdn::$zone_records ) );

	// 4. 幂等
	$conns = count( get_option( 'fluentmail-settings' )['connections'] );
	MD::apply( $D );
	check( 'apply twice: no duplicate connection', $conns === count( get_option( 'fluentmail-settings' )['connections'] ) );
	check( 'apply twice: no duplicate DNS records', 4 === count( Cdn::$zone_records ) );

	// 5. 在线检查
	Cdn::$zone_records = $have; // DKIM 被删掉的情况
	$c = MD::check( $D, true );
	check( 'online: DKIM missing → not ok', false === $c['ok'] && in_array( 'TXT cf-bounce._domainkey.' . $D, $c['missing'], true ) );
	check( 'online: message names SPF / DKIM', str_contains( $c['message'], 'SPF / DKIM' ) && str_contains( $c['message'], 'cf-bounce._domainkey' ) );
	Cdn::$zone_records = array_merge( $have, array( $expected[2] ) );
	$c = MD::check( $D, true );
	check( 'online: complete → ok', true === $c['ok'] );
	MD::remember_check( $D, $c );
	check( 'remember_check stores the result', true === MD::last_check( $D )['ok'] );

	// 6. 换域名事件
	Sites::$rows[16] = $site;
	MD::on_site_moved( 16, array( 'id' => 16, 'public_url' => 'https://www.vialcoveofficial.com/', 'dual_domain' => true ) );
	check( 'moved: one event scheduled', 1 === count( $GLOBALS['scheduled'] ) );
	MD::on_site_moved( 16, array( 'id' => 16, 'public_url' => 'https://www.vialcoveofficial.com/', 'dual_domain' => true ) );
	check( 'moved twice: still one event', 1 === count( $GLOBALS['scheduled'] ) );
	$GLOBALS['scheduled'] = array();
	MD::on_site_moved( 16, $site );
	check( 'not moved: nothing scheduled', array() === $GLOBALS['scheduled'] );
	MD::run_moved( 16 );
	check( 'run_moved: check remembered', is_array( MD::last_check( $D ) ) );

	// 7. backfill 处理没有 zone_id 的已完成域名
	$E = 'korgavex.com';
	$cdn = get_option( 'pchq_cdn_domains' );
	$cdn[ $E ] = array( 'site_id' => 15, 'stage' => 'done' );
	update_option( 'pchq_cdn_domains', $cdn );
	Cdn::$expected = array();
	MD::backfill();
	check( 'backfill: korgavex gets a sender', MD::has_connection( $E ) && 'zone-' . $E === Cdn::domains()[ $E ]['zone_id'] );

	echo "mail-domains-sync-pure: {$checks} checks passed\n";
}
