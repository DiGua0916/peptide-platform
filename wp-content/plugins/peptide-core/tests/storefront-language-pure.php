<?php
/** Actual resolver/controller under pure WP/GeoIP fixtures. No cookies, options or locale are written. */
namespace PeptideCore\Tracking {
	final class Country_Provider {
		public static array $calls = array();
		public static array $result = array( 'status' => 'known', 'country_code' => 'US' );
		public static bool $fail = false;
		public static function lookup( $address ): array { self::$calls[] = $address; if ( self::$fail ) { throw new \RuntimeException( 'Synthetic unavailable local database' ); } return self::$result; }
	}
}
namespace PeptideCore\Api { final class Rest_Controller { public const ROUTE_NAMESPACE = 'peptide/v1'; } }
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	final class WP_Error { public function __construct( public string $code, public string $message, public array $data ) {} }
	final class WP_REST_Request {
		public function __construct( public array $query = array(), public string $route = '/peptide/v1/storefront-language' ) {}
		public function get_query_params(): array { return $this->query; }
		public function get_route(): string { return $this->route; }
	}
	final class WP_REST_Response {
		private array $headers = array();
		public function __construct( public $data = null, public int $status = 200 ) {}
		public function get_headers(): array { return $this->headers; }
		public function set_headers( array $headers ): void { $this->headers = $headers; }
		public function header( $key, $value, $replace = true ): void { $this->headers[ $key ] = $replace ? $value : ( $this->headers[ $key ] ?? '' ) . ',' . $value; }
	}
	$GLOBALS['language_test_option_reads'] = 0;
	$GLOBALS['language_test_hooks'] = array(); $GLOBALS['language_test_routes'] = array();
	function get_option( $name, $default = false ) { ++$GLOBALS['language_test_option_reads']; if ( ! empty( $GLOBALS['language_test_storage_failure'] ) ) { throw new \RuntimeException( 'Synthetic option read failure' ); } return $GLOBALS['language_test_options'][ $name ] ?? $default; }
	function __( $text, $domain = '' ) { return $text; }
	function rest_ensure_response( $data ) { return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data ); }
	function add_action( ...$args ) { $GLOBALS['language_test_hooks'][] = array_merge( array( 'action' ), $args ); }
	function add_filter( ...$args ) { $GLOBALS['language_test_hooks'][] = array_merge( array( 'filter' ), $args ); }
	function register_rest_route( ...$args ) { $GLOBALS['language_test_routes'][] = $args; }
	require dirname( __DIR__ ) . '/includes/class-storefront-language.php';
	require dirname( __DIR__ ) . '/includes/api/class-storefront-language-controller.php';
	use PeptideCore\Storefront_Language as Language;
	use PeptideCore\Api\Storefront_Language_Controller as Controller;
	use PeptideCore\Tracking\Country_Provider as Country;
	$checks = array();
	$check = static function( string $name, bool $pass ) use ( &$checks ): void { $checks[] = compact( 'name', 'pass' ); };
	$defaults = Language::defaults();
	$enabled = array_replace( $defaults, array( 'enabled' => true ) );
	$check( 'disabled independent defaults and exact setting keys', $defaults === array( 'enabled' => false, 'default_language' => 'en', 'us_language' => 'en', 'pr_language' => 'es' ) && array_keys( $defaults ) === Language::KEYS );
	$check( 'normalization and sanitizer preserve exact full settings', Language::normalize( $enabled ) === $enabled && Language::sanitize( $enabled ) === $enabled );
	$shuffled = array_reverse( $enabled, true ); $check( 'normalization canonical key order', Language::normalize( $shuffled ) === $enabled );
	foreach ( array( null, false, true, 0, 'en', new \stdClass(), array(), array( 'en' ), array_merge( $enabled, array( 'country' => 'PR' ) ) ) as $i => $bad ) { $check( 'nonobject or extra configuration rejected ' . $i, Language::normalize( $bad ) instanceof WP_Error ); }
	foreach ( Language::KEYS as $key ) { $partial = $enabled; unset( $partial[ $key ] ); $check( 'missing setting rejected ' . $key, Language::sanitize( $partial ) instanceof WP_Error ); }
	foreach ( array( 'yes', 'true', 'false', '1', 1, 0, null, array() ) as $i => $bad ) { $check( 'enabled is strictly boolean ' . $i, Language::normalize( array_replace( $enabled, array( 'enabled' => $bad ) ) ) instanceof WP_Error ); }
	foreach ( array( 'default_language', 'us_language', 'pr_language' ) as $key ) {
		foreach ( array( '', 'EN', 'es_PR', ' en', 'es ', 'fr', true, 1, array(), null ) as $i => $bad ) { $check( 'strict language enum ' . $key . ':' . $i, Language::sanitize( array_replace( $enabled, array( $key => $bad ) ) ) instanceof WP_Error ); }
	}
	$reads = $GLOBALS['language_test_option_reads'];
	foreach ( array( 'en', 'es' ) as $fallback ) { foreach ( array( 'en', 'es' ) as $us ) { foreach ( array( 'en', 'es' ) as $pr ) {
		$settings = array( 'enabled' => true, 'default_language' => $fallback, 'us_language' => $us, 'pr_language' => $pr );
		foreach ( array( 'US' => $us, 'PR' => $pr, 'DE' => $fallback ) as $country => $expected ) {
			$result = Language::resolve( $settings, null, $country ); $source = 'DE' === $country ? 'default' : 'geoip';
			$check( 'pure configured map ' . implode( '-', array( $fallback, $us, $pr, $country ) ), $result === array( 'language' => $expected, 'locale' => 'en' === $expected ? 'en_US' : 'es_PR', 'source' => $source, 'country_code' => $country, 'translation_applied' => false ) );
		}
		foreach ( array( 'en', 'es' ) as $manual ) { $result = Language::resolve( $settings, $manual, 'PR' ); $check( 'manual choice outranks geo map ' . implode( '-', array( $fallback, $us, $pr, $manual ) ), $result['language'] === $manual && $result['source'] === 'manual' && $result['country_code'] === null ); }
	} } }
	$check( 'pure resolver null settings does not read options', Language::resolve()['source'] === 'disabled' && $GLOBALS['language_test_option_reads'] === $reads && Country::$calls === array() );
	$check( 'manual selection outranks disabled IP detection', Language::resolve( array_replace( $defaults, array( 'default_language' => 'es' ) ), 'en', 'US' ) === array( 'language' => 'en', 'locale' => 'en_US', 'source' => 'manual', 'country_code' => null, 'translation_applied' => false ) );
	$check( 'disabled IP detection without preference retains configured default', Language::resolve( array_replace( $defaults, array( 'default_language' => 'es' ) ), null, 'US' ) === array( 'language' => 'es', 'locale' => 'es_PR', 'source' => 'disabled', 'country_code' => null, 'translation_applied' => false ) );
	foreach ( array( null, '', 'us', 'USA', ' U', 'U1', array( 'US' ), false ) as $i => $bad ) { $result = Language::resolve( $enabled, null, $bad ); $check( 'invalid country metadata falls back ' . $i, $result['source'] === 'default' && $result['language'] === 'en' && $result['country_code'] === null ); }
	$check( 'corrupt supplied settings stay disabled without option fallback', Language::resolve( array( 'enabled' => true ), null, 'PR' )['source'] === 'disabled' );
	$config = Language::public_config( $enabled );
	$check( 'public configuration exact documented shape', $config === array( 'version' => 1, 'enabled' => true, 'default_language' => 'en', 'country_languages' => array( 'US' => 'en', 'PR' => 'es' ), 'supported_languages' => array( array( 'code' => 'en', 'locale' => 'en_US', 'label' => 'English' ), array( 'code' => 'es', 'locale' => 'es_PR', 'label' => 'Español' ) ), 'preference_cookie' => 'pc_storefront_language' ) );
	$GLOBALS['language_test_options'] = array( Language::OPTION => $enabled, 'WPLANG' => 'zh_CN', 'woocommerce_currency' => 'USD' );
	$optionsBefore = $GLOBALS['language_test_options'];
	$check( 'get reads only independent settings without mutation', Language::get() === $enabled && $GLOBALS['language_test_options'] === $optionsBefore );
	$GLOBALS['language_test_options'][Language::OPTION] = array( 'enabled' => true );
	$check( 'malformed stored options do not enable or repair themselves', Language::get() === $defaults && $GLOBALS['language_test_options'][Language::OPTION] === array( 'enabled' => true ) );
	$GLOBALS['language_test_storage_failure'] = true; $check( 'unavailable storage fails disabled', Language::get() === $defaults ); unset( $GLOBALS['language_test_storage_failure'] );
	$GLOBALS['language_test_options'][Language::OPTION] = $enabled;
	$_COOKIE = array(); $_GET = array( 'language' => 'es', 'country' => 'PR', 'currency' => 'EUR' );
	$_SERVER = array( 'REMOTE_ADDR' => '203.0.113.15', 'HTTP_CF_IPCOUNTRY' => 'PR', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.2', 'HTTP_ACCEPT_LANGUAGE' => 'es-PR' );
	$beforeRequest = array( $_COOKIE, $_GET, $_SERVER, $GLOBALS['language_test_options'] );
	Country::$calls = array(); Country::$result = array( 'status' => 'known', 'country_code' => 'US' );
	$result = Language::current_request();
	$check( 'current request passes only canonical remote address and ignores browser/query/proxy headers', $result['language'] === 'en' && $result['source'] === 'geoip' && Country::$calls === array( '203.0.113.15' ) );
	$check( 'resolver never writes cookie/query/server/options or locale', $beforeRequest === array( $_COOKIE, $_GET, $_SERVER, $GLOBALS['language_test_options'] ) );
	Country::$calls = array(); Country::$result = array( 'status' => 'known', 'country_code' => 'PR' );
	$result = Language::current_request( $enabled ); $check( 'known Puerto Rico uses configured Spanish', $result['language'] === 'es' && $result['locale'] === 'es_PR' && $result['country_code'] === 'PR' && $result['translation_applied'] === false );
	Country::$calls = array(); $_COOKIE[Language::PREFERENCE_COOKIE] = 'en';
	$result = Language::current_request( $enabled ); $check( 'valid preference skips all GeoIP work', $result['source'] === 'manual' && $result['language'] === 'en' && $result['country_code'] === null && Country::$calls === array() );
	$_COOKIE[Language::PREFERENCE_COOKIE] = 'es'; Country::$calls = array();
	$result = Language::current_request( $defaults ); $check( 'disabled IP detection keeps manual selection and skips GeoIP', $result['source'] === 'manual' && $result['language'] === 'es' && Country::$calls === array() );
	$_COOKIE = array(); $result = Language::current_request( $defaults ); $check( 'disabled without preference skips GeoIP', $result['source'] === 'disabled' && $result['language'] === 'en' && Country::$calls === array() );
	foreach ( array( array( 'es' ), 1, true, 'ES', 'es_PR', ' es ', new \stdClass() ) as $i => $bad ) {
		Country::$calls = array(); $_COOKIE[Language::PREFERENCE_COOKIE] = $bad; Country::$result = array( 'status' => 'known', 'country_code' => 'US' );
		$result = Language::current_request( $enabled ); $check( 'malformed preference ignored without coercion ' . $i, $result['source'] === 'geoip' && $result['language'] === 'en' && Country::$calls === array( '203.0.113.15' ) );
	}
	$_COOKIE = array();
	foreach ( array( array( 'status' => 'unavailable' ), array( 'status' => 'unknown', 'country_code' => 'PR' ), array( 'status' => 'known', 'country_code' => array( 'PR' ) ), array( 'status' => 'known', 'country_code' => 'pr' ) ) as $i => $country ) {
		Country::$result = $country; $result = Language::current_request( $enabled ); $check( 'invalid or unknown GeoIP result falls back ' . $i, $result['source'] === 'default' && $result['language'] === 'en' && $result['country_code'] === null );
	}
	Country::$result = array( 'status' => 'known', 'country_code' => 'DE' );
	$result = Language::current_request( $enabled ); $check( 'unmapped valid country preserves safe metadata and falls back', $result['source'] === 'default' && $result['country_code'] === 'DE' );
	Country::$fail = true; $result = Language::current_request( $enabled ); $check( 'country reader exception stays default and does not expose error or IP', $result === array( 'language' => 'en', 'locale' => 'en_US', 'source' => 'default', 'country_code' => null, 'translation_applied' => false ) ); Country::$fail = false;
	foreach ( array( null, '', array( '203.0.113.15' ) ) as $i => $address ) { Country::$calls = array(); $_SERVER['REMOTE_ADDR'] = $address; $result = Language::current_request( $enabled ); $check( 'missing or malformed remote address does not call provider ' . $i, Country::$calls === array() && $result['source'] === 'default' ); }
	$_SERVER['REMOTE_ADDR'] = '203.0.113.15'; $_COOKIE = array( Language::PREFERENCE_COOKIE => 'es' ); Country::$calls = array();
	$response = Controller::effective( new WP_REST_Request() );
	$expectedHeaders = array( 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'Expires' => '0', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff' );
	$check( 'GET controller emits config and choice but never claims translation', $response instanceof WP_REST_Response && $response->data['config'] === Language::public_config( $enabled ) && $response->data['selection']['source'] === 'manual' && $response->data['selection']['language'] === 'es' && $response->data['selection']['translation_applied'] === false && Country::$calls === array() );
	$check( 'success response private no-store header set exact', $response->get_headers() === $expectedHeaders );
	foreach ( array( array( 'language' => 'es' ), array( 'country' => 'PR' ), array( 'ip' => '203.0.113.15' ), array( '_locale' => array( 'es' ) ), array( '_fields' => 'selection' ) ) as $i => $query ) {
		$beforeReads = $GLOBALS['language_test_option_reads']; $error = Controller::effective( new WP_REST_Request( $query ) );
		$check( 'unsupported query rejected before reads ' . $i, $error instanceof WP_Error && $error->data['status'] === 400 && $GLOBALS['language_test_option_reads'] === $beforeReads );
	}
	$check( 'scalar locale query is metadata only and accepted', Controller::effective( new WP_REST_Request( array( '_locale' => 'user' ) ) ) instanceof WP_REST_Response );
	$errorResponse = new WP_REST_Response( array( 'code' => 'pc_storefront_language_query' ), 400 );
	$errorResponse->set_headers( array( 'cache-control' => 'public', 'VARY' => 'Origin', 'vary' => 'Accept-Language', 'Other' => 'preserved' ) );
	Controller::private_headers( $errorResponse, null, new WP_REST_Request() );
	$check( 'error privacy headers preserve existing Vary dependencies without duplicate header cases', $errorResponse->get_headers() === array_merge( array( 'Other' => 'preserved' ), array_replace( $expectedHeaders, array( 'Vary' => 'Origin, Accept-Language, Cookie' ) ) ) && $errorResponse->status === 400 );
	$deduplicated = new WP_REST_Response(); $deduplicated->set_headers( array( 'Vary' => 'Origin, COOKIE', 'vary' => 'origin, Accept-Language, cookie' ) ); Controller::private_headers( $deduplicated, null, new WP_REST_Request() );
	$check( 'Vary dependency names de-duplicate case-insensitively', $deduplicated->get_headers()['Vary'] === 'origin, Cookie, Accept-Language' );
	$wildcard = new WP_REST_Response(); $wildcard->set_headers( array( 'Vary' => '*', 'vary' => 'Origin' ) ); Controller::private_headers( $wildcard, null, new WP_REST_Request() );
	$check( 'Vary wildcard stays wildcard', $wildcard->get_headers()['Vary'] === '*' && count( array_filter( array_keys( $wildcard->get_headers() ), static fn( $key ) => strtolower( $key ) === 'vary' ) ) === 1 );
	$unrelated = new WP_REST_Response(); $unrelated->header( 'Vary', 'Origin' ); Controller::private_headers( $unrelated, null, new WP_REST_Request( array(), '/peptide/v1/site' ) );
	$check( 'unrelated REST response headers untouched', $unrelated->get_headers() === array( 'Vary' => 'Origin' ) );
	Controller::register_hooks(); Controller::register_routes();
	$check( 'registration only REST route and response header hooks, no locale or preference hooks', count( $GLOBALS['language_test_hooks'] ) === 2 && $GLOBALS['language_test_hooks'][0][1] === 'rest_api_init' && $GLOBALS['language_test_hooks'][1][1] === 'rest_post_dispatch' );
	$route = $GLOBALS['language_test_routes'][0]; $check( 'public read-only single GET route', count( $GLOBALS['language_test_routes'] ) === 1 && $route[0] === 'peptide/v1' && $route[1] === '/storefront-language' && $route[2]['methods'] === 'GET' && $route[2]['permission_callback'] === '__return_true' );
	$passed = count( array_filter( $checks, static fn( $item ) => $item['pass'] ) );
	echo json_encode( array( 'kind' => 'Pure actual language resolver/controller fixtures; no real geolocation/translation/DB/browser', 'passed' => $passed, 'total' => count( $checks ), 'checks' => $checks ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . PHP_EOL;
	exit( $passed === count( $checks ) ? 0 : 1 );
}
