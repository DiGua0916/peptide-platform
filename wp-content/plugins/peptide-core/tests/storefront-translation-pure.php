<?php
/** Actual resolver/locale adapter with isolated WordPress boundaries; no bootstrap, HTTP or business writes. */
declare( strict_types=1 );

namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 2 ); }
	define( 'ABSPATH', __DIR__ );
	define( 'WP_LANG_DIR', 'D:/SyntheticStorefrontLanguages' );
	class WP_Error { public function __construct( ...$unused ) {} }
	class WP_REST_Request {
		public function __construct( private string $method = 'GET', private string $route = '/peptide/v1/site' ) {}
		public function get_method(): string { return $this->method; }
		public function get_route(): string { return $this->route; }
	}
	class WP_REST_Response {
		public function __construct( private array $headers = array() ) {}
		public function get_headers(): array { return $this->headers; }
		public function set_headers( array $headers ): void { $this->headers = $headers; }
	}
	$GLOBALS['checks_translation'] = 0;
	$GLOBALS['forbidden_translation'] = 0;
	function reset_translation( array $changes = array(), string $uri = '/', array $query = array(), array $cookies = array() ): void {
		$GLOBALS['t'] = array_replace( array( 'enabled' => true, 'settings' => array( 'enabled' => false, 'default_language' => 'en', 'us_language' => 'en', 'pr_language' => 'es' ), 'locale' => 'en_US', 'languages' => array( 'es_ES' ), 'core' => true, 'woo' => true, 'json' => true, 'switch_fails' => false, 'ssl' => true, 'admin' => false, 'staff' => false, 'management' => false, 'cron' => false, 'headers_sent' => false, 'wp_ran' => false, 'conditional' => false, 'home_path' => '/', 'country' => 'PR', 'constants' => array(), 'option_reads' => array(), 'switches' => array(), 'cookie_writes' => array(), 'headers' => array(), 'geo_calls' => array(), 'hooks' => array(), 'conditional_calls' => 0 ), $changes );
		$_GET = $query; $_COOKIE = $cookies;
		$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_HOST' => 'store.example.test' );
		$reflection = new \ReflectionClass( \PeptideCore\Storefront_Translation::class );
		foreach ( array( 'registered' => false, 'attempted' => false, 'handled' => false, 'scope' => '', 'enabled_cache' => null, 'resource_cache' => array(), 'state' => array( 'requested_language' => 'en', 'language' => 'en', 'locale' => 'en_US', 'source' => 'disabled', 'country_code' => null, 'active' => false, 'applied' => false, 'reason' => 'not_initialized' ) ) as $key => $value ) { $reflection->getProperty( $key )->setValue( null, $value ); }
	}
	function check_translation( string $name, bool $condition ): void { if ( ! $condition ) { throw new \RuntimeException( 'FAIL ' . $name ); } ++$GLOBALS['checks_translation']; }
	function get_option( $name, $default = false ) {
		$GLOBALS['t']['option_reads'][] = $name;
		return match ( $name ) {
			'pc_storefront_translation_enabled' => $GLOBALS['t']['enabled'],
			'pc_storefront_language_settings' => $GLOBALS['t']['settings'],
			default => throw new \RuntimeException( 'Unexpected option read' ),
		};
	}
	function determine_locale(): string { return $GLOBALS['t']['determine_override'] ?? $GLOBALS['t']['locale']; }
	function get_locale(): string { return $GLOBALS['t']['locale']; }
	function switch_to_locale( $locale ): bool { $GLOBALS['t']['switches'][] = $locale; if ( $GLOBALS['t']['switch_fails'] ) { return false; } $GLOBALS['t']['locale'] = $locale; return true; }
	function get_available_languages(): array { return $GLOBALS['t']['languages']; }
	function is_admin(): bool { return $GLOBALS['t']['admin']; }
	function current_user_can( ...$unused ): bool { return $GLOBALS['t']['staff']; }
	function wp_doing_cron(): bool { return $GLOBALS['t']['cron']; }
	function did_action( $name ): int { return 'wp' === $name && $GLOBALS['t']['wp_ran'] ? 1 : 0; }
	function is_preview(): bool { ++$GLOBALS['t']['conditional_calls']; if ( ! $GLOBALS['t']['wp_ran'] ) { throw new \RuntimeException( 'Early main-query conditional' ); } return $GLOBALS['t']['conditional']; }
	function is_customize_preview(): bool { return false; }
	function is_feed(): bool { return false; }
	function is_trackback(): bool { return false; }
	function is_robots(): bool { return false; }
	function is_favicon(): bool { return false; }
	function is_ssl(): bool { return $GLOBALS['t']['ssl']; }
	function wp_parse_url( $value, $part = -1 ) { return parse_url( $value, $part ); }
	function home_url( $unused = '/' ): string { return 'https://store.example.test' . $GLOBALS['t']['home_path']; }
	function add_action( ...$args ): void { $GLOBALS['t']['hooks'][] = $args; }
	function add_filter( ...$args ): void { $GLOBALS['t']['hooks'][] = $args; }
	function forbidden_translation( ...$unused ) { ++$GLOBALS['forbidden_translation']; throw new \RuntimeException( 'Write or external lookup forbidden' ); }
	function update_option( ...$args ) { return forbidden_translation(); }
	function set_transient( ...$args ) { return forbidden_translation(); }
	function wp_remote_get( ...$args ) { return forbidden_translation(); }
	function wp_remote_post( ...$args ) { return forbidden_translation(); }
	function wp_redirect( ...$args ) { return forbidden_translation(); }
	function wp_safe_redirect( ...$args ) { return forbidden_translation(); }
}

