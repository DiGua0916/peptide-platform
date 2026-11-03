<?php
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

/** A readable return error; never verifies or completes a payment. */
final class Payment_Return_View {

	private const MARKER = 'pc_panapay_return_view';
	private const ROUTE = '/pc-panapay/v1/hub/return';
	private static bool $registered = false;

	public static function register_hooks(): void {
		if ( self::$registered ) { return; }
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve' ), 100, 4 );
		self::$registered = true;
	}

	/**
	 * The optional store home must come from an already authenticated attempt and
	 * its registered site, never a browser target/query parameter. No private
	 * order, transaction, signing or return-token values belong in this response.
	 */
	public static function failure( ?string $validated_store_home = null ): \WP_REST_Response {
		return new \WP_REST_Response(
			array( self::MARKER => 1, 'store_home' => self::store_home( $validated_store_home ) ),
			400,
			self::headers()
		);
	}

	/** Serve only this module's exact GET return-error shape, never another API. */
	public static function serve( $served, $result, $request, $server ) {
		if ( $served || ! $request instanceof \WP_REST_Request || self::ROUTE !== $request->get_route()
			|| 'GET' !== $request->get_method() || ! $result instanceof \WP_REST_Response || 400 !== $result->get_status() ) {
			return $served;
		}
		$data = $result->get_data();
		if ( ! is_array( $data ) || count( $data ) !== 2 || 1 !== ( $data[ self::MARKER ] ?? null )
			|| ! array_key_exists( 'store_home', $data ) || ( null !== $data['store_home'] && ! is_string( $data['store_home'] ) )
			|| self::store_home( $data['store_home'] ) !== $data['store_home'] ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			status_header( 400 );
			foreach ( self::headers() as $name => $value ) { header( $name . ': ' . $value, true ); }
		}
		echo self::render( $data['store_home'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render escapes every dynamic value.
		return true;
	}

	public static function render( ?string $validated_store_home = null ): string {
		$home = self::store_home( $validated_store_home );
		$title = __( 'Check your payment status', 'peptide-panapay' );
		$html = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>' . esc_html( $title ) . '</title>';
		$html .= '<style>body{margin:0;background:#fff;color:#171717;font-family:Manrope,system-ui,sans-serif;font-size:16px;line-height:1.5}main{box-sizing:border-box;max-width:40rem;margin:64px auto;padding:24px}h1{margin:0 0 24px;font-size:30px;line-height:1.2;font-weight:650;text-wrap:balance}p{margin:0 0 24px;text-wrap:pretty}.return-link{display:inline-flex;align-items:center;min-height:44px;box-sizing:border-box;padding:8px 12px;border-radius:6px;background:#171717;color:#fff;font-weight:600;text-decoration:none}.return-link:hover{background:#333}.return-link:active{transform:translateY(1px)}.return-link:focus-visible{outline:2px solid #171717;outline-offset:4px}@media(max-width:600px){main{margin:32px auto;padding:24px}h1{font-size:24px;line-height:1.333333}}</style></head><body><main aria-labelledby="payment-return-title"><h1 id="payment-return-title">' . esc_html( $title ) . '</h1>';
		$html .= '<p>' . esc_html__( 'We could not verify the return from the payment provider. Please check your order with the store.', 'peptide-panapay' ) . '</p>';
		$html .= '<p>' . esc_html__( 'If your card was charged, do not pay again. Open your store order or contact the store for help.', 'peptide-panapay' ) . '</p>';
		if ( null !== $home ) {
			$html .= '<p><a class="return-link" href="' . esc_url( $home ) . '" rel="noreferrer">' . esc_html__( 'Return to store', 'peptide-panapay' ) . '</a></p>';
		} else {
			$html .= '<p>' . esc_html__( 'Open the store where you placed this order to view its current status.', 'peptide-panapay' ) . '</p>';
		}
		return $html . '</main></body></html>';
	}

	private static function headers(): array {
		return array(
			'Content-Type' => 'text/html; charset=UTF-8',
			'Cache-Control' => 'no-store, private, max-age=0',
			'Pragma' => 'no-cache',
			'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
			'X-Robots-Tag' => 'noindex, nofollow, noarchive',
			'Referrer-Policy' => 'no-referrer',
			'X-Content-Type-Options' => 'nosniff',
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
		);
	}

	/** Syntax guard in addition to the caller's authenticated-site authorization. */
	private static function store_home( ?string $url ): ?string {
		if ( null === $url || strlen( $url ) > 512 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return null; }
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] )
			|| isset( $p['query'] ) || isset( $p['fragment'] ) || ! in_array( $p['path'] ?? '', array( '', '/' ), true )
			|| ( isset( $p['port'] ) && 443 !== $p['port'] ) || ! is_string( $p['host'] ?? null ) ) { return null; }
		$host = strtolower( $p['host'] );
		if ( strlen( $host ) > 253 || ! preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host )
			|| preg_match( '/\.(?:localhost|local|internal|test|invalid)\z/D', $host ) ) { return null; }
		return 'https://' . $host . '/';
	}
}
