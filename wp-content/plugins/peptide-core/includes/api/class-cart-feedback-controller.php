<?php
/** Configuration REST routes only; no cart, customer, or add-item mutation endpoint. */
namespace PeptideCore\Api;

use PeptideCore\Cart_Feedback\Config;
use PeptideCore\Cart_Feedback\Store;

defined( 'ABSPATH' ) || exit;

final class Cart_Feedback_Controller {
	public static function register_hooks(): void { add_action( 'rest_api_init', array( self::class, 'register_routes' ) ); }
	public static function register_routes(): void {
		register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/cart-feedback', array( 'methods' => 'GET', 'callback' => array( self::class, 'effective' ), 'permission_callback' => '__return_true' ) );
		foreach ( array( '/admin' => array( array( 'GET', 'read' ), array( 'PUT', 'save' ) ), '/publish' => array( array( 'POST', 'publish' ) ) ) as $path => $methods ) {
			$routes = array();
			foreach ( $methods as [ $method, $callback ] ) { $routes[] = array( 'methods' => $method, 'callback' => array( self::class, $callback ), 'permission_callback' => array( self::class, 'can_manage' ) ); }
			register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/cart-feedback' . $path, $routes );
		}
	}
	public static function can_manage( \WP_REST_Request $request ) {
		if ( ! Store::can_manage() ) { return new \WP_Error( 'pc_cart_feedback_forbidden', __( '此账号没有应用配置权限。', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) ); }
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return is_string( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ? true : new \WP_Error( 'pc_cart_feedback_nonce', __( '登录验证已过期，请刷新后重试。', 'peptide-core' ), array( 'status' => 403 ) );
	}
	private static function respond( $data ) {
		if ( is_wp_error( $data ) ) { return $data; }
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}
	private static function query( \WP_REST_Request $request ) {
		foreach ( $request->get_query_params() as $key => $value ) { if ( '_locale' !== $key || ! is_scalar( $value ) ) { return Config::error( (string) $key, 'unsupported_query', '该接口不接受其他店铺、客户或购物车选择参数。' ); } }
		return true;
	}
	private static function body( \WP_REST_Request $request ) {
		$query = self::query( $request ); if ( is_wp_error( $query ) ) { return $query; }
		if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 8192 ) { return Config::error( 'body', 'invalid_format_or_size', '仅接受不超过 8 KiB 的 JSON 配置。' ); }
		$data = $request->get_json_params(); $shape = json_decode( $request->get_body() );
		if ( ! is_array( $data ) || ! $shape instanceof \stdClass || ! ( $shape->config ?? null ) instanceof \stdClass ) { return Config::error( 'config', 'object_required', '请提供完整配置对象。' ); }
		foreach ( array_keys( $data ) as $key ) { if ( ! in_array( $key, array( 'config', 'expectedRevision' ), true ) ) { return Config::error( (string) $key, 'unknown_field', '包含未支持的参数，未保存此次修改。' ); } }
		return $data;
	}
	public static function effective( \WP_REST_Request $request ) { $query = self::query( $request ); return is_wp_error( $query ) ? $query : self::respond( Store::public_config() ); }
	public static function read( \WP_REST_Request $request ) { $query = self::query( $request ); return is_wp_error( $query ) ? $query : self::respond( Store::admin() ); }
	private static function write( \WP_REST_Request $request, bool $publish ) {
		$data = self::body( $request ); if ( is_wp_error( $data ) ) { return $data; }
		if ( ! is_int( $data['expectedRevision'] ?? null ) || $data['expectedRevision'] < 0 || $data['expectedRevision'] > 2147483646 ) { return Config::error( 'expectedRevision', 'integer_required', '请重新读取有效配置修订号。' ); }
		return self::respond( Store::save( $data['config'], $data['expectedRevision'], $publish ) );
	}
	public static function save( \WP_REST_Request $request ) { return self::write( $request, false ); }
	public static function publish( \WP_REST_Request $request ) { return self::write( $request, true ); }
}
