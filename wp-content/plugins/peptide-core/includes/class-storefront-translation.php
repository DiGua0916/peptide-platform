<?php
/** Request-scoped public storefront locale. Does not translate or duplicate business records. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Storefront_Translation {

	public const OPTION = 'pc_storefront_translation_enabled';
	private static bool $registered = false;
	private static bool $attempted = false;
	private static bool $handled = false;
	private static string $scope = '';
	private static ?bool $enabled_cache = null;
	private static array $resource_cache = array();
	private static array $state = array( 'requested_language' => 'en', 'language' => 'en', 'locale' => 'en_US', 'source' => 'disabled', 'country_code' => null, 'active' => false, 'applied' => false, 'reason' => 'not_initialized' );

	public static function register_hooks(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'init', array( self::class, 'apply' ), 20 );
		add_filter( 'wp_headers', array( self::class, 'document_headers' ), PHP_INT_MAX );
		add_action( 'send_headers', array( self::class, 'send_document_headers' ), PHP_INT_MAX );
		add_filter( 'rest_post_dispatch', array( self::class, 'rest_headers' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_rest_headers' ), PHP_INT_MAX, 4 );
		add_filter( 'determine_locale', array( self::class, 'filter_locale' ), PHP_INT_MAX );
	}

	/** Explicit opt-in, independent of automatic country-language detection. */
	public static function enabled(): bool {
		if ( null !== self::$enabled_cache ) { return self::$enabled_cache; }
		try { self::$enabled_cache = in_array( get_option( self::OPTION, false ), array( true, 1, '1' ), true ); }
		catch ( \Throwable $error ) { self::$enabled_cache = false; }
		return self::$enabled_cache;
	}

	public static function code(): string { return self::$state['language']; }
	public static function locale(): string { return self::$state['locale']; }
	public static function active(): bool { return self::$state['active']; }
	/** No raw address, request URL, authentication material or customer identity. */
	public static function selected(): array { return self::$state; }

	/** Installed core, native Woo PHP and block-script catalogs must all be available. */
	public static function resources_ready( string $language ): bool {
		if ( array_key_exists( $language, self::$resource_cache ) ) { return self::$resource_cache[ $language ]; }
		return self::$resource_cache[ $language ] = self::check_resources( $language );
	}

	private static function check_resources( string $language ): bool {
		if ( 'en' === $language ) { return true; }
		if ( 'es' !== $language || ! defined( 'WP_LANG_DIR' ) || ! function_exists( 'get_available_languages' ) ) { return false; }
		try {
			if ( ! in_array( 'es_ES', get_available_languages(), true ) ) { return false; }
			$base = rtrim( WP_LANG_DIR, '/\\' );
			$core = is_readable( $base . '/es_ES.mo' ) || is_readable( $base . '/es_ES.l10n.php' );
			$woo = is_readable( $base . '/plugins/woocommerce-es_ES.mo' ) || is_readable( $base . '/plugins/woocommerce-es_ES.l10n.php' );
			if ( ! $core || ! $woo ) { return false; }
			foreach ( glob( $base . '/plugins/woocommerce-es_ES-*.json' ) ?: array() as $file ) { if ( is_readable( $file ) ) { return true; } }
			return false;
		} catch ( \Throwable $error ) { return false; }
	}
	public static function packages_ready(): bool { return self::resources_ready( 'es' ); }

	/** Runs after Woo initialization; getters never trigger an early lookup or another switch. */
	public static function apply(): void {
		if ( self::$attempted ) { return; }
		self::$attempted = true;
		if ( ! self::enabled() ) { self::$state['reason'] = 'feature_disabled'; return; }
		$scope = self::request_scope();
		if ( '' === $scope ) { self::$state['reason'] = 'excluded_request'; return; }
		if ( headers_sent() ) { self::$state['reason'] = 'headers_already_sent'; return; }
		self::$scope = $scope;
		self::$handled = true;
		$query = $_GET['pc_lang'] ?? null;
		$explicit = self::supported( $query ) ? $query : null;
		$automatic = '1' === ( $_GET['pc_lang_auto'] ?? null );
		$manual_cookie = self::supported( $_COOKIE[ Storefront_Language::PREFERENCE_COOKIE ] ?? null );
		try {
			if ( null !== $explicit && ( ! $automatic || ! $manual_cookie ) ) {
				$choice = Storefront_Language::resolve( Storefront_Language::get(), $explicit );
				$choice['source'] = $automatic ? 'auto_url' : 'manual_url';
				if ( ! $automatic ) { self::write_preference( $explicit ); }
			} else {
				// This existing resolver uses only a valid manual cookie or the canonical
				// REMOTE_ADDR plus its local Country_Provider. Never a caller country header.
				$choice = Storefront_Language::current_request();
			}
		} catch ( \Throwable $error ) {
			self::$state['reason'] = 'selection_unavailable';
			return;
		}
		$language = self::supported( $choice['language'] ?? null ) ? $choice['language'] : 'en';
		self::$state['requested_language'] = $language;
		self::$state['source'] = is_string( $choice['source'] ?? null ) ? $choice['source'] : 'default';
		$country = $choice['country_code'] ?? null;
		self::$state['country_code'] = is_string( $country ) && 1 === preg_match( '/\A[A-Z]{2}\z/D', $country ) ? $country : null;
		if ( ! self::resources_ready( $language ) ) { self::$state['reason'] = 'resources_unavailable'; return; }
		$locale = 'es' === $language ? 'es_ES' : 'en_US';
		try {
			if ( get_locale() !== $locale ) { switch_to_locale( $locale ); }
			if ( get_locale() !== $locale ) { self::$state['reason'] = 'locale_switch_failed'; return; }
		} catch ( \Throwable $error ) { self::$state['reason'] = 'locale_switch_failed'; return; }
		self::$state['language'] = $language;
		self::$state['locale'] = $locale;
		self::$state['active'] = true;
		self::$state['applied'] = 'es' === $language;
		self::$state['reason'] = 'ready';
	}

	/** Script catalogs follow this owned public request, not ?_locale=user.
	 * Native nested switches (for example Woo mail) retain their own locale: this
	 * filter never forces the selection over a different actual get_locale().
	 */
	public static function filter_locale( string $locale ): string {
		if ( ! self::$handled || ! self::$state['active'] || is_admin() || self::excluded_context() ) { return $locale; }
		return get_locale() === self::$state['locale'] ? self::$state['locale'] : $locale;
	}

	private static function supported( $value ): bool { return is_string( $value ) && in_array( $value, array( 'en', 'es' ), true ); }

	/** Host-only preference; no Domain attribute and no authentication/session cookie. */
	public static function preference_cookie_options(): array {
		return array( 'expires' => time() + 90 * 86400, 'path' => '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' );
	}

	/** Kept behind a narrow wrapper so isolated tests can observe, never emit, headers. */
	public static function write_preference( string $language ): bool {
		if ( ! self::$handled || ! self::supported( $language ) || headers_sent() || '' === self::request_scope() ) { return false; }
		try { return setcookie( Storefront_Language::PREFERENCE_COOKIE, $language, self::preference_cookie_options() ); }
		catch ( \Throwable $error ) { return false; }
	}

	/** No arbitrary proxy/forwarded header, IP, locale or redirect target is parsed here. */
	private static function excluded_context(): bool {
		foreach ( array( 'WP_CLI', 'DOING_CRON', 'XMLRPC_REQUEST', 'WP_INSTALLING' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) { return true; }
		}
		if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || is_admin() ) { return true; }
		// Query conditionals are not valid during init. URI/query guards below
		// protect that phase; use conditionals only after WordPress ran the query.
		if ( function_exists( 'did_action' ) && did_action( 'wp' ) ) {
			foreach ( array( 'is_preview', 'is_customize_preview', 'is_feed', 'is_trackback', 'is_robots', 'is_favicon' ) as $function ) {
				if ( function_exists( $function ) && $function() ) { return true; }
			}
		}
		if ( class_exists( Management_Host::class ) && Management_Host::is_management_request() ) { return true; }
		foreach ( array( 'wc-api', 'wc_api', 'wc-ajax', 'key', 'order_key', 'pay_for_order', 'order-pay', 'order-received', 'pc_panapay_return', 'pc_payment_return', 'payment-return', '_wpnonce', 'feed', 'pc_frame', 'preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'customize_theme', 'elementor-preview', 'pc_preview_token', 'pc_template_preview', 'pc_design_document', 'pc_preview_nonce' ) as $key ) {
			if ( array_key_exists( $key, $_GET ) ) { return true; }
		}
		$page = $_GET['page'] ?? null;
		if ( is_string( $page ) && ( str_starts_with( $page, 'pc-' ) || str_starts_with( $page, 'pchq' ) || 'wc-admin' === $page ) ) { return true; }
		return false;
	}

	private static function path( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 8192 || '/' !== $value[0] || str_starts_with( $value, '//' ) || preg_match( '/[\x00-\x20\x7f\\\\]|%(?:00|0a|0d|2f|5c|2e)/i', $value ) ) { return null; }
		$decoded = rawurldecode( $value );
		if ( ! preg_match( '//u', $decoded ) || preg_match( '/[\x00-\x20\x7f\\\\?#]|%[0-9a-f]{2}/i', $decoded ) || str_contains( $decoded, '//' ) || preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $decoded ) ) { return null; }
		return $decoded;
	}

	/** Only public GET namespaces; protected editor/management and machine routes are excluded. */
	public static function public_rest_route( string $route, string $method ): bool {
		if ( 'GET' !== $method || null === self::path( $route ) || str_contains( $route, '?' ) || str_contains( $route, '#' ) ) { return false; }
		$route = self::path( $route );
		if ( ! preg_match( '~\A/(?:wc/store/v1|vialcove/v1|peptide/v1)(?:/|\z)~D', $route ) ) { return false; }
		if ( preg_match( '~(?:^|/)(?:admin(?:[-_][a-z]+)?|editor|design(?:-[a-z]+)?|layouts?|templates?|records?|settings?|staff|track|tracking|data|hq|hub|payments?|panapay|callbacks?|webhooks?|state|draft|save|publish|import|reset|outbox)(?:/|\z)~iD', $route ) ) { return false; }
		if ( preg_match( '~\A/wc/store/v1(?:/|\z)~D', $route ) ) { return true; }
		if ( preg_match( '~\A/vialcove/v1/home-product/[1-9][0-9]*/?\z~D', $route ) ) { return true; }
		return (bool) preg_match( '~\A/peptide/v1/(?:site|products|batches|reports|coupons|menus|exit-intent|product-broadcast|activity-listener|cart-feedback|recently-viewed|storefront-language)(?:/|\z)~D', $route );
	}

	private static function request_scope(): string {
		if ( self::excluded_context() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return ''; }
		$uri = $_SERVER['REQUEST_URI'] ?? null;
		if ( ! is_string( $uri ) || strlen( $uri ) > 8192 || preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) ) { return ''; }
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['scheme'] ) || isset( $parts['host'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return ''; }
		$path = self::path( $parts['path'] ?? null );
		if ( null === $path ) { return ''; }
		$base = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( is_string( $base ) && '/' !== $base && '' !== $base ) {
			$base = rtrim( $base, '/' );
			if ( $path !== $base && ! str_starts_with( $path, $base . '/' ) ) { return ''; }
			$path = substr( $path, strlen( $base ) ) ?: '/';
		}
		if ( preg_match( '~\A/(?:wp-admin(?:/|\z)|wp-login\.php(?:/|\z)|wp-cron\.php|xmlrpc\.php|wp-comments-post\.php|wp-content(?:/|\z)|wp-includes(?:/|\z)|wc-api(?:/|\z)|pc-panapay(?:/|\z)|wp-sitemap(?:\.xml|/)|robots\.txt|favicon\.ico)~iD', $path ) ) { return ''; }
		// Native and custom checkout-base payment/receipt endpoints stay wholly
		// outside the storefront locale adapter, even without a query signature.
		if ( preg_match( '~/(?:order-pay|order-received)(?:/|\z)~iD', $path ) ) { return ''; }
		if ( preg_match( '~/(?:feed|rss|rss2|atom|rdf)/?\z~iD', $path ) ) { return ''; }
		$pretty = '/wp-json' === $path || str_starts_with( $path, '/wp-json/' );
		$query_rest = array_key_exists( 'rest_route', $_GET );
		if ( $pretty || $query_rest ) {
			$route = $pretty ? substr( $path, strlen( '/wp-json' ) ) : ( $_GET['rest_route'] ?? null );
			if ( ! is_string( $route ) || ( $pretty && $query_rest && $_GET['rest_route'] !== $route ) ) { return ''; }
			return self::public_rest_route( $route, 'GET' ) ? 'rest' : '';
		}
		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) { return ''; }
		return 'document';
	}

	public static function public_document_request(): bool { return 'document' === self::request_scope(); }

	/** Preserve all pre-existing Vary dependencies, including multi-line/mixed-case fields. */
	private static function private_header_values( array $headers ): array {
		$vary = array(); $all = false;
		foreach ( $headers as $name => $values ) {
			if ( 'vary' !== strtolower( (string) $name ) ) { continue; }
			foreach ( (array) $values as $value ) {
				if ( ! is_string( $value ) ) { continue; }
				foreach ( explode( ',', $value ) as $token ) {
					$token = trim( $token );
					if ( '*' === $token ) { $all = true; }
					elseif ( '' !== $token ) { $vary[ strtolower( $token ) ] = $token; }
				}
			}
		}
		foreach ( array_keys( $headers ) as $name ) {
			if ( in_array( strtolower( (string) $name ), array( 'cache-control', 'pragma', 'expires', 'vary' ), true ) ) { unset( $headers[ $name ] ); }
		}
		$vary['cookie'] = 'Cookie';
		return array_merge( $headers, array( 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'Expires' => '0', 'Vary' => $all ? '*' : implode( ', ', array_values( $vary ) ) ) );
	}

	public static function document_headers( array $headers ): array {
		return self::$handled && 'document' === self::$scope ? self::private_header_values( $headers ) : $headers;
	}
	public static function send_document_headers(): void {
		if ( ! self::$handled || 'document' !== self::$scope || headers_sent() ) { return; }
		foreach ( self::private_header_values( array() ) as $name => $value ) { header( $name . ': ' . $value, 'Vary' !== $name ); }
	}
	public static function rest_headers( $response, $server, \WP_REST_Request $request ) {
		if ( self::$handled && $response instanceof \WP_REST_Response && self::public_rest_route( $request->get_route(), $request->get_method() ) ) {
			$response->set_headers( self::private_header_values( $response->get_headers() ) );
		}
		return $response;
	}
	public static function serve_rest_headers( $served, $response, \WP_REST_Request $request, $server ) {
		if ( self::$handled && self::public_rest_route( $request->get_route(), $request->get_method() ) && ! headers_sent() ) {
			foreach ( self::private_header_values( array() ) as $name => $value ) { header( $name . ': ' . $value, 'Vary' !== $name ); }
		}
		return $served;
	}
}
