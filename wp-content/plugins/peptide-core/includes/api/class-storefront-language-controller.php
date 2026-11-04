<?php
/** Public, read-only language metadata. The callback never writes preference or switches locale. */
namespace PeptideCore\Api;

use PeptideCore\Storefront_Language;
use PeptideCore\Storefront_Translation;

defined( 'ABSPATH' ) || exit;

final class Storefront_Language_Controller {
	private const ROUTE = '/peptide/v1/storefront-language';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'private_headers' ), 90, 3 );
	}

	public static function register_routes(): void {
		register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/storefront-language', array(
			'methods' => 'GET', 'callback' => array( self::class, 'effective' ), 'permission_callback' => '__return_true',
		) );
	}

	public static function effective( \WP_REST_Request $request ) {
		foreach ( $request->get_query_params() as $key => $value ) {
			if ( '_locale' !== $key || ! is_scalar( $value ) ) {
				return new \WP_Error( 'pc_storefront_language_query', __( '该接口不接受国家、语言、地址或其他选择参数。', 'peptide-core' ), array( 'status' => 400 ) );
			}
		}
		$settings = Storefront_Language::get();
		$selection = Storefront_Language::current_request( $settings );
		$runtime = class_exists( Storefront_Translation::class ) ? Storefront_Translation::selected() : null;
		if ( is_array( $runtime ) && ! empty( $runtime['active'] ) ) {
			$selection['translation_applied'] = ! empty( $runtime['applied'] );
			$selection['locale'] = $runtime['locale'];
		}
		$response = rest_ensure_response( array(
			'config' => Storefront_Language::public_config( $settings ),
			'selection' => $selection,
		) );
		return self::private_headers( $response, null, $request );
	}

	/** Apply to success/errors without erasing earlier CORS/content Vary dependencies. */
	public static function private_headers( $response, $server, \WP_REST_Request $request ) {
		if ( self::ROUTE === rtrim( $request->get_route(), '/' ) && $response instanceof \WP_REST_Response ) {
			$headers = $response->get_headers();
			$vary = array(); $all = false;
			foreach ( $headers as $name => $value ) {
				if ( 'vary' !== strtolower( (string) $name ) ) { continue; }
				foreach ( (array) $value as $part ) {
					if ( ! is_string( $part ) ) { continue; }
					foreach ( explode( ',', $part ) as $token ) {
						$token = trim( $token );
						if ( '*' === $token ) { $all = true; }
						elseif ( '' !== $token ) { $vary[ strtolower( $token ) ] = $token; }
					}
				}
			}
			$vary['cookie'] = 'Cookie';
			foreach ( array_keys( $headers ) as $name ) {
				if ( in_array( strtolower( (string) $name ), array( 'cache-control', 'pragma', 'expires', 'vary', 'x-content-type-options' ), true ) ) { unset( $headers[ $name ] ); }
			}
			$response->set_headers( $headers );
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
			$response->header( 'Vary', $all ? '*' : implode( ', ', array_values( $vary ) ), true );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	}
}