namespace PeptideCore {
	function defined( $name ): bool { return array_key_exists( $name, $GLOBALS['t']['constants'] ?? array() ) ? true : \defined( $name ); }
	function constant( $name ) { return $GLOBALS['t']['constants'][ $name ] ?? \constant( $name ); }
	function is_readable( $path ): bool {
		if ( str_contains( $path, 'woocommerce-es_ES-' ) && str_ends_with( $path, '.json' ) ) { return $GLOBALS['t']['json']; }
		if ( str_contains( $path, 'woocommerce-es_ES.' ) ) { return $GLOBALS['t']['woo']; }
		return str_contains( $path, '/es_ES.' ) && $GLOBALS['t']['core'];
	}
	function glob( $pattern ): array { return $GLOBALS['t']['json'] ? array( 'D:/SyntheticStorefrontLanguages/plugins/woocommerce-es_ES-synthetic.json' ) : array(); }
	function headers_sent(): bool { return $GLOBALS['t']['headers_sent']; }
	function header( $line, $replace = true ): void { $GLOBALS['t']['headers'][] = array( $line, $replace ); }
	function setcookie( $name, $value, $options ): bool { $GLOBALS['t']['cookie_writes'][] = array( $name, $value, $options ); return true; }
	function time(): int { return 1700000000; }
	final class Management_Host { public static function is_management_request(): bool { return $GLOBALS['t']['management']; } }
}

namespace PeptideCore\Tracking {
	final class Country_Provider {
		public static function lookup( $address ): array { $GLOBALS['t']['geo_calls'][] = $address; return is_string( $GLOBALS['t']['country'] ) ? array( 'status' => 'known', 'country_code' => $GLOBALS['t']['country'] ) : array( 'status' => 'unavailable' ); }
	}
}

namespace {
	require dirname( __DIR__ ) . '/includes/class-storefront-language.php';
	require dirname( __DIR__ ) . '/includes/class-storefront-translation.php';
	use PeptideCore\Storefront_Translation as T;
	use PeptideCore\Storefront_Language as L;

