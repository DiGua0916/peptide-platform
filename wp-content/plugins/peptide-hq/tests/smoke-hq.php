<?php
/**
 * 总后台（peptide-hq）冒烟测试：连接码解析、密钥加密、告警规则、每日数字汇总、接入店铺的错误处理，
 * 以及对已经接入的店铺做一次真实检查和一键登录。开发环境专用。
 *
 * 运行（在总后台那个 WordPress 上）：wp eval-file wp-content/plugins/peptide-hq/tests/smoke-hq.php
 * 用一个不存在的店铺编号写测试数据，结束时删掉。
 *
 * @package PeptideHq
 */

defined( 'ABSPATH' ) || exit;

use PeptideHq\Alerts;
use PeptideHq\Client;
use PeptideHq\Crypto;
use PeptideHq\Db;
use PeptideHq\Monitor;
use PeptideHq\Sites;
use PeptideHq\Stats;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
$fake = 999901; // 测试用的店铺编号（表里没有这个店铺）。

/** 像店铺一样向总后台注册（真实请求）。 */
$register = static function ( array $body, ?string $secret = null, ?int $time = null ): array {
	$time = $time ?? time();
	$json = (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
	$res  = wp_remote_post(
		add_query_arg( 'rest_route', rawurlencode( '/peptide-hq/v1/register' ), home_url( '/' ) ),
		array(
			'timeout' => 30,
			'headers' => array(
				'X-PC-HQ-Time' => (string) $time,
				'X-PC-HQ-Sign' => \PeptideHq\Register::sign( $secret ?? \PeptideHq\Register::secret(), $time, $json ),
				'Content-Type' => 'application/json',
			),
			'body'    => $json,
		)
	);
	return array( (int) wp_remote_retrieve_response_code( $res ), json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
};

// 1. 店铺自动注册和密钥加密。
$join_url = 'http://pc-smoke-join.invalid/';
$k1       = str_repeat( 'a1', 32 );
[ $c ]    = $register( array( 'url' => $join_url, 'key' => $k1, 'name' => 'Smoke Join' ), str_repeat( 'f', 64 ) );
$ok( 'a store signing with a wrong registration secret is refused (401)', 401 === $c );
[ $c ] = $register( array( 'url' => $join_url, 'key' => $k1, 'name' => 'Smoke Join' ), null, time() - 900 );
$ok( 'an old registration request is refused (401)', 401 === $c );
[ $c ] = $register( array( 'url' => 'javascript:alert(1)', 'key' => $k1 ) );
[ $c2 ] = $register( array( 'url' => $join_url, 'key' => 'short' ) );
$ok( 'a bad address or key is refused (400)', 400 === $c && 400 === $c2 );
[ $c, $r ] = $register( array( 'url' => $join_url, 'key' => $k1, 'name' => 'Smoke Join' ) );
$jid       = (int) ( $r['site'] ?? 0 );
$ok( 'a new store joins by itself: listed with its name, key stored encrypted', 200 === $c && 'new' === $r['state'] && Sites::get( $jid ) && 'Smoke Join' === Sites::get( $jid )['name'] && $k1 === Sites::key( $jid ) && ! str_contains( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT key_enc FROM ' . Db::table( 'sites' ) . ' WHERE id = %d', $jid ) ), $k1 ) ); // phpcs:ignore WordPress.DB
[ , $r ] = $register( array( 'url' => $join_url, 'key' => $k1, 'name' => 'Smoke Join' ), null, time() + 2 ); // 同一秒内完全相同的请求会被当成重放拒绝。
$ok( 'registering again with the same key changes nothing', 'same' === $r['state'] && $jid === (int) $r['site'] && 1 === count( array_filter( Sites::all(), static fn( $s ) => $s['url'] === $join_url ) ) );
[ , $r ] = $register( array( 'url' => $join_url, 'key' => str_repeat( 'b2', 32 ), 'name' => 'Smoke Join' ) );
$ok( 'a new key waits for confirmation; the old key stays in use', 'pending' === $r['state'] && $k1 === Sites::key( $jid ) && str_repeat( 'b2', 32 ) === Sites::key( $jid, true ) );
Monitor::poll( $jid );
$ok( 'a new key that does not work is thrown away at the next check', $k1 === Sites::key( $jid ) && '' === Sites::key( $jid, true ) );
[ , $r ] = $register( array( 'url' => $join_url, 'key' => $k1, 'name' => 'Smoke Join', 'uuid' => 'smoke-uuid-1' ), null, time() + 3 );
[ , $r ] = $register( array( 'url' => 'http://pc-smoke-moved.invalid/', 'key' => $k1, 'name' => 'Smoke Join', 'uuid' => 'smoke-uuid-1' ), null, time() + 4 );
$ok( 'a store that changed its main domain is recognised by its ID and keeps its record', 'moved' === $r['state'] && $jid === (int) $r['site'] && 'http://pc-smoke-moved.invalid/' === Sites::get( $jid )['url'] );
[ , $r ] = $register( array( 'url' => 'http://pc-smoke-thief.invalid/', 'key' => str_repeat( 'e5', 32 ), 'uuid' => 'smoke-uuid-1' ), null, time() + 5 );
$ok( 'someone using the same ID with another key cannot move the store', 'pending' === $r['state'] && 'http://pc-smoke-moved.invalid/' === Sites::get( $jid )['url'] );

// 服务器。
$ok( 'a server needs a valid IP address', is_wp_error( \PeptideHq\Servers::save( 0, 'Bad', 'not-an-ip', '' ) ) );
$sv = \PeptideHq\Servers::save( 0, 'Smoke server', '198.51.100.20', 'smoke' );
$ok( 'a server is saved with its name and IP', is_int( $sv ) && '198.51.100.20' === \PeptideHq\Servers::get( $sv )['ip'] );
$assigned = \PeptideHq\Servers::assign( $jid, $sv );
$ok( 'assigning saves the server even if the store cannot be reached yet (sent again at the next check)', is_wp_error( $assigned ) && $sv === Sites::get( $jid )['server_id'] && '198.51.100.20' === \PeptideHq\Servers::ip_for_site( $jid ) );
$ok( 'a server that stores still use cannot be deleted', is_wp_error( \PeptideHq\Servers::delete( $sv ) ) );
Sites::delete( $jid );
$ok( 'a server without stores can be deleted', true === \PeptideHq\Servers::delete( $sv ) && null === \PeptideHq\Servers::get( $sv ) );
$enc = Crypto::encrypt( 'secret-key-123' );
$bad = base64_encode( substr( (string) base64_decode( $enc ), 0, -1 ) . 'x' );
$ok( 'store keys are stored encrypted; a changed value does not decrypt', ! str_contains( $enc, 'secret' ) && 'secret-key-123' === Crypto::decrypt( $enc ) && '' === Crypto::decrypt( $bad ) && $enc !== Crypto::encrypt( 'secret-key-123' ) );

// 2. 告警。
$ok( 'an alert opens once per store and problem; a second raise only updates the text', true === Alerts::raise( $fake, 'offline', '', 'first' ) && false === Alerts::raise( $fake, 'offline', '', 'second' ) );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::table( 'alerts' ) . " WHERE site_id = %d AND type = 'offline' AND resolved = 0", $fake ), ARRAY_A ); // phpcs:ignore WordPress.DB
$ok( 'the open alert shows the latest text', $row && 'second' === $row['message'] );
Alerts::raise( $fake, 'domain', 'a.smoke-shop.com', 'a' );
Alerts::raise( $fake, 'domain', 'b.smoke-shop.com', 'b' );
Alerts::resolve( $fake, 'domain', array( 'b.smoke-shop.com' ) );
$open_refs = $wpdb->get_col( $wpdb->prepare( 'SELECT ref FROM ' . Db::table( 'alerts' ) . " WHERE site_id = %d AND type = 'domain' AND resolved = 0", $fake ) ); // phpcs:ignore WordPress.DB
$ok( 'resolving closes fixed problems and keeps the ones still failing', array( 'b.smoke-shop.com' ) === $open_refs );
Alerts::close( (int) $row['id'] );
$ok( 'an alert can be marked as handled', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'alerts' ) . " WHERE site_id = %d AND type = 'offline' AND resolved = 0", $fake ) ) ); // phpcs:ignore WordPress.DB

