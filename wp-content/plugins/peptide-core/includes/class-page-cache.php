<?php
/**
 * Opt-in homepage cache for first-time anonymous visitors (stress test item 2).
 *
 * Product ad landings: /product/<slug>/ redirects to /?vc_product=<id>#vc-products,
 * which opens that product's dialog over the homepage. Such a landing (vc_product plus
 * optional tracking tags) is cached per product, keyed by the product ID, for published
 * products only.
 *
 * Ad landings also create copies (2026-11-07): before the page renders, the tracking tags are
 * removed from this request (REQUEST_URI, QUERY_STRING, $_GET), so the stored copy is the same
 * as a plain render. Click-ID cookies (_fbc, pc_ttclid) are set at init from the original link,
 * per visitor, and are never stored. As a fail-closed guard, a copy that still contains any of
 * this visitor's tracking values is not stored.
 *
 * Off unless wp-config.php defines PEPTIDE_CORE_PAGE_CACHE true. When on, a
 * cookie-less (or language-preference-only) anonymous GET/HEAD of exactly "/"
 * with no query string, or only ad-tracking parameters (utm_*, click IDs; see
 * tracking_only()), is answered from a stored copy of the same visitor
 * variant, skipping theme rendering. WordPress, plugins, bot blocking,
 * language/GeoIP resolution and the visitor-ID cookie still run as before;
 * browsers still receive the original private/no-store headers. Every other
 * request, including any visitor with a session, login, cart, history,
 * tracking or opt-out cookie, renders normally. Copies are only ever
 * taken from a plain "/" request.
 *
 * A copy is never stored when the response is not a complete 200 HTML page,
 * DONOTCACHEPAGE is defined, or any cookie other than the visitor ID was set.
 * Pages with ad pixels are stored with their per-view pcPixels configuration
 * replaced by a placeholder; each hit calls Pixels::page_config() again, so every
 * visitor gets fresh event IDs and their own Conversions API page events. Copies expire after the
 * TTL (default 300 s, max 3600 s, well under the nonce lifetime) and are
 * invalidated when posts, products, stock or storefront options change.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Page_Cache {
	public const GENERATION = 'pc_page_cache_generation';
	private const VERSION = 4;
	public const PIXELS_SLOT = '/*pc-page-cache:pixels-config*/';
	private const MAX_BYTES = 4194304;
	private const SKIP_HEADERS = array( 'set-cookie', 'content-length', 'date', 'x-powered-by', 'transfer-encoding', 'x-pc-page-cache', 'age' );

	private static bool $capturing = false;
	/** @var string[]|null Cookie names exactly as the browser sent them, recorded before any code adds to $_COOKIE. */
	private static ?array $sent_cookies = null;
	/** @var array|null Test-only [headers_list, status]; CLI PHP records neither. */
	private static ?array $response_for_tests = null;
	private static ?string $key = null;
	/** @var string[] Tracking "name=value" pairs removed from this request (leak guard). */
	private static array $stripped = array();
	private const CLICK_IDS = array( 'gclid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid', 'fbclid', 'ttclid', 'msclkid', 'twclid', 'li_fat_id' );

	public static function enabled(): bool {
		return defined( 'PEPTIDE_CORE_PAGE_CACHE' ) && true === PEPTIDE_CORE_PAGE_CACHE;
	}

	public static function ttl(): int {
		$ttl = defined( 'PEPTIDE_CORE_PAGE_CACHE_TTL' ) ? (int) PEPTIDE_CORE_PAGE_CACHE_TTL : 300;
		return max( 30, min( 3600, $ttl ) );
	}

	public static function register_hooks(): void {
		if ( ! self::enabled() ) { return; }
		self::$sent_cookies = array_map( 'strval', array_keys( $_COOKIE ) );
		// After every existing template_redirect hook (max 99): redirects, the visitor-ID
		// cookie (1) and the Spanish output buffer (90) behave exactly as on a normal render.
		add_action( 'template_redirect', array( self::class, 'serve_or_capture' ), 100 );
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'woocommerce_update_product', 'woocommerce_update_product_variation',
			'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status',
			'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'wp_update_nav_menu', 'edited_term', 'created_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ), 10, 0 );
		}
		add_action( 'updated_option', array( self::class, 'option_changed' ), 10, 1 );
		add_action( 'added_option', array( self::class, 'option_changed' ), 10, 1 );
	}

	/** Storefront-affecting options invalidate; transients, cron and locks do not. */
	public static function option_changed( $name ): void {
		if ( ! is_string( $name ) || self::GENERATION === $name ) { return; }
		if ( preg_match( '/\A(?:_site)?_transient|\Acron\z|lock|queue|_last_|heartbeat|action_scheduler|\Awc_session|session/i', $name ) ) { return; }
		if ( preg_match( '/\A(?:pc_|peptide|vc_|vialcove|theme_mods_|widget_|sidebars_widgets\z|woocommerce_|blogname\z|blogdescription\z|page_on_front\z|show_on_front\z|stylesheet\z|template\z)/', $name ) ) { self::flush(); }
	}

	public static function flush(): void {
		update_option( self::GENERATION, (string) ( (int) get_option( self::GENERATION, '0' ) + 1 ), true );
	}

	/**
	 * Pure eligibility check over a request snapshot.
	 *
	 * @param array $r method, path, home_path, query, cookies (names), front, paged, logged_in, admin, preview, donotcache.
	 */
	public static function eligible( array $r ): bool {
		if ( ! in_array( $r['method'] ?? '', array( 'GET', 'HEAD' ), true ) ) { return false; }
		$query = (string) ( $r['query'] ?? '' );
		if ( '' !== $query && ! self::tracking_only( $query ) && null === self::entry_product( $query ) ) { return false; }
		if ( ( $r['path'] ?? null ) !== ( $r['home_path'] ?? '/' ) ) { return false; }
		if ( empty( $r['front'] ) || ! empty( $r['paged'] ) || ! empty( $r['logged_in'] ) || ! empty( $r['admin'] ) || ! empty( $r['preview'] ) || ! empty( $r['donotcache'] ) ) { return false; }
		$allowed = array( Storefront_Language::PREFERENCE_COOKIE );
		foreach ( (array) ( $r['cookies'] ?? array() ) as $name ) {
			if ( ! in_array( (string) $name, $allowed, true ) ) { return false; }
		}
		return true;
	}

	/**
	 * True when every query parameter is an ad/analytics tag that no server code renders:
	 * utm_*, Google/Microsoft/Meta/TikTok/X/LinkedIn click IDs. Click-ID cookies (_fbc,
	 * pc_ttclid) are set at init, before this cache, so they still apply on a cached view.
	 * Anything else (coupons, language, previews, filters) renders normally.
	 */
	public static function tracking_only( string $query ): bool {
		if ( '' === $query || strlen( $query ) > 2048 ) { return false; }
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) { continue; }
			if ( ! self::tracking_name( strtolower( urldecode( explode( '=', $pair, 2 )[0] ) ) ) ) { return false; }
		}
		return true;
	}

	public static function tracking_name( string $name ): bool {
		// ref = 推广人代号（Referrals 在 init 读取并写 cookie），和广告参数一样不影响页面内容。
		// placement、ad_name、cr（素材专属链接）= CR 素材归因的 Meta 网址参数模板（Creatives\Contract::URL_TEMPLATE），同样不影响页面。
		return (bool) preg_match( '/\Autm_[a-z_]{1,32}\z/', $name ) || in_array( $name, self::CLICK_IDS, true ) || in_array( $name, array( 'ref', 'placement', 'ad_name', 'cr' ), true ); // ref = Referrals::PARAM，纯函数测试不加载 Referrals。
	}

	/**
	 * Split a query into the part that may change the page and the tracking tags.
	 *
	 * @return array{0:string,1:string[]} [ query without tracking tags, removed "name=value" pairs (decoded) ]
	 */
	public static function split_tracking( string $query ): array {
		$keep = array(); $removed = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) { continue; }
			$parts = explode( '=', $pair, 2 );
			$name = strtolower( urldecode( $parts[0] ) );
			if ( self::tracking_name( $name ) ) { $removed[] = $name . '=' . urldecode( (string) ( $parts[1] ?? '' ) ); } else { $keep[] = $pair; }
		}
		return array( implode( '&', $keep ), $removed );
	}

	/** True when the page still carries one of the removed tracking tags (in any common encoding). */
	public static function leaks( string $html, array $removed ): bool {
		foreach ( $removed as $pair ) {
			[ $name, $value ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
			if ( '' === $value ) { continue; }
			$needles = array( $name . '=' . $value, $name . '=' . rawurlencode( $value ), $name . '=' . urlencode( $value ), $name . '=' . htmlspecialchars( $value, ENT_QUOTES ) );
			// Click IDs are long random strings: their bare value must not appear anywhere either.
			if ( in_array( $name, self::CLICK_IDS, true ) && strlen( $value ) >= 12 ) { $needles[] = $value; }
			foreach ( array_unique( $needles ) as $needle ) {
				if ( false !== stripos( $html, $needle ) ) { return true; }
			}
		}
		return false;
	}

	/** Render this request as if it had no tracking tags (they never change the page itself). */
	private static function strip_tracking_from_request( string $query ): void {
		[ $clean, $removed ] = self::split_tracking( $query );
		self::$stripped = $removed;
		if ( ! $removed ) { return; }
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$_SERVER['REQUEST_URI'] = ( '' !== $path ? $path : '/' ) . ( '' !== $clean ? '?' . $clean : '' );
		$_SERVER['QUERY_STRING'] = $clean;
		foreach ( array_keys( $_GET ) as $key ) {
			if ( is_string( $key ) && self::tracking_name( strtolower( $key ) ) ) { unset( $_GET[ $key ], $_REQUEST[ $key ] ); }
		}
	}

	/**
	 * Product ad landing: exactly one vc_product=<positive id> and otherwise only tracking
	 * tags. Returns the product ID, or null for any other query (including none).
	 */
	public static function entry_product( string $query ): ?int {
		if ( '' === $query || strlen( $query ) > 2048 ) { return null; }
		$id = null; $rest = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) { continue; }
			$parts = explode( '=', $pair, 2 );
			if ( 'vc_product' === strtolower( urldecode( $parts[0] ) ) ) {
				if ( null !== $id || ! preg_match( '/\A[1-9][0-9]{0,9}\z/D', (string) ( $parts[1] ?? '' ) ) || (int) $parts[1] > 2147483647 ) { return null; }
				$id = (int) $parts[1];
				continue;
			}
			$rest[] = $pair;
		}
		if ( null === $id ) { return null; }
		return ( ! $rest || self::tracking_only( implode( '&', $rest ) ) ) ? $id : null;
	}

	/** Only published products get their own copy, so random IDs cannot fill the cache. */
	private static function entry_allowed( int $id ): bool {
		return function_exists( 'get_post_type' ) && 'product' === get_post_type( $id ) && 'publish' === get_post_status( $id );
	}

	/** Visitor variant that may change the rendered HTML. */
	public static function variant(): array {
		$language = Storefront_Translation::selected();
		// Woo has already located this request's guest customer; reuse it instead of geolocating again.
		$customer = function_exists( 'WC' ) && WC()->customer ? WC()->customer : null;
		return array(
			'v' => self::VERSION,
			'host' => strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ),
			'ssl' => is_ssl(),
			'language' => Storefront_Translation::active() ? Storefront_Translation::code() : 'native',
			'locale' => get_locale(),
			'country' => (string) ( $language['country_code'] ?? '' ),
			'customer_country' => $customer ? (string) $customer->get_billing_country() . '|' . (string) $customer->get_shipping_country() : '',
			'device' => function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? 'mobile' : 'desktop',
			'declined' => class_exists( Tracking\Tracker::class ) && Tracking\Tracker::declined(),
			'pixels' => class_exists( Pixels::class ) && Pixels::allowed(),
			'generation' => (string) get_option( self::GENERATION, '0' ),
			'entry' => (int) self::entry_product( (string) ( $_SERVER['QUERY_STRING'] ?? '' ) ),
		);
	}

	public static function key( array $variant ): string {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : '';
		return hash_hmac( 'sha256', wp_json_encode( $variant ), 'pc-page-cache|' . $salt );
	}

	public static function directory(): string {
		return WP_CONTENT_DIR . '/cache/peptide-core-pages';
	}

	private static function file( string $key ): string { return self::directory() . '/' . $key . '.php'; }

	public static function serve_or_capture(): void {
		$request = array(
			'method' => strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ),
			'path' => (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ),
			'home_path' => (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/' ),
			'query' => (string) ( $_SERVER['QUERY_STRING'] ?? '' ),
			// As sent by the browser: a visitor ID issued during this request (template_redirect 1) is not
			// a visitor signal, but one the browser sent means a returning visitor and always bypasses.
			'cookies' => self::$sent_cookies ?? array_keys( $_COOKIE ),
			'front' => is_front_page(), 'paged' => is_paged(), 'logged_in' => is_user_logged_in(),
			'admin' => is_admin(), 'preview' => is_preview() || is_customize_preview(), 'donotcache' => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
		);
		if ( ! self::eligible( $request ) ) {
			if ( ! headers_sent() && $request['front'] ) { header( 'X-PC-Page-Cache: BYPASS' ); }
			return;
		}
		$entry_id = self::entry_product( $request['query'] );
		if ( null !== $entry_id && ! self::entry_allowed( $entry_id ) ) {
			if ( ! headers_sent() ) { header( 'X-PC-Page-Cache: BYPASS' ); }
			return;
		}
		self::$key = self::key( self::variant() );
		$entry = self::read( self::$key );
		if ( null !== $entry && $entry['pixels'] ) {
			// Fresh per-view pixel events and Conversions API send, exactly as enqueue() would.
			$config = class_exists( Pixels::class ) ? Pixels::page_config() : null;
			if ( null === $config ) { return; } // Render normally; never serve a page without its events.
			$entry['body'] = str_replace( self::PIXELS_SLOT, Pixels::localized_statement( $config ), $entry['body'] );
		}
		if ( null !== $entry && ! headers_sent() ) {
			$age = max( 0, time() - $entry['created'] );
			foreach ( self::replay_headers( $entry['headers'] ) as [ $line, $replace ] ) { header( $line, $replace ); }
			header( 'X-PC-Page-Cache: HIT' );
			header( 'Age: ' . $age );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stored complete page.
			if ( 'HEAD' !== $request['method'] ) { echo self::refresh_server_time( $entry['body'], $age ); }
			exit;
		}
		if ( headers_sent() ) { return; }
		// Any eligible landing may create the copy; its tracking tags are removed first so the
		// copy equals a plain "/" (or "/?vc_product=<id>") render.
		self::strip_tracking_from_request( $request['query'] );
		header( 'X-PC-Page-Cache: MISS' );
		self::$capturing = true;
		ob_start( array( self::class, 'capture' ) );
	}

	/** Output-buffer callback; always returns the page unchanged. */
	public static function capture( $html, int $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		if ( ! self::$capturing || null === self::$key || ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ! is_string( $html ) ) { return $html; }
		self::$capturing = false;
		try {
			[ $raw, $status ] = self::$response_for_tests ?? array( headers_list(), http_response_code() );
			$headers = self::storable_headers( $raw, $status, $html );
			$pixels = function_exists( 'wp_script_is' ) && wp_script_is( 'peptide-core-pixels', 'enqueued' );
			$body = $pixels ? self::punch_pixels( $html ) : $html;
			if ( null !== $body && self::$stripped && self::leaks( $body, self::$stripped ) ) { $body = null; } // Fail closed.
			if ( null !== $headers && null !== $body ) { self::write( self::$key, array( 'created' => time(), 'headers' => $headers, 'body' => $body, 'pixels' => $pixels ) ); }
		} catch ( \Throwable $ignored ) { /* Never affect the visitor's response. */ }
		return $html;
	}

	/**
	 * Headers to replay, or null when this response must not be stored.
	 *
	 * @param string[] $raw Lines from headers_list().
	 */
	public static function storable_headers( array $raw, $status, string $html ): ?array {
		if ( 200 !== (int) $status || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) { return null; }
		$size = strlen( $html );
		if ( $size < 512 || $size > self::MAX_BYTES || false === stripos( $html, '</html>' ) ) { return null; }
		$allowed_cookies = self::issued_this_request();
		$out = array(); $html_type = false;
		foreach ( $raw as $line ) {
			$parts = explode( ':', (string) $line, 2 );
			if ( 2 !== count( $parts ) ) { continue; }
			$name = trim( $parts[0] ); $value = trim( $parts[1] ); $lower = strtolower( $name );
			if ( 'set-cookie' === $lower ) {
				$cookie = trim( explode( '=', $value, 2 )[0] );
				if ( ! in_array( $cookie, $allowed_cookies, true ) ) { return null; }
				continue;
			}
			if ( 'content-type' === $lower ) { $html_type = str_starts_with( strtolower( $value ), 'text/html' ); }
			if ( in_array( $lower, self::SKIP_HEADERS, true ) ) { continue; }
			$out[] = array( $name, $value );
		}
		return $html_type ? $out : null;
	}

	/**
	 * Reproduce the captured header list exactly: the first header of each name replaces
	 * what WordPress already queued this request, later ones with the same name append.
	 *
	 * @return array<int, array{0:string,1:bool}>
	 */
	public static function replay_headers( array $headers ): array {
		$seen = array(); $out = array();
		foreach ( $headers as [ $name, $value ] ) {
			$lower = strtolower( $name );
			$out[] = array( $name . ': ' . $value, ! isset( $seen[ $lower ] ) );
			$seen[ $lower ] = true;
		}
		return $out;
	}

	/**
	 * Server clocks printed into the page ("serverNow": Unix seconds, used by the campaign
	 * countdown) were exact at capture; advance them by the copy's age so they are exact now.
	 */
	public static function refresh_server_time( string $html, int $age ): string {
		if ( $age <= 0 ) { return $html; }
		$out = preg_replace_callback( '/"serverNow":(\d{9,11})(?=[,}])/', static fn( $m ) => '"serverNow":' . ( (int) $m[1] + $age ), $html );
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Replace this view's one-line pcPixels statement (printed by wp_localize_script inside
	 * <script id="peptide-core-pixels-js-extra">) with a placeholder. Null when it is not
	 * found exactly once, or the page already contains the placeholder: then nothing is stored.
	 */
	public static function punch_pixels( string $html ): ?string {
		if ( str_contains( $html, self::PIXELS_SLOT ) ) { return null; }
		if ( 1 !== preg_match_all( '/<script\b[^>]*\bid=(["\'])peptide-core-pixels-js-extra\1[^>]*>.*?<\/script>/is', $html, $blocks, PREG_OFFSET_CAPTURE ) ) { return null; }
		[ $block, $offset ] = $blocks[0][0];
		$punched = preg_replace( '/^var pcPixels = \{.*\};$/m', self::PIXELS_SLOT, $block, -1, $count );
		if ( 1 !== $count || ! is_string( $punched ) ) { return null; }
		return substr_replace( $html, $punched, $offset, strlen( $block ) );
	}

	/** Cookies this code path may legitimately set on a first visit: the anonymous visitor ID. */
	private static function issued_this_request(): array {
		// Visitor ID plus the click-ID cookies Pixels::remember_click_ids() sets at init from the
		// landing link. Set-Cookie is never stored; on a cached view init sets them per visitor again.
		return array_merge( class_exists( Tracking\Tracker::class ) ? array( Tracking\Tracker::COOKIE ) : array(), array( '_fbc', 'pc_ttclid', 'pc_ref' ) );
	}

	private static function read( string $key ): ?array {
		$entry = self::read_entry( self::file( $key ), self::ttl() );
		if ( null === $entry || ! is_array( $entry['headers'] ?? null ) || ! is_string( $entry['body'] ?? null ) || ! is_bool( $entry['pixels'] ?? null ) ) { return null; }
		return $entry;
	}

	private static function write( string $key, array $entry ): void {
		self::write_entry( self::directory(), self::file( $key ), $entry );
	}

	/** A stored array with an int 'created' not older than $ttl seconds, or null. Shared with Fragment_Cache. */
	public static function read_entry( string $file, int $ttl ): ?array {
		if ( ! is_readable( $file ) ) { return null; }
		$raw = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$prefix = "<?php exit; ?>\n";
		if ( ! is_string( $raw ) || ! str_starts_with( $raw, $prefix ) ) { return null; }
		$entry = @unserialize( substr( $raw, strlen( $prefix ) ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $entry ) || ! is_int( $entry['created'] ?? null ) ) { return null; }
		if ( time() - $entry['created'] >= $ttl || $entry['created'] > time() + 5 ) { return null; }
		return $entry;
	}

	/** Atomic write (temp file + rename) behind a PHP exit guard. Shared with Fragment_Cache. */
	public static function write_entry( string $dir, string $file, array $entry ): void {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return; }
		if ( ! file_exists( $dir . '/index.php' ) ) { @file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$tmp = $dir . '/.' . basename( $file ) . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		// The PHP exit prefix keeps a direct web request from returning the stored copy.
		if ( false === @file_put_contents( $tmp, "<?php exit; ?>\n" . serialize( $entry ), LOCK_EX ) ) { return; } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! @rename( $tmp, $file ) ) { @unlink( $tmp ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Test-only state reset. */
	public static function reset_for_tests( ?array $sent_cookies = null, ?array $response = null ): void { self::$capturing = false; self::$key = null; self::$stripped = array(); self::$sent_cookies = $sent_cookies; self::$response_for_tests = $response; }
}
