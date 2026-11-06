<?php
/**
 * Section ("fragment") cache for expensive, visitor-independent storefront HTML:
 * the homepage product catalogue grid and the header product menu.
 *
 * Unlike Page_Cache this also serves returning visitors and ad landings, because
 * only the named section is reused while the rest of the page renders normally.
 * It is enabled by the same PEPTIDE_CORE_PAGE_CACHE switch.
 *
 * A stored section is skipped (rendered normally) for logged-in visitors, visitors
 * with a known checkout email, previews, and whenever any active promotion targets
 * specific customers, so customer-dependent prices are never reused. The key covers
 * the caller's own inputs, language/locale/currency/device/host, the active promotion
 * set, the content generation (Page_Cache::GENERATION) and the page's auto-ID position.
 *
 * Side effects are replayed exactly: scripts/styles the section enqueued are enqueued
 * again, and WordPress's wp_unique_id() counter is advanced by the same amount, so the
 * rest of the page gets identical IDs. A section that registers new assets or changes
 * asset data while rendering is never stored.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Fragment_Cache {
	private const VERSION = 1;
	private const MAX_BYTES = 2097152;

	/** @var array<int, array{0:string,1:string}> name => outcome, for diagnostics and tests. */
	public static array $log = array();
	private static ?bool $personalized = null;

	public static function enabled(): bool {
		return Page_Cache::enabled();
	}

	public static function directory(): string {
		return WP_CONTENT_DIR . '/cache/peptide-core-fragments';
	}

	/**
	 * Return the section HTML, from the store when safe, otherwise by calling $render.
	 *
	 * @param string   $name   Section name.
	 * @param array    $inputs Everything the caller passes to the template (settings, ids, context).
	 * @param callable $render Returns the section HTML.
	 */
	public static function remember( string $name, array $inputs, callable $render ): string {
		$skip = self::skip_reason();
		if ( null !== $skip ) {
			self::$log[] = array( $name, 'skip:' . $skip );
			return (string) $render();
		}
		$start = self::uid_probe();
		$key = self::key( $name, $inputs, $start );
		$entry = Page_Cache::read_entry( self::file( $key ), Page_Cache::ttl() );
		if ( null !== $entry && is_string( $entry['html'] ?? null ) && is_int( $entry['uids'] ?? null ) && is_array( $entry['scripts'] ?? null ) && is_array( $entry['styles'] ?? null ) ) {
			foreach ( $entry['scripts'] as $handle ) { if ( is_string( $handle ) && wp_script_is( $handle, 'registered' ) ) { wp_enqueue_script( $handle ); } }
			foreach ( $entry['styles'] as $handle ) { if ( is_string( $handle ) && wp_style_is( $handle, 'registered' ) ) { wp_enqueue_style( $handle ); } }
			for ( $i = 0; $i < $entry['uids']; $i++ ) { wp_unique_id(); }
			self::$log[] = array( $name, 'hit' );
			return $entry['html'];
		}
		$before = self::assets_state();
		$html = (string) $render();
		$after = self::assets_state();
		$uids = self::uid_probe() - $start;
		$diff = self::assets_diff( $before, $after );
		if ( null === $diff || strlen( $html ) > self::MAX_BYTES ) {
			self::$log[] = array( $name, 'miss:not-storable' );
			return $html;
		}
		Page_Cache::write_entry( self::directory(), self::file( $key ), array( 'created' => time(), 'html' => $html, 'uids' => $uids, 'scripts' => $diff['scripts'], 'styles' => $diff['styles'] ) );
		self::$log[] = array( $name, 'miss:stored' );
		return $html;
	}

	/** Why this request must render the section itself, or null when the store may be used. */
	public static function skip_reason(): ?string {
		if ( ! self::enabled() ) { return 'disabled'; }
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return 'context'; }
		if ( is_user_logged_in() ) { return 'logged-in'; }
		if ( is_preview() || is_customize_preview() || ( class_exists( Templates::class ) && Templates::previewing_id() ) ) { return 'preview'; }
		if ( class_exists( Customer_Segments::class ) && '' !== Customer_Segments::current_email() ) { return 'customer-email'; }
		if ( self::personalized_promotions() ) { return 'personalized-promotion'; }
		return null;
	}

	/** True when an active promotion applies only to some customers (email, level, tag or visitor). */
	public static function personalized_promotions(): bool {
		if ( null !== self::$personalized ) { return self::$personalized; }
		self::$personalized = false;
		if ( class_exists( Promotions::class ) ) {
			foreach ( Promotions::active_ids() as $id ) {
				$rule = Promotions::config( (int) $id )['customer_rule'] ?? array();
				if ( 'all' !== ( $rule['customers'] ?? 'all' ) ) { self::$personalized = true; break; }
			}
		}
		return self::$personalized;
	}

	public static function key( string $name, array $inputs, int $uid_start ): string {
		$language = class_exists( Storefront_Translation::class ) ? Storefront_Translation::selected() : array();
		$variant = array(
			'v' => self::VERSION, 'name' => $name, 'inputs' => $inputs, 'uid' => $uid_start,
			'host' => strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ), 'ssl' => is_ssl(),
			'language' => class_exists( Storefront_Translation::class ) && Storefront_Translation::active() ? Storefront_Translation::code() : 'native',
			'country' => (string) ( $language['country_code'] ?? '' ), 'locale' => get_locale(),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'device' => function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? 'mobile' : 'desktop',
			'promotions' => class_exists( Promotions::class ) ? array_map( 'intval', Promotions::active_ids() ) : array(),
			'generation' => (string) get_option( Page_Cache::GENERATION, '0' ),
		);
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : '';
		return hash_hmac( 'sha256', (string) wp_json_encode( $variant ), 'pc-fragment-cache|' . $salt );
	}

	private static function file( string $key ): string { return self::directory() . '/' . $key . '.php'; }

	/** Current wp_unique_id() position (consumes one ID, identically on store and reuse). */
	private static function uid_probe(): int {
		return (int) substr( wp_unique_id( 'pcfc-' ), 5 );
	}

	/** Queue and a fingerprint of every registered script/style (src, deps, version, args, inline data). */
	public static function assets_state(): array {
		$state = array();
		foreach ( array( 'scripts' => wp_scripts(), 'styles' => wp_styles() ) as $type => $deps ) {
			$registered = array();
			foreach ( $deps->registered as $handle => $dep ) {
				$registered[ $handle ] = md5( serialize( array( $dep->src, $dep->deps, $dep->ver, $dep->args, $dep->extra ) ) );
			}
			$state[ $type ] = array( 'queue' => array_values( $deps->queue ), 'registered' => $registered );
		}
		return $state;
	}

	/**
	 * Handles newly enqueued by the section, or null when it did anything else
	 * (registered or changed an asset, added inline data, dequeued) that a stored copy could not replay.
	 */
	public static function assets_diff( array $before, array $after ): ?array {
		$out = array();
		foreach ( array( 'scripts', 'styles' ) as $type ) {
			if ( $before[ $type ]['registered'] !== $after[ $type ]['registered'] ) { return null; }
			if ( array_diff( $before[ $type ]['queue'], $after[ $type ]['queue'] ) ) { return null; }
			$out[ $type ] = array_values( array_diff( $after[ $type ]['queue'], $before[ $type ]['queue'] ) );
		}
		return $out;
	}

	/** Test-only state reset. */
	public static function reset_for_tests(): void { self::$log = array(); self::$personalized = null; }
}