// 3. 告警规则（用店铺状态的样子来测）。
$now = time();
Stats::store(
	$fake,
	array_map(
		static fn( $i ) => array(
			'day'      => wp_date( 'Y-m-d', $now - $i * DAY_IN_SECONDS ),
			'orders'   => 3,
			'revenue'  => 150.5,
			'visitors' => 40,
		),
		range( 1, 7 )
	),
	'USD'
);
$status = array(
	'domains'     => array(
		array( 'host' => 'smoke-shop.com', 'conn' => 'failed', 'https' => 'checking' ),
		array( 'host' => 'www.smoke-shop.com', 'conn' => 'connected', 'https' => 'failed' ),
		array( 'host' => 'dev.smoke.local', 'conn' => 'failed', 'https' => 'failed' ),
	),
	'last_order'  => $now - 3 * DAY_IN_SECONDS,
	'coming_soon' => true,
);
Monitor::check_rules( $fake, $status, $now );
$types = $wpdb->get_col( $wpdb->prepare( 'SELECT CONCAT(type, ":", ref) FROM ' . Db::table( 'alerts' ) . ' WHERE site_id = %d AND resolved = 0 ORDER BY type, ref', $fake ) ); // phpcs:ignore WordPress.DB
$ok( 'rules: domain not connected, HTTPS failing, no orders for 72 hours, coming soon; local test domains ignored', array( 'closed:', 'domain:smoke-shop.com', 'https:www.smoke-shop.com', 'no_orders:' ) === $types );
$status['domains']     = array( array( 'host' => 'smoke-shop.com', 'conn' => 'connected', 'https' => 'active' ) );
$status['last_order']  = $now - HOUR_IN_SECONDS;
$status['coming_soon'] = false;
Monitor::check_rules( $fake, $status, $now );
$ok( 'when everything is fine again, the alerts close by themselves', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'alerts' ) . ' WHERE site_id = %d AND resolved = 0', $fake ) ) ); // phpcs:ignore WordPress.DB

