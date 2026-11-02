<?php
/** Recent history and management APIs. Customer requests only read Woo products/cart and write one signed cookie. */
namespace PeptideCore\Api;

use PeptideCore\Recent\Config;
use PeptideCore\Recent\Store;
use PeptideCore\Recent\History;

defined( 'ABSPATH' ) || exit;

final class Recently_Viewed_Controller {
	public static function register_hooks(): void { add_action( 'rest_api_init', array( self::class, 'register_routes' ) ); add_filter( 'rest_post_dispatch', array( self::class, 'private_headers' ), 10, 3 ); }
	public static function register_routes(): void {
		register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/recently-viewed', array( 'methods' => 'GET', 'callback' => array( self::class, 'effective' ), 'permission_callback' => '__return_true' ) );
		foreach ( array( 'items' => 'GET', 'record' => 'POST', 'clear' => 'POST' ) as $path => $method ) { register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/recently-viewed/' . $path, array( 'methods' => $method, 'callback' => array( self::class, $path ), 'permission_callback' => array( self::class, 'items' === $path ? 'can_read_history' : 'can_' . $path ) ) ); }
		foreach ( array( '/admin' => array( array( 'GET', 'read' ), array( 'PUT', 'save' ) ), '/publish' => array( array( 'POST', 'publish' ) ) ) as $path => $methods ) {
			$routes = array();
			foreach ( $methods as [ $method, $callback ] ) { $routes[] = array( 'methods' => $method, 'callback' => array( self::class, $callback ), 'permission_callback' => array( self::class, 'can_manage' ) ); }
			register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/recently-viewed' . $path, $routes );
		}
	}
	public static function can_manage( \WP_REST_Request $request ) {
		if ( ! Store::can_manage() ) { return new \WP_Error( 'pc_recently_viewed_forbidden', __( '此账号没有应用配置权限。', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) ); }
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return is_string( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ? true : new \WP_Error( 'pc_recently_viewed_nonce', __( '登录验证已过期，请刷新后重试。', 'peptide-core' ), array( 'status' => 403 ) );
	}
	private static function respond( $data ) {
		if ( is_wp_error( $data ) ) { return $data; }
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}
	private static function query( \WP_REST_Request $request ) {
		foreach ( $request->get_query_params() as $key => $value ) { if ( '_locale' !== $key || ! is_scalar( $value ) ) { return Config::error( (string) $key, 'unsupported_query', '该接口不接受其他店铺、金额或购物车选择参数。' ); } }
		return true;
	}
	private static function body( \WP_REST_Request $request, array $allowed ) {
		$query = self::query( $request ); if ( is_wp_error( $query ) ) { return $query; }
		if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 32768 ) { return Config::error( 'body', 'invalid_format_or_size', '仅接受不超过 32 KiB 的 JSON 配置。' ); }
		$data = $request->get_json_params();
		$shape = json_decode( $request->get_body() );
		if ( ! is_array( $data ) || ! $shape instanceof \stdClass || ! ( $shape->config ?? null ) instanceof \stdClass ) { return Config::error( 'config', 'object_required', '请提供配置对象。' ); }
		foreach ( array_keys( $data ) as $key ) { if ( ! in_array( $key, $allowed, true ) ) { return Config::error( (string) $key, 'unknown_field', '包含未支持的参数，未保存此次修改。' ); } }
		return $data;
	}
	public static function effective( \WP_REST_Request $request ) {
		$query = self::query( $request ); return is_wp_error( $query ) ? $query : self::respond( Store::public_config() );
	}
	public static function read( \WP_REST_Request $request ) {
		$query = self::query( $request ); return is_wp_error( $query ) ? $query : self::respond( Store::admin() );
	}
	private static function write( \WP_REST_Request $request, bool $publish ) {
		$data = self::body( $request, array( 'config', 'expectedRevision' ) ); if ( is_wp_error( $data ) ) { return $data; }
		if ( ! is_int( $data['expectedRevision'] ?? null ) || $data['expectedRevision'] < 0 || $data['expectedRevision'] > 2147483646 ) { return Config::error( 'expectedRevision', 'integer_required', '请重新读取有效配置修订号。' ); }
		return self::respond( Store::save( $data['config'], $data['expectedRevision'], $publish ) );
	}
	public static function save( \WP_REST_Request $request ) { return self::write( $request, false ); }
	public static function publish( \WP_REST_Request $request ) { return self::write( $request, true ); }
	/** No public read may choose another browser, customer, product set or cart. */
	public static function can_read_history( \WP_REST_Request $request ) { return self::same_origin( $request ); }
	public static function can_record( \WP_REST_Request $request ) { return self::mutation_permission( $request, 'record' ); }
	public static function can_clear( \WP_REST_Request $request ) { return self::mutation_permission( $request, 'clear' ); }
	private static function mutation_permission( \WP_REST_Request $request, string $action ) {
		$origin = self::same_origin( $request ); if ( is_wp_error( $origin ) ) { return $origin; }
		$token = $request->get_header( 'X-PC-Recent-Token' );
		return 'POST' === $request->get_method() && is_string( $token ) && History::authorize( $token, $action ) ? true : new \WP_Error( 'pc_recently_viewed_token', '浏览器验证已过期，请重新读取最近浏览后重试。', array( 'status' => 403 ) );
	}
	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return ''; }
		$scheme = strtolower( $parts['scheme'] ); $port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}
	private static function same_origin( \WP_REST_Request $request ) {
		$expected = self::origin( home_url( '/' ) ); $origin = $request->get_header( 'Origin' ); $referer = $request->get_header( 'Referer' ); $site = strtolower( (string) $request->get_header( 'Sec-Fetch-Site' ) );
		$valid = '' !== $expected && ! in_array( $site, array( 'cross-site', 'same-site' ), true );
		if ( $origin ) { $valid = $valid && self::origin( $origin ) === $expected; }
		if ( $referer ) { $valid = $valid && self::origin( $referer ) === $expected; }
		// Ordinary same-origin fetch carries Referer or Fetch Metadata. Bare external scripts/curl are not browser authority.
		$valid = $valid && ( $origin || $referer || 'same-origin' === $site );
		return $valid ? true : new \WP_Error( 'pc_recently_viewed_origin', '最近浏览仅允许本站浏览器访问。', array( 'status' => 403 ) );
	}
	public static function private_headers( $response, $server, \WP_REST_Request $request ) {
		if ( str_starts_with( $request->get_route(), '/peptide/v1/recently-viewed' ) && $response instanceof \WP_REST_Response ) { $response->header( 'Cache-Control', 'private, no-store, max-age=0' ); $response->header( 'Vary', 'Cookie, Origin' ); $response->header( 'X-Content-Type-Options', 'nosniff' ); }
		return $response;
	}
	private static function history_body( \WP_REST_Request $request, array $allowed ) {
		$query = self::query( $request ); if ( is_wp_error( $query ) ) { return $query; }
		if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 1024 ) { return Config::error( 'body', 'invalid_format_or_size', '仅接受不超过 1 KiB 的 JSON 对象。' ); }
		$shape = json_decode( $request->get_body() ); $data = $request->get_json_params();
		if ( ! $shape instanceof \stdClass || ! is_array( $data ) ) { return Config::error( 'body', 'object_required', '请提供 JSON 对象。' ); }
		foreach ( array_keys( $data ) as $key ) { if ( ! in_array( $key, $allowed, true ) ) { return Config::error( (string) $key, 'unknown_field', '不接受客户身份、商品价格或其他附加字段。' ); } }
		return $data;
	}
	public static function items( \WP_REST_Request $request ) {
		$query = self::query( $request ); if ( is_wp_error( $query ) ) { return $query; }
		try { return self::respond( History::listing() ); } catch ( \Throwable $error ) { return new \WP_Error( 'pc_recently_viewed_unavailable', '最近浏览暂不可用。', array( 'status' => 503 ) ); }
	}
	public static function record( \WP_REST_Request $request ) {
		$data = self::history_body( $request, array( 'productId', 'variationId', 'kind' ) ); if ( is_wp_error( $data ) ) { return $data; }
		foreach ( array( 'productId' => 1, 'variationId' => 0 ) as $key => $min ) { $id = array_key_exists( $key, $data ) ? $data[ $key ] : ( 'variationId' === $key ? 0 : null ); if ( ! is_int( $id ) || $id < $min || $id > 2147483647 ) { return Config::error( $key, 'invalid_id', '商品及规格编号须为本站的有效整数。' ); } }
		$kind = array_key_exists( 'kind', $data ) ? $data['kind'] : 'view';
		if ( ! is_string( $kind ) || ! in_array( $kind, array( 'view', 'selection' ), true ) ) { return Config::error( 'kind', 'invalid_kind', '浏览记录类型仅支持 view 或 selection。' ); }
		try { return self::respond( History::record( $data['productId'], $data['variationId'] ?? 0, $kind ) ); } catch ( \Throwable $error ) { return new \WP_Error( 'pc_recently_viewed_unavailable', '最近浏览暂不可用。', array( 'status' => 503 ) ); }
	}
	public static function clear( \WP_REST_Request $request ) {
		$data = self::history_body( $request, array() ); return is_wp_error( $data ) ? $data : self::respond( History::clear() );
	}
}
