<?php
/** Application configuration and authorized synthetic preview. No public cart mutation API. */
namespace PeptideCore\Api;

use PeptideCore\Activity\Config;
use PeptideCore\Activity\Store;
use PeptideCore\Activity\Progress;
use PeptideCore\Promotions;
use PeptideCore\Activity\Cart_Reader;
use PeptideCore\Activity\Recommendations;

defined( 'ABSPATH' ) || exit;

final class Activity_Listener_Controller {
	public static function register_hooks(): void { add_action( 'rest_api_init', array( self::class, 'register_routes' ) ); add_filter( 'rest_post_dispatch', array( self::class, 'private_headers' ), 10, 3 ); }
	public static function register_routes(): void {
		register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/activity-listener', array( 'methods' => 'GET', 'callback' => array( self::class, 'effective' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/activity-listener/recommendations', array( 'methods' => 'POST', 'callback' => array( self::class, 'recommendations' ), 'permission_callback' => array( self::class, 'can_recommend' ) ) );
		foreach ( array( '/admin' => array( array( 'GET', 'read' ), array( 'PUT', 'save' ) ), '/publish' => array( array( 'POST', 'publish' ) ), '/preview' => array( array( 'POST', 'preview' ) ) ) as $path => $methods ) {
			$routes = array();
			foreach ( $methods as [ $method, $callback ] ) { $routes[] = array( 'methods' => $method, 'callback' => array( self::class, $callback ), 'permission_callback' => array( self::class, 'can_manage' ) ); }
			register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/activity-listener' . $path, $routes );
		}
	}

	/** Even validation/authentication errors must never become shared cart-cache entries. */
	public static function private_headers( $response, $server, \WP_REST_Request $request ) {
		if ( '/peptide/v1/activity-listener/recommendations' === $request->get_route() && $response instanceof \WP_REST_Response ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Cookie, Origin, Cart-Token' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	}
	public static function can_recommend( \WP_REST_Request $request ) {
		if ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return Recommendations::error( 'login_required', 401 ); }
		if ( is_user_logged_in() && ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return Recommendations::error( 'invalid_nonce', 403 ); }
		$origin = (string) $request->get_header( 'Origin' );
		if ( '' === $origin ) { $origin = (string) $request->get_header( 'Referer' ); }
		$parts = wp_parse_url( $origin ); $home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || strtolower( $parts['scheme'] ?? '' ) !== strtolower( $home['scheme'] ?? '' ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) || (int) ( $parts['port'] ?? ( 'https' === ( $parts['scheme'] ?? '' ) ? 443 : 80 ) ) !== (int) ( $home['port'] ?? ( 'https' === ( $home['scheme'] ?? '' ) ? 443 : 80 ) ) ) { return Recommendations::error( 'invalid_origin', 403 ); }
		return true;
	}
	public static function recommendations( \WP_REST_Request $request ) {
		$query = self::query( $request ); if ( is_wp_error( $query ) ) { return $query; }
		if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 16384 || ! json_decode( $request->get_body() ) instanceof \stdClass ) { return Recommendations::error( 'invalid_input' ); }
		$input = Recommendations::validate( $request->get_json_params() ); if ( is_wp_error( $input ) ) { return $input; }
		try {
			$current = Cart_Reader::read( $request ); if ( is_wp_error( $current ) ) { return $current; }
			// Security-only expiring state; no customer/cart/session/analytics record is written.
			$key = 'pc-recommendation-' . $current['rateKey'];
			if ( \WC_Rate_Limiter::retried_too_soon( $key ) ) { return Recommendations::error( 'rate_limited', 429, array( 'retryAfter' => 3 ) ); }
			if ( ! \WC_Rate_Limiter::set_rate_limit( $key, 2 ) ) { return Recommendations::error( 'rate_limit_unavailable', 503 ); }
			return self::respond( Recommendations::quote( $current['cart'], $input, null, $current['persistedTotals'] ) );
		} catch ( \Throwable $error ) { return Recommendations::error( 'cart_unavailable', 503 ); }
	}
	public static function can_manage( \WP_REST_Request $request ) {
		if ( ! Store::can_manage() ) { return new \WP_Error( 'pc_activity_listener_forbidden', __( '此账号没有应用配置权限。', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) ); }
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return is_string( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ? true : new \WP_Error( 'pc_activity_listener_nonce', __( '登录验证已过期，请刷新后重试。', 'peptide-core' ), array( 'status' => 403 ) );
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
	public static function preview( \WP_REST_Request $request ) {
		$data = self::body( $request, array( 'config', 'amount', 'quantity' ) ); if ( is_wp_error( $data ) ) { return $data; }
		$config = Config::normalize( $data['config'] ); if ( is_wp_error( $config ) ) { return $config; }
		if ( ! $config['promotionId'] || Promotions::CPT !== get_post_type( $config['promotionId'] ) || 'trash' === get_post_status( $config['promotionId'] ) ) { return Config::error( 'promotionId', 'required', '请先选择本站已有的优惠活动。' ); }
		$amount = $data['amount'] ?? '0'; $quantity = $data['quantity'] ?? 0;
		if ( ( ! is_string( $amount ) && ! is_int( $amount ) && ! is_float( $amount ) ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,6})?\z/', (string) $amount ) || ! is_finite( (float) $amount ) || (float) $amount > 10000000 ) { return Config::error( 'amount', 'invalid_amount', '示例金额须为 0 至 10,000,000 的十进制数，以点分隔小数。' ); }
		if ( ! is_int( $quantity ) || $quantity < 0 || $quantity > 10000 ) { return Config::error( 'quantity', 'invalid_quantity', '示例件数须为 0 至 10,000 的整数。' ); }
		return self::respond( array( 'preview' => true, 'notice' => __( '仅后台合成示例，假设全部合资格；不校验真实优惠码、商品范围或顾客资格，不修改购物车。', 'peptide-core' ), 'progress' => Progress::preview( $config, (float) $amount, $quantity ) ) );
	}
}
