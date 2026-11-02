<?php
/** Explicit, store-bound management origin. Legacy single-origin sites are unchanged. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Management_Host {

	private static bool $raw = false;
	private static int $email_depth = 0;

	public static function enabled(): bool {
		return defined( 'PEPTIDE_CORE_MANAGEMENT_URL' ) || defined( 'PEPTIDE_CORE_MANAGEMENT_UUID' );
	}

	/** Only HTTPS origins, with no credentials, query, fragment or alternate port. */
	public static function normalize_url( $value ): string {
		if ( ! is_string( $value ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) { return ''; }
		$p = wp_parse_url( $value );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || empty( $p['host'] )
			|| isset( $p['user'] ) || isset( $p['pass'] )
			|| isset( $p['query'] ) || isset( $p['fragment'] ) || ( isset( $p['port'] ) && 443 !== $p['port'] )
			|| ! in_array( $p['path'] ?? '', array( '', '/' ), true ) ) { return ''; }
		$host = strtolower( $p['host'] );
		if ( ! preg_match( '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host )
			|| filter_var( $host, FILTER_VALIDATE_IP ) ) { return ''; }
		return 'https://' . $host;
	}

	public static function management_url(): string {
		return defined( 'PEPTIDE_CORE_MANAGEMENT_URL' ) ? self::normalize_url( PEPTIDE_CORE_MANAGEMENT_URL ) : '';
	}

	public static function reading_raw(): bool { return self::$raw; }

	/** The public primary is stored independently of the current request's alias. */
	public static function public_url(): string {
		$before = self::$raw;
		self::$raw = true;
		try { return untrailingslashit( (string) get_option( 'home' ) ); }
		finally { self::$raw = $before; }
	}

	public static function configuration_error(): string {
		if ( ! self::enabled() ) { return ''; }
		if ( '' === self::management_url() || ! defined( 'PEPTIDE_CORE_MANAGEMENT_UUID' )
			|| ! is_string( PEPTIDE_CORE_MANAGEMENT_UUID )
			|| ! preg_match( '/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', PEPTIDE_CORE_MANAGEMENT_UUID ) ) { return 'management_configuration_invalid'; }
		$store = get_option( Hq_Connector::OPTION, array() );
		if ( ! is_array( $store ) || ! hash_equals( PEPTIDE_CORE_MANAGEMENT_UUID, (string) ( $store['uuid'] ?? '' ) ) ) { return 'management_store_mismatch'; }
		if ( defined( 'COOKIE_DOMAIN' ) && '' !== (string) COOKIE_DOMAIN ) { return 'management_cookie_domain_invalid'; }
		foreach ( array( 'COOKIEPATH' => '/', 'SITECOOKIEPATH' => '/', 'ADMIN_COOKIE_PATH' => '/wp-admin' ) as $key => $path ) {
			if ( defined( $key ) && $path !== constant( $key ) ) { return 'management_cookie_path_invalid'; }
		}
		$before = self::$raw;
		self::$raw = true;
		try { $site = self::normalize_url( get_option( 'siteurl' ) ); }
		finally { self::$raw = $before; }
		if ( $site !== self::management_url() ) { return 'management_siteurl_mismatch'; }
		if ( '' === self::normalize_url( self::public_url() ) ) { return 'public_origin_invalid'; }
		return '';
	}

	/** Validate the actual Host header; proxy forwarding headers never select a store. */
	public static function request_host(): string {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ( ! is_string( $host ) || ! preg_match( '/\A([a-z0-9.-]+)(?::443)?\z/iD', $host, $m ) ) { return ''; }
		return strtolower( $m[1] );
	}

	public static function is_management_request(): bool {
		return self::enabled() && '' !== self::management_url() && self::request_host() === wp_parse_url( self::management_url(), PHP_URL_HOST );
	}

	public static function allowed_hosts(): array {
		$hosts = array( wp_parse_url( self::management_url(), PHP_URL_HOST ), wp_parse_url( self::public_url(), PHP_URL_HOST ) );
		foreach ( Domains::hosts() as $host ) {
			if ( '' !== self::normalize_url( 'https://' . $host ) ) { $hosts[] = strtolower( $host ); }
		}
		return array_values( array_unique( array_filter( $hosts, 'is_string' ) ) );
	}

	public static function register_hooks(): void {
		if ( ! self::enabled() ) { return; }
		force_ssl_admin( true );
		add_action( 'init', array( self::class, 'guard_request' ), -1000 );
		add_filter( 'option_siteurl', static fn( $value ) => self::$raw ? $value : self::management_url(), PHP_INT_MAX );
		add_filter( 'site_url', array( self::class, 'site_url' ), PHP_INT_MAX, 4 );
		add_filter( 'admin_url', array( self::class, 'management_link' ), PHP_INT_MAX );
		foreach ( array( 'login_url', 'register_url' ) as $hook ) { add_filter( $hook, array( self::class, 'login_link' ), PHP_INT_MAX ); }
		add_filter( 'logout_url', array( self::class, 'logout_link' ), PHP_INT_MAX );
		add_filter( 'lostpassword_url', array( self::class, 'lostpassword_link' ), PHP_INT_MAX );
		add_filter( 'rest_url', array( self::class, 'request_link' ), PHP_INT_MAX );
		foreach ( array( 'content_url', 'plugins_url', 'includes_url' ) as $hook ) { add_filter( $hook, array( self::class, 'asset_link' ), PHP_INT_MAX ); }
		add_filter( 'upload_dir', array( self::class, 'upload_dir' ), PHP_INT_MAX );
		add_filter( 'wp_get_attachment_url', array( self::class, 'asset_link' ), PHP_INT_MAX );
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'image_srcset' ), PHP_INT_MAX );
		add_action( 'woocommerce_email_header', static function (): void { ++self::$email_depth; }, -1000 );
		add_action( 'woocommerce_email_footer', static function (): void { self::$email_depth = max( 0, self::$email_depth - 1 ); }, PHP_INT_MAX );
		add_filter( 'preview_post_link', array( self::class, 'management_link' ), PHP_INT_MAX );
		add_action( 'customize_register', array( self::class, 'customizer_preview' ), PHP_INT_MAX );
		add_filter( 'customize_allowed_urls', static fn() => array( trailingslashit( self::management_url() ) ), PHP_INT_MAX );
		add_filter( 'home_url', array( self::class, 'customizer_link' ), PHP_INT_MAX );
		add_filter( 'allowed_redirect_hosts', array( self::class, 'redirect_hosts' ) );
		add_filter( 'redirect_canonical', static fn( $url ) => self::is_management_request() ? false : $url );
		add_filter( 'authenticate', array( self::class, 'authenticate' ), PHP_INT_MAX - 1 );
		add_filter( 'rest_authentication_errors', array( self::class, 'rest_authentication' ), PHP_INT_MAX );
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest_dispatch' ), PHP_INT_MAX, 3 );
	}

	/** Preserve path, query and fragment; never accept a caller-supplied target origin. */
	public static function on_origin( string $url, string $origin ): string {
		if ( '' === $origin ) { return $url; }
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) ) { return $url; }
		return $origin . ( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' ) . ( isset( $p['fragment'] ) ? '#' . $p['fragment'] : '' );
	}

	public static function management_link( $url ) {
		return is_string( $url ) ? self::on_origin( $url, self::management_url() ) : $url;
	}

	/** Customer account forms use the public WooCommerce endpoints and cookie host. */
	public static function login_link( $url ) {
		return ! self::is_management_request() && function_exists( 'wc_get_page_permalink' )
			? wc_get_page_permalink( 'myaccount' ) : self::management_link( $url );
	}

	public static function logout_link( $url ) {
		return ! self::is_management_request() && ! self::staff_user( wp_get_current_user() ) && function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'customer-logout' ) : self::management_link( $url );
	}

	public static function lostpassword_link( $url ) {
		if ( self::is_management_request() ) {
			// WooCommerce may already have replaced the native login link at priority 10.
			return self::management_url() . '/wp-login.php?action=lostpassword';
		}
		return is_string( $url ) && ! preg_match( '#/wp-login\.php(?:[/?]|$)#i', $url ) ? $url : self::management_link( $url );
	}

	/** Customizer controls and iframe navigation share the management cookie origin. */
	public static function customizer_preview( $manager ): void {
		if ( self::is_management_request() && current_user_can( 'customize' ) && $manager instanceof \WP_Customize_Manager ) {
			$manager->set_preview_url( self::management_link( $manager->get_preview_url() ) );
		}
	}

	public static function customizer_link( $url ) {
		$manager = $GLOBALS['wp_customize'] ?? null;
		return self::is_management_request() && current_user_can( 'customize' ) && $manager instanceof \WP_Customize_Manager && $manager->is_preview()
			? self::management_link( $url ) : $url;
	}

	public static function request_link( $url ) {
		if ( ! is_string( $url ) ) { return $url; }
		$origin = self::management_url();
		if ( ! self::is_management_request() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$host = self::request_host();
			$origin = in_array( $host, self::allowed_hosts(), true ) ? 'https://' . $host : self::public_url();
		}
		return self::on_origin( $url, $origin );
	}

	/** Mail/CLI-generated customer assets use the public origin, never the private admin. */
	public static function asset_link( $url ) {
		return is_string( $url ) && ( self::$email_depth > 0 || ( defined( 'WP_CLI' ) && WP_CLI ) )
			? self::on_origin( $url, self::public_url() ) : self::request_link( $url );
	}

	/** WP_CONTENT_URL is initialized before normal plugins; rewrite URL fields, never disk paths. */
	public static function upload_dir( array $uploads ): array {
		foreach ( array( 'url', 'baseurl' ) as $key ) { if ( isset( $uploads[ $key ] ) && is_string( $uploads[ $key ] ) ) { $uploads[ $key ] = self::asset_link( $uploads[ $key ] ); } }
		return $uploads;
	}

	public static function image_srcset( $sources ) {
		if ( ! is_array( $sources ) ) { return $sources; }
		foreach ( $sources as &$source ) { if ( is_array( $source ) && isset( $source['url'] ) && is_string( $source['url'] ) ) { $source['url'] = self::asset_link( $source['url'] ); } }
		unset( $source );
		return $sources;
	}

	public static function site_url( $url, $path = '', $scheme = null, $blog_id = null ) {
		if ( ! is_string( $url ) ) { return $url; }
		$path = ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return preg_match( '#\A(?:wp-admin(?:/|$)|wp-login\.php(?:/|$))#', $path )
			? self::management_link( $url ) : self::request_link( $url );
	}

	public static function redirect_hosts( array $hosts ): array {
		$host = wp_parse_url( self::management_url(), PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) { $hosts[] = $host; }
		return array_values( array_unique( $hosts ) );
	}

	public static function staff_user( $user ): bool {
		return $user instanceof \WP_User && ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'edit_posts' ) || Staff::is_staff( $user ) );
	}

	public static function authenticate( $user, $username = '', $password = '' ) {
		return self::staff_user( $user ) && ! self::is_management_request()
			? new \WP_Error( 'pc_management_origin_required', 'Use your assigned store management address.' ) : $user;
	}

	public static function rest_authentication( $error ) {
		if ( $error instanceof \WP_Error || self::is_management_request() ) { return $error; }
		if ( self::staff_user( wp_get_current_user() ) ) { return new \WP_Error( 'pc_management_origin_required', 'Use your assigned store management address.', array( 'status' => 403 ) ); }
		return $error;
	}

	/** Actual routes are checked for each dispatch, including REST batch subrequests. */
	public static function rest_dispatch( $result, $server, $request ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return $result; }
		if ( $result instanceof \WP_Error || self::is_management_request() ) { return $result; }
		if ( self::staff_user( wp_get_current_user() ) || ( $request instanceof \WP_REST_Request && self::machine_route( $request->get_route() ) ) ) {
			return new \WP_Error( 'pc_management_origin_required', 'Use your assigned store management address.', array( 'status' => 403 ) );
		}
		return $result;
	}

	public static function machine_route( string $route ): bool {
		return (bool) preg_match( '#\A/(?:peptide/v1/hq(?:/|$)|peptide-ops/|pc-ops/|pc-panapay/v1/store/event(?:/|$))#i', $route );
	}

	/** Public commerce AJAX/forms remain available with their existing callback permissions. */
	public static function personnel_path( string $uri, array $query, array $post = array() ): bool {
		if ( ! str_starts_with( $uri, '/' ) || preg_match( '/[\x00-\x1f\x7f]/', $uri ) ) { return true; }
		$path = (string) wp_parse_url( '/' . ltrim( $uri, '/' ), PHP_URL_PATH );
		for ( $i = 0; $i < 3; ++$i ) { $path = rawurldecode( $path ); }
		$path = preg_replace( '#/+#', '/', $path );
		if ( str_contains( $path, '\\' ) || preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) ) { return true; }
		if ( preg_match( '#\A/(?:wp-login\.php|xmlrpc\.php|admin)(?:/|$)#i', $path ) ) { return true; }
		if ( array_key_exists( 'pc_hq_login', $query ) || array_key_exists( 'pc_staff_entry', $query ) ) { return true; }
		if ( self::preview_request( $query ) ) { return true; }
		if ( isset( $query['rest_route'] ) && ( ! is_string( $query['rest_route'] ) || self::machine_route( $query['rest_route'] ) ) ) { return true; }
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		if ( preg_match( '#/' . preg_quote( $prefix, '#' ) . '(/.*)\z#i', $path, $match ) && self::machine_route( $match[1] ) ) { return true; }
		if ( '/wp-admin/admin-ajax.php' === $path ) { return false; }
		if ( '/wp-admin/admin-post.php' === $path ) {
			$action = $post['action'] ?? $query['action'] ?? '';
			return ! is_string( $action ) || ! in_array( $action, array( 'pc_offer_add', 'pc_review_submit', 'pc_subscribe' ), true );
		}
		return (bool) preg_match( '#\A/wp-admin(?:/|$)#i', $path );
	}

	public static function guard_request(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }
		$error = self::configuration_error();
		if ( '' !== $error ) { self::deny( 503, 'Store management configuration is unavailable.' ); }
		if ( ! in_array( self::request_host(), self::allowed_hosts(), true ) ) { self::deny( 421, 'Unknown store host.' ); }
		if ( ! is_ssl() ) {
			if ( ! in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ), array( 'GET', 'HEAD' ), true ) ) { self::deny( 400, 'HTTPS is required.' ); }
			wp_safe_redirect( 'https://' . self::request_host() . '/' . ltrim( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), '/' ), 308, 'Store HTTPS' );
			exit;
		}
		if ( ! self::is_management_request() && ( self::personnel_path( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), $_GET, $_POST ) || self::staff_user( wp_get_current_user() ) ) ) {
			self::deny( 403, 'Use your assigned store management address.' );
		}
		$preview = self::preview_request( $_GET ) || self::exit_preview_ajax( $_GET );
		if ( $preview && ! self::staff_user( wp_get_current_user() ) ) { self::deny( 403, 'Sign in at your store management address to preview.' ); }
		if ( self::customizer_request( $_GET ) && ! current_user_can( 'customize' ) ) { self::deny( 403, 'Your account cannot customize this store.' ); }
		// Keep the public storefront canonical while authenticated editor previews stay same-origin.
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$service = is_admin() || wp_doing_ajax() || wp_doing_cron() || isset( $_GET['rest_route'] )
			|| str_contains( $path, '/' . $prefix . '/' ) || '/wp-cron.php' === $path || self::personnel_path( $uri, $_GET, $_POST );
		if ( self::is_management_request() && self::public_url() !== self::management_url() && ! $preview && ! $service ) {
			if ( ! in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ), array( 'GET', 'HEAD' ), true ) ) { self::deny( 403, 'Use the public store address for customer requests.' ); }
			wp_redirect( self::public_url() . '/' . ltrim( $uri, '/' ), 302, 'Store public address' );
			exit;
		}
	}

	public static function preview_request( array $query ): bool {
		foreach ( array( 'pc_template_preview', 'pc_preview_token', 'pc_preview_nonce', 'pc_exit_preview', 'preview', 'preview_nonce', 'preview_id' ) as $key ) {
			if ( array_key_exists( $key, $query ) ) { return true; }
		}
		return self::customizer_request( $query );
	}

	public static function customizer_request( array $query ): bool {
		foreach ( array( 'wp_customize', 'customize_changeset_uuid', 'customize_messenger_channel', 'customize_preview_nonce' ) as $key ) {
			if ( array_key_exists( $key, $query ) ) { return true; }
		}
		return false;
	}

	/** Exit previews use these existing callbacks, which suppress staff counters/coupon writes. */
	public static function exit_preview_ajax( array $query ): bool {
		return current_user_can( 'manage_woocommerce' ) && isset( $query['wc-ajax'] ) && is_string( $query['wc-ajax'] )
			&& in_array( $query['wc-ajax'], array( 'pc_exit_event', 'pc_exit_claim', 'pc_exit_spin' ), true );
	}

	private static function deny( int $status, string $message ): void {
		nocache_headers();
		wp_die( esc_html( $message ), 'Store access', array( 'response' => $status ) );
	}
}
