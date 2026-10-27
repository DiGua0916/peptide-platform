<?php
/**
 * 域名管理（照 YY 后台，2026-10-27）冒烟测试。开发环境专用。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-domains.php
 * 测试域名用保留后缀 .example（不会真的解析）；外部请求用 pre_http_request 模拟，
 * 只有“检查本站地址”是真实请求。主域名切换的写入被拦下，不会改网站地址。结束时恢复域名设置。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Admin\Domains_Page;
use PeptideCore\Domains;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$orig      = get_option( Domains::OPTION, null );
$orig_host = $_SERVER['HTTP_HOST'] ?? null;
$orig_get  = $_GET;
$orig_home = get_option( 'home' );
$root      = 'pc-smoke.example';
$www       = 'www.pc-smoke.example';
$site      = Domains::primary_host();

// 1. 域名规则。
$ok( 'root domain: subdomains and two-part suffixes', 'example.com' === Domains::root( 'shop.example.com' ) && 'example.co.uk' === Domains::root( 'www.example.co.uk' ) && 'example.com' === Domains::root( 'example.com' ) );
$ok( 'host record: @ for the root, the prefix for subdomains', '@' === Domains::record_name( 'example.com' ) && 'shop' === Domains::record_name( 'shop.example.com' ) && 'www' === Domains::record_name( 'www.example.co.uk' ) );
$ok( 'www pair: root ↔ www, other subdomains have none', 'www.example.com' === Domains::pair( 'example.com' ) && 'example.com' === Domains::pair( 'www.example.com' ) && '' === Domains::pair( 'shop.example.com' ) );
$ok( 'input must be a domain', is_wp_error( Domains::expand( array( 'not a domain' ), false ) ) && is_wp_error( Domains::expand( array( 'localhost' ), false ) ) && is_wp_error( Domains::expand( array( '', ' ' ), false ) ) );
$ok( 'by default the www pair is bound too; “only” binds just the input', array( $root, $www ) === Domains::expand( array( 'https://PC-Smoke.example/path' ), false ) && array( $root ) === Domains::expand( array( $root ), true ) );
$ok( 'several domains at once, duplicates dropped', array( $root, $www, 'shop.pc-smoke.example' ) === Domains::expand( array( $root, $www, 'shop.pc-smoke.example' ), false ) );

// 2. 添加。
delete_option( Domains::OPTION );
Domains::ensure_primary();
$added = Domains::add( Domains::expand( array( $root ), false ), 'cloudflare' );
$items = Domains::items();
$ok( 'the site address is listed first and both domains are added with the chosen CDN', isset( $items[ $site ] ) && 1 === $items[ $site ]['id'] && array( $root, $www ) === $added && 2 === $items[ $root ]['id'] && 3 === $items[ $www ]['id'] && 'cloudflare' === $items[ $www ]['cdn'] );
$ok( 'new domains start as “checking”', 'checking' === $items[ $root ]['conn'] && 'checking' === $items[ $root ]['https'] );
$ok( 'adding bound domains again is refused', is_wp_error( Domains::add( array( $root, $www ), 'none' ) ) );
$full          = get_option( Domains::OPTION );
$saved_full    = $full;
for ( $i = 0; $i < Domains::MAX - 3; $i++ ) {
	$full['items'][ "d{$i}.pc-fill.example" ] = array( 'id' => 100 + $i, 'host' => "d{$i}.pc-fill.example" );
}
update_option( Domains::OPTION, $full, true );
$limit = Domains::add( array( 'one.pc-more.example' ), 'none' );
$ok( 'at most 100 domains', is_wp_error( $limit ) && 'pc_domain_limit' === $limit->get_error_code() );
update_option( Domains::OPTION, $saved_full, true );
$ok( 'a cron check is scheduled', (bool) wp_next_scheduled( Domains::CRON ) );

// 3. 解析记录和服务器 IP。
$ok( 'server IP must be an IP address', is_wp_error( Domains::save_server_ip( 'my-server' ) ) );
Domains::save_server_ip( '203.0.113.10' );
$ok( 'root domain: A @ → server IP', array( 'type' => 'A', 'name' => '@', 'value' => '203.0.113.10' ) === Domains::record( $root, 'none' ) );
$ok( 'www with its root bound: CNAME www → root', array( 'type' => 'CNAME', 'name' => 'www', 'value' => $root ) === Domains::record( $www, 'cloudflare' ) );
$ok( 'other CDN: CNAME to the address the CDN gives', 'CNAME' === Domains::record( $root, 'other' )['type'] && '' === Domains::record( $root, 'other' )['value'] );

// 4. 检查：本站地址是真实请求；测试域名用模拟的回应。
$res = wp_remote_get( add_query_arg( Domains::CHECK_ARG, 'x', home_url( '/' ) ), array( 'timeout' => 20 ) );
$ok( 'the store answers the check with its code for the domain', Domains::token( $site ) === trim( (string) wp_remote_retrieve_body( $res ) ) && 32 === strlen( Domains::token( $site ) ) && Domains::token( $site ) !== Domains::token( $root ) );
$real = Domains::check( $site );
$ok( 'checking the site address for real: connected', $real && 'connected' === $real['conn'] && $real['checked'] > 0 );

$mock = static function ( $pre, $args, $url ) use ( $root, $www ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! in_array( $host, array( $root, $www ), true ) ) {
		return $pre;
	}
	if ( str_starts_with( $url, 'https://' ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem' );
	}
	$body = $host === $root ? Domains::token( $root ) : '<html>another shop</html>';
	return array(
		'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $host === $root ? array( 'cf-ray' => 'abc-LAX', 'server' => 'cloudflare' ) : array( 'server' => 'nginx' ) ),
		'body'     => $body,
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$r = Domains::check( $root );
$ok( 'a domain that returns this store’s code is connected; Cloudflare is detected', 'connected' === $r['conn'] && 'cloudflare' === $r['cdn'] && '' === $r['error'] );
$ok( 'HTTPS that fails stays “checking” during the first 7 days', 'checking' === $r['https'] && str_contains( $r['https_error'], 'SSL' ) );
$w = Domains::check( $www );
$ok( 'a domain that opens another website is not connected yet', 'checking' === $w['conn'] && '' !== $w['error'] );
$data                            = get_option( Domains::OPTION );
$data['items'][ $www ]['created'] = time() - 8 * DAY_IN_SECONDS;
update_option( Domains::OPTION, $data, true );
$w = Domains::check( $www );
$ok( 'after 7 days it shows “not connected” (kept, not deleted)', 'failed' === $w['conn'] && 'failed' === $w['https'] && isset( Domains::items()[ $www ] ) );
remove_filter( 'pre_http_request', $mock, 10 );

$now = time();
$ok( 'new domains not yet working are checked hourly', Domains::due( array( 'created' => $now - DAY_IN_SECONDS, 'checked' => $now - HOUR_IN_SECONDS, 'conn' => 'connected', 'https' => 'checking' ), $now ) );
$ok( 'working or older domains are checked daily', ! Domains::due( array( 'created' => $now - DAY_IN_SECONDS, 'checked' => $now - HOUR_IN_SECONDS, 'conn' => 'connected', 'https' => 'active' ), $now ) && Domains::due( array( 'created' => $now - 9 * DAY_IN_SECONDS, 'checked' => $now - DAY_IN_SECONDS, 'conn' => 'failed', 'https' => 'failed' ), $now ) );

// 5. 访客打开已绑定的域名时，链接留在这个域名上。
$_SERVER['HTTP_HOST'] = $www;
$ok( 'on a bound domain, home_url uses that domain', 'http://' . $www . '/shop/' === home_url( '/shop/' ) && str_starts_with( admin_url(), 'http://' . $www . '/' ) );
$ok( 'the stored site address is not affected', $site === Domains::primary_host() );
$_SERVER['HTTP_HOST'] = $www . ':8080';
$ok( 'a port in the request is kept', 'http://' . $www . ':8080/' === home_url( '/' ) );
$_SERVER['HTTP_HOST']              = $www;
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$ok( 'behind an https proxy the links use https', 'https://' . $www . '/' === home_url( '/' ) );
unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
$_SERVER['HTTP_HOST'] = 'evil.pc-other.example';
$ok( 'an unbound domain does not change the links', $orig_home === get_option( 'home' ) );
$_SERVER['HTTP_HOST'] = $site;

// 6. 域名跳转、http → https。
$ok( 'a domain cannot redirect to itself or to an unbound domain', is_wp_error( Domains::set_redirect( $www, $www ) ) && is_wp_error( Domains::set_redirect( $www, 'nope.pc-other.example' ) ) );
$ok( 'the primary domain cannot redirect', is_wp_error( Domains::set_redirect( $site, $root ) ) );
$ok( 'www → root is saved', true === Domains::set_redirect( $www, $root ) && $root === Domains::items()[ $www ]['redirect'] );
$ok( 'redirects never chain', is_wp_error( Domains::set_redirect( $root, $www ) ) && is_wp_error( Domains::set_redirect( 'shop.pc-smoke.example', $www ) ) );
$ok( 'a visit to www goes to the same page on the root domain', 'http://' . $root . '/shop/?a=1' === Domains::redirect_target( $www, '/shop/?a=1', false ) );
$ok( 'a protocol-relative path cannot leave the domain', 'http://' . $root . '/evil.example/x' === Domains::redirect_target( $www, '//evil.example/x', false ) );
$data                           = get_option( Domains::OPTION );
$data['items'][ $root ]['https'] = 'active';
update_option( Domains::OPTION, $data, true );
$ok( 'with HTTPS in effect, http visits go to https and redirects use https', 'https://' . $root . '/a' === Domains::redirect_target( $root, '/a', false ) && null === Domains::redirect_target( $root, '/a', true ) && 'https://' . $root . '/a' === Domains::redirect_target( $www, '/a', false ) );
$ok( 'no redirect for unbound domains or domains without rules', null === Domains::redirect_target( 'evil.pc-other.example', '/', false ) && null === Domains::redirect_target( $site, '/', false ) );

// 7. 主域名（写入被拦下，不会真的改网站地址）。
$written = array();
$block   = static function ( $value, $old, $option ) use ( &$written ) {
	$written[ $option ] = $value;
	return $old;
};
add_filter( 'pre_update_option_home', $block, 10, 3 );
add_filter( 'pre_update_option_siteurl', $block, 10, 3 );
$ok( 'a domain that is not connected cannot become primary', is_wp_error( Domains::set_primary( $www ) ) );
$ok( 'a connected domain becomes primary: home and siteurl switch, https when in effect', true === Domains::set_primary( $root ) && 'https://' . $root === ( $written['home'] ?? '' ) && str_starts_with( (string) ( $written['siteurl'] ?? '' ), 'https://' . $root ) );
remove_filter( 'pre_update_option_home', $block, 10 );
remove_filter( 'pre_update_option_siteurl', $block, 10 );
$ok( 'the real site address was not changed by the test', $orig_home === get_option( 'home' ) );

// 8. 删除。
$ok( 'the primary domain cannot be deleted', 0 === Domains::remove( array( $site ) ) && isset( Domains::items()[ $site ] ) );
$ok( 'deleting a domain also clears redirects to it', 1 === Domains::remove( array( $root ) ) && '' === Domains::items()[ $www ]['redirect'] );

// 9. 后台页面。
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
$render = static function ( array $get ): string {
	$_GET = $get;
	ob_start();
	Domains_Page::render();
	return (string) ob_get_clean();
};
$html = $render( array( 'page' => Domains_Page::SLUG ) );
$ok( 'list: table with the site address as primary, switch, statuses and add button', str_contains( $html, 'pc-dm-table' ) && str_contains( $html, 'pc-dm-switch is-on' ) && str_contains( $html, '>' . $www . '</a>' ) && str_contains( $html, 'pc-dm-badge' ) && str_contains( $html, 'add=1' ) && str_contains( $html, 'name="server_ip"' ) );
$html = $render( array( 'page' => Domains_Page::SLUG, 'https' => 'active' ) );
$ok( 'list filters by HTTPS status', ! str_contains( $html, '>' . $www . '</a>' ) );
$html = $render( array( 'page' => Domains_Page::SLUG, 'redirect' => $www ) );
$ok( 'redirect panel offers other domains', str_contains( $html, 'pc_domain_redirect' ) && str_contains( $html, 'value="' . $site . '"' ) );
$html = $render( array( 'page' => Domains_Page::SLUG, 'add' => '1' ) );
$ok( 'wizard step 1: domain field and “only bind” option', str_contains( $html, 'pc-dm-steps' ) && str_contains( $html, 'name="only"' ) && str_contains( $html, 'name="batch"' ) );
$html = $render( array( 'page' => Domains_Page::SLUG, 'add' => '1', 'step' => '2', 'domain' => 'Shop2-Smoke.example' ) );
$ok( 'wizard step 2: both domains and the CDN choices', str_contains( $html, 'value="shop2-smoke.example"' ) && str_contains( $html, 'value="www.shop2-smoke.example"' ) && 3 === substr_count( $html, 'name="cdn"' ) );
$html = $render( array( 'page' => Domains_Page::SLUG, 'add' => '1', 'step' => '2', 'domain' => 'bad domain' ) );
$ok( 'wizard step 2 with a bad domain goes back to step 1 with the error', str_contains( $html, 'notice-error' ) && str_contains( $html, 'name="only"' ) );
$html = $render( array( 'page' => Domains_Page::SLUG, 'add' => '1', 'step' => '3', 'ids' => (string) Domains::items()[ $www ]['id'] ) );
$ok( 'wizard step 3: the DNS record to add and a check button', str_contains( $html, 'pc-dm-records' ) && str_contains( $html, '203.0.113.10' ) && str_contains( $html, 'pc_domain_check' ) );
$html = $render( array( 'page' => Domains_Page::SLUG, 'add' => '1', 'step' => '4', 'ids' => (string) Domains::items()[ $www ]['id'] ) );
$ok( 'wizard step 4: done', str_contains( $html, 'pc-dm-done' ) && str_contains( $html, $www ) );
wp_set_current_user( 0 );

// 清理。
$_GET = $orig_get;
if ( null === $orig ) {
	delete_option( Domains::OPTION );
} else {
	update_option( Domains::OPTION, $orig, true );
}
if ( null === $orig_host ) {
	unset( $_SERVER['HTTP_HOST'] );
} else {
	$_SERVER['HTTP_HOST'] = $orig_host;
}
$ok( 'domain settings restored', $orig === get_option( Domains::OPTION, null ) && $orig_home === get_option( 'home' ) );
