<?php
/** Product broadcast: public effective configuration, authorized management. */
namespace PeptideCore\Api;

use PeptideCore\Broadcast\Config;
use PeptideCore\Broadcast\Importer;
use PeptideCore\Broadcast\Store;

defined( 'ABSPATH' ) || exit;

final class Product_Broadcast_Controller {

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$base = '/product-broadcast';
		register_rest_route( Rest_Controller::ROUTE_NAMESPACE, $base, array( 'methods' => 'GET', 'callback' => array( self::class, 'effective' ), 'permission_callback' => '__return_true' ) );
		foreach ( array(
			'/admin' => array( array( 'GET', 'read' ), array( 'PUT', 'save' ) ),
			'/publish' => array( array( 'POST', 'publish' ) ),
			'/preview' => array( array( 'POST', 'preview' ) ),
			'/import' => array( array( 'POST', 'import' ) ),
			'/examples' => array( array( 'GET', 'examples' ) ),
			'/export' => array( array( 'GET', 'export' ) ),
		) as $path => $methods ) {
			$routes = array();
			foreach ( $methods as [ $method, $callback ] ) {
				$routes[] = array( 'methods' => $method, 'callback' => array( self::class, $callback ), 'permission_callback' => array( self::class, 'can_manage' ) );
			}
			register_rest_route( Rest_Controller::ROUTE_NAMESPACE, $base . $path, $routes );
		}
	}

	public static function can_manage( \WP_REST_Request $request ) {
		if ( ! Store::can_manage() ) { return new \WP_Error( 'pc_broadcast_forbidden', __( '此账号没有应用配置权限。', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) ); }
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) { return new \WP_Error( 'pc_broadcast_nonce', __( '登录验证已过期，请刷新页面后重试。', 'peptide-core' ), array( 'status' => 403 ) ); }
		return true;
	}

	private static function respond( $data ) {
		if ( is_wp_error( $data ) ) { return $data; }
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	/** JSON object only; no form, query-selected store or partial unknown payload. */
	private static function body( \WP_REST_Request $request, array $allowed ) {
		// Allows a complete legal config even when a client escapes every Unicode code point.
		if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 24 * 1024 * 1024 ) { return self::invalid( 'body', 'invalid_format_or_size' ); }
		$data = $request->get_json_params();
		if ( is_wp_error( $data ) || ! is_array( $data ) || array_is_list( $data ) ) { return self::invalid( 'body', 'object_required' ); }
		if ( array_key_exists( 'config', $data ) ) {
			$shape = json_decode( $request->get_body() );
			if ( ! $shape instanceof \stdClass || ! ( $shape->config ?? null ) instanceof \stdClass ) { return self::invalid( 'config', 'object_required' ); }
		}
		foreach ( array_keys( $data ) as $key ) { if ( ! in_array( $key, $allowed, true ) ) { return self::invalid( (string) $key, 'unknown_field' ); } }
		foreach ( $request->get_query_params() as $key => $value ) { if ( '_locale' !== $key ) { return self::invalid( (string) $key, 'query_not_supported' ); } }
		return $data;
	}

	private static function query( \WP_REST_Request $request, array $allowed ) {
		$params = $request->get_query_params();
		foreach ( $params as $key => $value ) { if ( ! in_array( $key, array_merge( $allowed, array( '_locale' ) ), true ) || ! is_scalar( $value ) ) { return self::invalid( (string) $key, 'invalid_format' ); } }
		return $params;
	}

	public static function effective( \WP_REST_Request $request ) {
		$params = self::query( $request, array() );
		return is_wp_error( $params ) ? $params : self::respond( Store::public_config() );
	}

	public static function read( \WP_REST_Request $request ) {
		$params = self::query( $request, array() );
		return is_wp_error( $params ) ? $params : self::respond( Store::admin() );
	}

	private static function write( \WP_REST_Request $request, bool $publish ) {
		$data = self::body( $request, array( 'config', 'expectedRevision' ) );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( ! is_int( $data['expectedRevision'] ?? null ) || $data['expectedRevision'] < 0 || $data['expectedRevision'] > 2147483646 ) { return self::invalid( 'expectedRevision', 'nonnegative_integer_required' ); }
		return self::respond( Store::save( $data['config'] ?? null, $data['expectedRevision'], $publish ) );
	}
	public static function save( \WP_REST_Request $request ) { return self::write( $request, false ); }
	public static function publish( \WP_REST_Request $request ) { return self::write( $request, true ); }

	public static function preview( \WP_REST_Request $request ) {
		$data = self::body( $request, array( 'config' ) );
		if ( is_wp_error( $data ) ) { return $data; }
		$config = Config::normalize( $data['config'] ?? null );
		return is_wp_error( $config ) ? $config : self::respond( array( 'config' => $config, 'warnings' => Config::warnings( $config ) ) );
	}

	public static function import( \WP_REST_Request $request ) {
		$data = self::body( $request, array( 'content', 'filename', 'mode', 'restore', 'config' ) );
		if ( is_wp_error( $data ) ) { return $data; }
		foreach ( array( 'content', 'filename', 'mode' ) as $key ) { if ( ! is_string( $data[ $key ] ?? null ) ) { return self::invalid( $key, 'string_required' ); } }
		if ( isset( $data['restore'] ) && ! is_bool( $data['restore'] ) ) { return self::invalid( 'restore', 'boolean_required' ); }
		$config = Config::normalize( $data['config'] ?? null );
		if ( is_wp_error( $config ) ) { return $config; }
		return self::respond( Importer::parse( $data['content'], $data['filename'], $config, $data['mode'], $data['restore'] ?? false ) );
	}

	public static function export( \WP_REST_Request $request ) {
		$params = self::query( $request, array( 'source' ) );
		if ( is_wp_error( $params ) ) { return $params; }
		$source = $params['source'] ?? 'draft';
		if ( ! in_array( $source, array( 'draft', 'published' ), true ) ) { return self::invalid( 'source', 'invalid_format' ); }
		$state = Store::admin();
		if ( is_wp_error( $state ) ) { return $state; }
		return self::respond( array( 'filename' => 'product-broadcast-' . $source . '.json', 'mime' => 'application/json;charset=utf-8', 'content' => wp_json_encode( $state[ $source ]['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" ) );
	}

	public static function examples( \WP_REST_Request $request ) {
		$params = self::query( $request, array( 'format' ) );
		if ( is_wp_error( $params ) ) { return $params; }
		$format = $params['format'] ?? 'txt';
		$examples = array(
			'txt' => array( 'txt', 'text/plain;charset=utf-8', "{product} · Choose a pack\n{specification} · {price}\nProduct details in one place\n" ),
			'csv' => array( 'csv', 'text/csv;charset=utf-8', "text\r\n\"{product}, choose a pack\"\r\n\"{specification} · {price}\"\r\n" ),
			'csv-names' => array( 'csv', 'text/csv;charset=utf-8', "text,name\r\n\"{name}: explore {product}\",\"示例名称 A\"\r\n\"{name}: view {specification}\",\"示例名称 B\"\r\n" ),
			'json' => array( 'json', 'application/json;charset=utf-8', wp_json_encode( Config::defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" ),
		);
		if ( ! isset( $examples[ $format ] ) ) { return self::invalid( 'format', 'invalid_format' ); }
		[ $extension, $mime, $content ] = $examples[ $format ];
		return self::respond( array( 'filename' => 'product-broadcast-example' . ( 'csv-names' === $format ? '-names' : '' ) . '.' . $extension, 'mime' => $mime, 'content' => $content ) );
	}

	private static function invalid( string $field, string $reason ): \WP_Error {
		return new \WP_Error( 'pc_broadcast_invalid', __( '商品播报参数无效，请检查标记的字段。', 'peptide-core' ), array( 'status' => 400, 'fields' => array( $field => $reason ) ) );
	}
}