	reset_translation();
	check_translation( 'uninitialized APIs stable and read-free', T::selected() === T::selected() && 'en' === T::code() && 'en_US' === T::locale() && ! T::active() && array() === $t['option_reads'] );
	T::register_hooks(); T::register_hooks();
	check_translation( 'hooks registered once with init20', 6 === count( $t['hooks'] ) && 'init' === $t['hooks'][0][0] && 20 === $t['hooks'][0][2] );
	foreach ( array( false, 0, '0', 'yes', 'true', null, array() ) as $value ) {
		reset_translation( array( 'enabled' => $value ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'opt-in is strict and disabled does not set locale/cookie', ! T::active() && array() === $t['switches'] && array() === $t['cookie_writes'] && array() === $t['geo_calls'] );
	}
	foreach ( array( true, 1, '1' ) as $value ) {
		reset_translation( array( 'enabled' => $value ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'manual Spanish works independently of old automatic-detection switch', T::active() && 'es' === T::code() && 'es_ES' === T::locale() && T::selected()['applied'] && 'manual_url' === T::selected()['source'] && array( 'es_ES' ) === $t['switches'] && array() === $t['geo_calls'] );
	}
	$cookie = $t['cookie_writes'][0];
	check_translation( 'manual preference is host-only 90 days Lax with TLS and JS visibility', L::PREFERENCE_COOKIE === $cookie[0] && 'es' === $cookie[1] && array( 'expires' => 1707776000, 'path' => '/', 'secure' => true, 'httponly' => false, 'samesite' => 'Lax' ) === $cookie[2] && ! isset( $cookie[2]['domain'] ) );
	reset_translation( array( 'ssl' => false ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'local HTTP preference does not require an unusable Secure flag', false === $t['cookie_writes'][0][2]['secure'] );
	reset_translation( array(), '/?pc_lang=es', array( 'pc_lang' => 'es' ), array( L::PREFERENCE_COOKIE => 'en' ) ); T::apply();
	check_translation( 'manual URL wins over previous manual cookie', 'es' === T::code() && 'manual_url' === T::selected()['source'] && 1 === count( $t['cookie_writes'] ) );
	reset_translation( array(), '/?pc_lang=es&pc_lang_auto=1', array( 'pc_lang' => 'es', 'pc_lang_auto' => '1' ) ); T::apply();
	check_translation( 'automatic URL does not become a saved manual preference', 'es' === T::code() && 'auto_url' === T::selected()['source'] && array() === $t['cookie_writes'] );
	reset_translation( array(), '/?pc_lang=es&pc_lang_auto=1', array( 'pc_lang' => 'es', 'pc_lang_auto' => '1' ), array( L::PREFERENCE_COOKIE => 'en' ) ); T::apply();
	check_translation( 'manual cookie wins over stale automatic URL', 'en' === T::code() && 'manual' === T::selected()['source'] && array() === $t['cookie_writes'] );
	reset_translation( array(), '/', array(), array( L::PREFERENCE_COOKIE => 'es' ) ); T::apply();
	check_translation( 'valid manual cookie works without any new cookie or IP lookup', 'es' === T::code() && 'manual' === T::selected()['source'] && array() === $t['cookie_writes'] && array() === $t['geo_calls'] );
	foreach ( array( null, false, 1, array( 'es' ), 'es_ES', 'ES', 'es ', '../es', 'https://other.invalid' ) as $value ) {
		reset_translation( array(), '/?pc_lang=invalid', array( 'pc_lang' => $value ) ); T::apply();
		check_translation( 'invalid language query never writes a preference or redirects', 'en' === T::code() && array() === $t['cookie_writes'] && array() === $t['geo_calls'] );
	}
	foreach ( array( 'PR' => 'es', 'US' => 'en', 'DE' => 'en' ) as $country => $language ) {
		reset_translation( array( 'settings' => array( 'enabled' => true, 'default_language' => 'en', 'us_language' => 'en', 'pr_language' => 'es' ), 'country' => $country ) );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'US'; $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-address'; $_GET['country'] = 'US';
		T::apply();
		check_translation( 'existing country mapping reads only canonical address', $language === T::code() && array( $_SERVER['REMOTE_ADDR'] ) === $t['geo_calls'] && array() === $t['cookie_writes'] );
	}
	reset_translation( array( 'settings' => array( 'enabled' => true, 'default_language' => 'en', 'us_language' => 'en', 'pr_language' => 'es' ), 'country' => null ) ); T::apply();
	check_translation( 'unavailable MMDB stays at configured default', 'en' === T::code() && 'default' === T::selected()['source'] );
	foreach ( array( 'core', 'woo', 'json' ) as $missing ) {
		reset_translation( array( $missing => false ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'missing native resources do not pretend Spanish is active', ! T::active() && 'en' === T::code() && 'es' === T::selected()['requested_language'] && 'resources_unavailable' === T::selected()['reason'] && array() === $t['switches'] && ! T::packages_ready() );
	}
	reset_translation( array( 'languages' => array( 'es_PR' ) ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'old es_PR metadata is never treated as full es_ES resources', ! T::active() && array() === $t['switches'] );
	reset_translation( array( 'switch_fails' => true ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'native locale-switch failure is honest and leaves optional translator inactive', ! T::active() && 'locale_switch_failed' === T::selected()['reason'] );
	reset_translation( array( 'determine_override' => 'zh_CN' ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'actual locale verification tolerates a prior REST user-locale result', T::active() && 'es_ES' === T::locale() && 'es_ES' === get_locale() );
	check_translation( 'owned script catalog locale follows actual selected locale', 'es_ES' === T::filter_locale( 'zh_CN' ) );
	$t['locale'] = 'en_US';
	check_translation( 'nested native mail locale switch is never forced back to Spanish', 'en_US' === T::filter_locale( 'en_US' ) );
	$t['locale'] = 'es_ES'; $t['admin'] = true;
	check_translation( 'late admin context retains its own user locale', 'zh_CN' === T::filter_locale( 'zh_CN' ) );
	reset_translation();
	check_translation( 'package-readiness API is available before translation activation', T::packages_ready() && ! T::active() );
	$t['json'] = false;
	check_translation( 'resource readiness is request-memoized', T::packages_ready() );
	check_translation( 'enabled API reads once per request', T::enabled() && 1 === count( $t['option_reads'] ) );
	$t['enabled'] = false;
	check_translation( 'enabled memo does not change in the middle of a request', T::enabled() && 1 === count( $t['option_reads'] ) );
	check_translation( 'unknown translation resource language is never accepted', ! T::resources_ready( 'es_PR' ) && ! T::resources_ready( '../es' ) );
	reset_translation( array( 'locale' => 'es_ES' ), '/', array(), array( L::PREFERENCE_COOKIE => 'es' ) ); T::apply();
	check_translation( 'already-correct native locale is successful without another switch', T::active() && 'es' === T::code() && array() === $t['switches'] );
	$before = T::selected(); $_GET = array( 'pc_lang' => 'en' ); T::apply();
	check_translation( 'late inner request cannot change the request owner selection', $before === T::selected() && array() === $t['switches'] );
	foreach ( array( '/wp-admin/', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wc-api/pc_panapay_return/', '/wp-json/pc-panapay/v1/hub/return', '/wp-json/wp/v2/pages', '/wp-json/peptide/v1/layouts/1', '/wp-json/peptide/v1/track/admin/stats', '/wp-json/peptide/v1/product-broadcast/admin/state', '/wp-json/vialcove/v1/unknown', '/wp-json/peptide/v1/unknown', '/feed/', '/wp-sitemap.xml', '/robots.txt', '/%77p-admin/options-general.php', '/wp-admin%2foptions-general.php', '/%252fwp-admin/', '/foo/../cart/', '//other.invalid/cart/', 'http:/cart/', 'https://other.invalid/cart/' ) as $uri ) {
		reset_translation( array(), $uri, array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'excluded or malformed route has no locale/cookie/provider side effect', ! T::active() && array() === $t['switches'] && array() === $t['cookie_writes'] && array() === $t['geo_calls'] );
	}
	foreach ( array( 'wc-api', 'wc_api', 'wc-ajax', 'key', 'order_key', 'pay_for_order', 'order-pay', 'order-received', 'pc_panapay_return', 'pc_payment_return', 'payment-return', '_wpnonce', 'preview', 'pc_template_preview', 'pc_preview_token', 'pc_frame', 'pc_design_document', 'feed' ) as $key ) {
		reset_translation( array(), '/', array( 'pc_lang' => 'es', $key => '1' ) ); T::apply();
		check_translation( 'callback/editor query is excluded before any resolver action', ! T::active() && array() === $t['switches'] && array() === $t['cookie_writes'] );
	}
	foreach ( array( '/checkout/order-pay/123/', '/checkout/order-received/123/', '/custom-checkout/order-pay/123/', '/custom-checkout/order-received/123/' ) as $uri ) {
		reset_translation( array(), $uri, array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'payment and receipt endpoints bypass translation even without query signatures', ! T::active() && array() === $t['switches'] && array() === $t['cookie_writes'] && array() === $t['geo_calls'] );
	}
	reset_translation( array(), '/checkout/', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'ordinary unsigned checkout document can still render Spanish', T::active() && 'es' === T::code() && 'es_ES' === get_locale() );
	reset_translation( array(), '/', array( 'pc_lang' => 'es', 'page' => 'pc-design-editor' ) ); T::apply();
	check_translation( 'explicit editor page query is excluded', ! T::active() && array() === $t['switches'] );
	reset_translation( array( 'staff' => true ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'administrator can manually preview the ordinary public storefront', T::active() && 'es' === T::code() && 'es_ES' === get_locale() && 'manual_url' === T::selected()['source'] && 1 === count( $t['cookie_writes'] ) );
	reset_translation( array( 'staff' => true, 'admin' => true ), '/wp-admin/', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'administrator backend retains its own locale without a preference write', ! T::active() && 'en_US' === get_locale() && array() === $t['switches'] && array() === $t['cookie_writes'] );
	foreach ( array( 'admin', 'management', 'cron', 'headers_sent' ) as $flag ) {
		reset_translation( array( $flag => true ), '/?pc_lang=es', array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'excluded execution context has no locale/cookie/provider side effect', ! T::active() && array() === $t['switches'] && array() === $t['cookie_writes'] && array() === $t['geo_calls'] );
	}
	foreach ( array( 'WP_CLI', 'DOING_CRON', 'XMLRPC_REQUEST', 'WP_INSTALLING' ) as $constant ) {
		reset_translation( array( 'constants' => array( $constant => true ) ), '/', array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'CLI/cron/XMLRPC/install guard stays outside storefront translation', ! T::active() && array() === $t['switches'] );
	}
	foreach ( array( '/cart/', '/checkout/', '/wp-json/wc/store/v1/products', '/wp-json/peptide/v1/site' ) as $uri ) {
		reset_translation( array(), $uri, array( 'pc_lang' => 'es' ) ); $_SERVER['REQUEST_METHOD'] = 'POST'; T::apply();
		check_translation( 'POST and payment mutations are not intercepted', ! T::active() && array() === $t['cookie_writes'] && array() === $t['switches'] );
	}
	foreach ( array( '/wp-json/wc/store/v1/products', '/wp-json/wc/store/v1/cart', '/wp-json/peptide/v1/site', '/wp-json/peptide/v1/storefront-language', '/wp-json/vialcove/v1/home-product/11306' ) as $uri ) {
		reset_translation( array(), $uri, array( 'pc_lang' => 'es' ) ); T::apply();
		check_translation( 'public GET REST is recognized before REST_REQUEST exists', T::active() && 'es' === T::code() && ! T::public_document_request() );
	}
	reset_translation( array(), '/index.php?rest_route=/wc/store/v1/products', array( 'rest_route' => '/wc/store/v1/products', 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'plain native REST route is supported', T::active() && ! T::public_document_request() );
	reset_translation( array(), '/wp-json/peptide/v1/site', array( 'rest_route' => '/pc-panapay/v1/hub/return', 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'conflicting pretty and plain REST identities fail closed', ! T::active() && array() === $t['switches'] );
	reset_translation( array(), '/', array( 'rest_route' => array( '/wc/store/v1/products' ), 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'nonscalar REST route fails closed', ! T::active() && array() === $t['switches'] );
	reset_translation( array( 'constants' => array( 'REST_REQUEST' => true ) ), '/', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'internal REST context without owned public HTTP route is not reclassified', ! T::active() && array() === $t['switches'] );
	reset_translation( array( 'home_path' => '/store/' ), '/store/wp-json/peptide/v1/site', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'WordPress subdirectory REST prefix is supported', T::active() );
	reset_translation( array(), '/', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'init selection never calls an early conditional query tag', 0 === $t['conditional_calls'] );
	$headers = T::document_headers( array( 'cache-control' => 'public, max-age=3600', 'Vary' => array( 'Accept-Encoding', 'Origin' ), 'vArY' => 'X-Example', 'Content-Type' => 'text/html', 'X-Other' => 'preserved' ) );
	check_translation( 'public HTML replaces shared caching and preserves all Vary tokens', 'private, no-store, max-age=0' === $headers['Cache-Control'] && 'Accept-Encoding, Origin, X-Example, Cookie' === $headers['Vary'] && 'preserved' === $headers['X-Other'] && ! isset( $headers['cache-control'], $headers['vArY'] ) );
	check_translation( 'Vary wildcard survives', '*' === T::document_headers( array( 'Vary' => '*' ) )['Vary'] );
	T::send_document_headers();
	check_translation( 'late HTML header emission appends Vary rather than losing earlier Origin', in_array( array( 'Vary: Cookie', false ), $t['headers'], true ) );
	$request = new WP_REST_Request( 'GET', '/wc/store/v1/products' ); $response = new WP_REST_Response( array( 'Vary' => array( 'Origin', 'Accept-Encoding' ) ) );
	T::rest_headers( $response, null, $request );
	check_translation( 'public REST response has private cache headers with combined dependencies', 'Origin, Accept-Encoding, Cookie' === $response->get_headers()['Vary'] && 'private, no-store, max-age=0' === $response->get_headers()['Cache-Control'] );
	$payment = new WP_REST_Response( array( 'Cache-Control' => 'original-policy' ) ); T::rest_headers( $payment, null, new WP_REST_Request( 'GET', '/pc-panapay/v1/hub/return' ) );
	check_translation( 'nested machine/payment REST response is not modified', array( 'Cache-Control' => 'original-policy' ) === $payment->get_headers() );
	$served = T::serve_rest_headers( true, $response, $request, null );
	check_translation( 'REST transport policy does not replace the core served flag', true === $served );
	reset_translation( array( 'management' => true ), '/', array( 'pc_lang' => 'es' ) ); T::apply();
	$untouched = new WP_REST_Response( array( 'Cache-Control' => 'private-policy' ) ); T::rest_headers( $untouched, null, $request ); T::send_document_headers();
	check_translation( 'loopback public REST from excluded management context does not inherit customer translation', array( 'Cache-Control' => 'private-policy' ) === $untouched->get_headers() && array() === $t['headers'] );
	check_translation( 'excluded management locale filter is transparent', 'zh_CN' === T::filter_locale( 'zh_CN' ) );
	reset_translation( array( 'core' => false ), '/', array( 'pc_lang' => 'es' ) ); T::apply();
	check_translation( 'enabled but unready request still has a private cache boundary', ! T::active() && 'private, no-store, max-age=0' === T::document_headers( array() )['Cache-Control'] );
	check_translation( 'selection never serializes the raw address or request URL', ! str_contains( json_encode( T::selected() ), $_SERVER['REMOTE_ADDR'] ) && ! str_contains( json_encode( T::selected() ), 'pc_lang' ) );
	reset_translation();
	check_translation( 'cookie wrapper cannot be invoked outside an owned enabled request', false === T::write_preference( 'es' ) && array() === $t['cookie_writes'] );
	check_translation( 'all fixtures preserve write/network/redirect boundaries', 0 === $GLOBALS['forbidden_translation'] );
	echo 'PASS ' . $GLOBALS['checks_translation'] . " actual storefront-translation guards; isolated WP boundaries, no live locale/cart/payment/GeoIP acceptance.\n";
}