// 4. 每日数字。
$ok( 'average orders per day over the last 7 days', abs( Stats::average_orders( $fake, 7 ) - 3.0 ) < 0.001 );
[ $from, $to ] = Stats::range( '7d' );
$per           = Stats::per_site( $from, $to )[ $fake ] ?? null;
$ok( 'per-store totals for the last 7 days (6 stored days fall inside)', $per && 18 === $per['orders'] && abs( $per['revenue'] - 903.0 ) < 0.01 && 240 === $per['visitors'] && 'USD' === $per['currency'] );
$days = Stats::per_day( $from, $to, 'USD' );
$ok( 'per-day series lists every day, including days without sales', 7 === count( $days ) && 0 === $days[ $to ]['orders'] && abs( $days[ wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ) ]['revenue'] - 150.5 ) < 0.01 );
Stats::store( $fake, array( array( 'day' => wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ), 'orders' => 5, 'revenue' => 10, 'visitors' => 1 ) ), 'USD' );
$ok( 'the same day is overwritten, not added twice', 5 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT orders FROM ' . Db::table( 'daily' ) . ' WHERE site_id = %d AND day = %s', $fake, wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ) ) ) ); // phpcs:ignore WordPress.DB

// 5. 真实店铺。
$sites = Sites::all();
$real  = $sites ? (int) array_key_first( $sites ) : 0;
if ( $real ) {
	$ok( 'the connected store answers a real check (online)', Monitor::poll( $real ) && 'online' === Sites::get( $real )['status'] );
	$denied = Client::request_raw( $sites[ $real ]['url'], str_repeat( 'c3', 32 ), 'GET', '/peptide/v1/hq/status' );
	$ok( 'the store refuses a wrong key', is_wp_error( $denied ) && 'pchq_denied' === $denied->get_error_code() );
	$login = Client::login( $real );
	$ok( 'one-click login returns a link on the store itself', is_array( $login ) && wp_parse_url( (string) $login['url'], PHP_URL_HOST ) === wp_parse_url( $sites[ $real ]['url'], PHP_URL_HOST ) );
	// 分配服务器 → 店铺马上拿到 IP；还原成原来的分配。
	$before_server = $sites[ $real ]['server_id'];
	$tmp           = \PeptideHq\Servers::save( 0, 'Smoke push', '198.51.100.30', '' );
	$push          = \PeptideHq\Servers::assign( $real, $tmp );
	$store_conf    = Client::status( $real );
	$ok( 'assigning a server sends its IP to the store right away (real request)', true === $push && is_array( $store_conf ) && '198.51.100.30' === $store_conf['config']['server_ip'] );
	\PeptideHq\Servers::assign( $real, $before_server );
	\PeptideHq\Servers::delete( $tmp );
	$back_conf = Client::status( $real );
	$ok( 'the original assignment is restored on the store', is_array( $back_conf ) && \PeptideHq\Servers::ip_for_site( $real ) === $back_conf['config']['server_ip'] );
	$cfg = Client::config( $real, array( 'blog_public' => ! empty( $store_conf['config']['blog_public'] ) ) );
	$ok( 'store settings can be read and written through config', is_array( $cfg ) && isset( $cfg['tracking']['enabled'], $cfg['server_ip'] ) );
} else {
	echo "SKIP no connected store for the real checks\n";
}
$off = Client::request_raw( 'http://pc-smoke-offline.invalid/', str_repeat( 'd4', 32 ), 'GET', '/peptide/v1/hq/status' );
$ok( 'an address that cannot be reached gives the reason', is_wp_error( $off ) && 'pchq_offline' === $off->get_error_code() );

// 清理。
$wpdb->delete( Db::table( 'alerts' ), array( 'site_id' => $fake ) ); // phpcs:ignore WordPress.DB
$wpdb->delete( Db::table( 'daily' ), array( 'site_id' => $fake ) ); // phpcs:ignore WordPress.DB
$ok( 'test data removed', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'daily' ) . ' WHERE site_id = %d', $fake ) ) ); // phpcs:ignore WordPress.DB
