<?php
/**
 * Configuration Manager, Cloak-Verified Real IP Extractor, Safe-Page/Bot Guard & Gateway Transport (V3.7).
 *
 * - Priority #1 buyer IP: `$GLOBALS['wprlo_verified_client_ip']` -> Cloudflare CIDR `CF-Connecting-IP` -> right-to-left peeled `X-Forwarded-For` -> `X-Real-IP` -> public non-server `REMOTE_ADDR`.
 * - Rejects private/loopback/Cloudflare-edge/server public IPs (`54.144.250.46`, `34.231.148.231`, `18.221.238.49`) from ever leaking as buyer `client_ip`.
 * - Lossless raw `fbclid` extraction preserving exact uppercase/lowercase characters.
 * - Defense-in-depth Safe Page & Crawler/Bot zero-reporting guard.
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Config_Manager {

	public const OPTION_NETWORK = 'stealth_capi_network_config';
	public const OPTION_SITE    = 'stealth_capi_site_config';
	public const FBP_REGEX      = '/^fb\.[012]\.\d{10,16}\.\d{8,16}$/';
	public const FBC_REGEX      = '/^fb\.[012]\.\d{10,16}\.[A-Za-z0-9._\-]{1,512}$/';
	public const FBP_FBC_REGEX  = '/^fb\.[012]\.\d{10,16}\.[A-Za-z0-9._\-]{1,512}$/';
	public const EID_REGEX      = '/^[A-Za-z0-9_.\-]{6,128}$/';

	public const MODE_AD_ACQUISITION        = 'AD_ACQUISITION';
	public const MODE_ORGANIC_RETENTION_HUB = 'ORGANIC_RETENTION_HUB';

	public const CLOUDFLARE_CIDRS = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	public const LOCAL_PROXY_CIDRS = array(
		'127.0.0.1/32',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'::1/128',
		'fc00::/7',
	);

	public const DEFAULT_BLOCKED_SERVER_IPS = array(
		'54.144.250.46',
		'34.231.148.231',
		'18.221.238.49',
	);

	private const SAFE_URL_MARKERS = array(
		'capi_safe_preview',
		'wprlo_preview',
		'safe_theme=',
		'/ui-themes/',
		'/safe-page',
		'/regional-fallback',
	);

	private const BOT_UA_MARKERS = array(
		'facebookexternalhit',
		'facebot',
		'meta-externalagent',
		'meta-externalfetcher',
		'adsbot-google',
		'mediapartners-google',
		'apis-google',
		'googlebot',
		'bingbot',
		'tiktokspider',
		'bytespider',
		'twitterbot',
		'pinterestbot',
		'headlesschrome',
		'phantomjs',
		'playwright',
		'puppeteer',
		'selenium',
		'curl/',
		'wget/',
		'python-requests',
		'python-httpx',
		'aiohttp/',
		'go-http-client',
		'scrapy',
		'petalbot',
		'ahrefsbot',
		'semrushbot',
	);

	public static function get_stores_catalog(): array {
		return array(
			'site_03'           => array( 'blog_id' => 1, 'store_code' => 's0008', 'zone_code' => 'S1', 'buyer' => '光辉', 'label' => 'S1 · 光辉' ),
			'site_04'           => array( 'blog_id' => 1, 'store_code' => 's0009', 'zone_code' => 'S2', 'buyer' => '老毛', 'label' => 'S2 · 老毛' ),
			'site_05'           => array( 'blog_id' => 1, 'store_code' => 's0010', 'zone_code' => 'S3', 'buyer' => '芒果', 'label' => 'S3 · 芒果' ),
			'site_01'           => array( 'blog_id' => 1, 'store_code' => 's0006', 'zone_code' => 'S4', 'buyer' => '李军', 'label' => 'S4 · 李军' ),
			'site_02'           => array( 'blog_id' => 1, 'store_code' => 's0007', 'zone_code' => 'S5', 'buyer' => '阳', 'label' => 'S5 · 阳' ),
			'site_06'           => array( 'blog_id' => 1, 'store_code' => 's0001', 'zone_code' => 'S6', 'buyer' => '地瓜', 'label' => 'S6 · 地瓜' ),
			'repurchase_hub_02' => array( 'blog_id' => 1, 'store_code' => 'hub_02', 'zone_code' => 'HUB', 'buyer' => '复购站', 'label' => '私域老客纯复购站（前台零像素 · 后台反哺原店）' ),
		);
	}

	public static function get_config(): array {
		$defaults = array(
			'enabled'                    => true,
			'gateway_base_url'           => 'http://18.221.238.49',
			'admin_master_key'           => '',
			'timeout_seconds'            => 2.0,
			'suppress_legacy_meta'       => true,
			'auto_enqueue_sdk'           => true,
			'enable_server_shallow'      => true,
			'enable_historical_backfill' => false,
			'reconcile_max_paid_age_sec' => 86400,
			'trust_private_proxies'      => true,
			'trusted_proxies'            => array(),
			'blocked_server_ips'         => self::DEFAULT_BLOCKED_SERVER_IPS,
			'default_tenant'             => array(
				'enabled'               => false,
				'blog_id'               => 1,
				'site_key'              => '',
				'api_secret'            => '',
				'store_code'            => '',
				'tenant_mode'           => self::MODE_AD_ACQUISITION,
				'is_repurchase_station' => false,
			),
			'tenants_by_blog_id'         => array(),
			'tenants_by_domain'          => array(),
		);

		$stored = array();
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$net = get_site_option( self::OPTION_NETWORK, array() );
			if ( is_array( $net ) ) {
				$stored = $net;
			}
		}
		$site_opt = get_option( self::OPTION_SITE, array() );
		if ( is_array( $site_opt ) && ! empty( $site_opt ) ) {
			$stored = array_replace_recursive( $stored, $site_opt );
		}

		$raw_override = '';
		if ( defined( 'STEALTH_CAPI_CONFIG_JSON' ) && is_string( STEALTH_CAPI_CONFIG_JSON ) ) {
			$raw_override = STEALTH_CAPI_CONFIG_JSON;
		} elseif ( getenv( 'STEALTH_CAPI_CONFIG_JSON' ) !== false ) {
			$raw_override = (string) getenv( 'STEALTH_CAPI_CONFIG_JSON' );
		}

		if ( '' !== trim( $raw_override ) ) {
			$decoded = json_decode( $raw_override, true );
			if ( is_array( $decoded ) ) {
				$stored = array_replace_recursive( $stored, $decoded );
			}
		}

		$cfg                    = array_replace_recursive( $defaults, $stored );
		$cfg['timeout_seconds'] = min( 2.0, max( 0.5, (float) $cfg['timeout_seconds'] ) );
		return $cfg;
	}

	public static function save_config( array $new_config ): bool {
		$current = get_option( self::OPTION_SITE, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = array_replace_recursive( $current, $new_config );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			update_site_option( self::OPTION_NETWORK, $merged );
		}
		return update_option( self::OPTION_SITE, $merged, false );
	}

	public static function resolve_current_tenant( ?int $explicit_blog_id = null ): ?array {
		$cfg = self::get_config();
		if ( empty( $cfg['enabled'] ) ) {
			return null;
		}

		$wp_blog_id = $explicit_blog_id ?? ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 );
		$host       = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';
		$host_bare  = preg_replace( '/^www\./', '', $host );

		$candidate = null;
		if ( $host_bare && isset( $cfg['tenants_by_domain'][ $host_bare ] ) && is_array( $cfg['tenants_by_domain'][ $host_bare ] ) ) {
			$candidate = $cfg['tenants_by_domain'][ $host_bare ];
		} elseif ( isset( $cfg['tenants_by_blog_id'][ (string) $wp_blog_id ] ) && is_array( $cfg['tenants_by_blog_id'][ (string) $wp_blog_id ] ) ) {
			$candidate = $cfg['tenants_by_blog_id'][ (string) $wp_blog_id ];
		} elseif ( isset( $cfg['tenants_by_blog_id'][ $wp_blog_id ] ) && is_array( $cfg['tenants_by_blog_id'][ $wp_blog_id ] ) ) {
			$candidate = $cfg['tenants_by_blog_id'][ $wp_blog_id ];
		} elseif ( ! empty( $cfg['default_tenant']['site_key'] ) ) {
			$candidate = $cfg['default_tenant'];
		}

		if ( ! is_array( $candidate ) ) {
			return null;
		}
		if ( isset( $candidate['enabled'] ) && ! $candidate['enabled'] ) {
			return null;
		}
		if ( empty( $candidate['site_key'] ) || empty( $candidate['api_secret'] ) ) {
			return null;
		}

		$raw_mode      = strtoupper( trim( (string) ( $candidate['tenant_mode'] ?? $candidate['site_role'] ?? '' ) ) );
		$is_repurchase = ! empty( $candidate['is_repurchase_station'] ) || ( self::MODE_ORGANIC_RETENTION_HUB === $raw_mode );
		$tenant_mode   = $is_repurchase ? self::MODE_ORGANIC_RETENTION_HUB : self::MODE_AD_ACQUISITION;

		return array(
			'site_key'              => (string) $candidate['site_key'],
			'api_secret'            => (string) $candidate['api_secret'],
			'blog_id'               => isset( $candidate['blog_id'] ) ? (int) $candidate['blog_id'] : $wp_blog_id,
			'store_code'            => isset( $candidate['store_code'] ) ? (string) $candidate['store_code'] : '',
			'tenant_mode'           => $tenant_mode,
			'site_role'             => $tenant_mode,
			'is_repurchase_station' => $is_repurchase,
		);
	}

	public static function is_organic_retention_hub( ?array $tenant = null ): bool {
		$tenant = $tenant ?? self::resolve_current_tenant();
		if ( ! $tenant ) {
			return false;
		}
		return ( self::MODE_ORGANIC_RETENTION_HUB === ( $tenant['tenant_mode'] ?? '' ) ) || ! empty( $tenant['is_repurchase_station'] );
	}

	/**
	 * Coordinated WPRLO edge-router blockade check.
	 */
	public static function is_blocked_by_edge_router( bool $active_probe = false ): bool {
		if ( ! empty( $GLOBALS['wprlo_blocked_request'] ) || ! empty( $GLOBALS['wprlo_traffic_intercepted'] ) ) {
			return true;
		}
		if ( $active_probe && function_exists( 'wprlo_check_traffic_target' ) ) {
			if ( false !== wprlo_check_traffic_target() ) {
				$GLOBALS['wprlo_blocked_request'] = true;
				return true;
			}
		}
		return false;
	}

	/**
	 * Unified Safe Page & Crawler/Bot zero-reporting guard.
	 */
	public static function is_safe_or_bot_request( bool $active_probe = false, string $candidate_url = '', string $candidate_ua = '' ): bool {
		if ( self::is_blocked_by_edge_router( $active_probe ) ) {
			return true;
		}

		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array( $req_uri, $referer, $candidate_url ) as $u ) {
			if ( '' === $u ) {
				continue;
			}
			$low_u = strtolower( $u );
			foreach ( self::SAFE_URL_MARKERS as $marker ) {
				if ( false !== strpos( $low_u, $marker ) ) {
					return true;
				}
			}
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			if ( '' !== $path && preg_match( '#/(?:index|about|privacy|terms|refund|contact|shipping|faq|returns|policy)\.html$#i', $path ) ) {
				return true;
			}
		}

		$ua = '' !== $candidate_ua
			? $candidate_ua
			: ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $ua ) {
			$low_ua = strtolower( $ua );
			foreach ( self::BOT_UA_MARKERS as $bot_kw ) {
				if ( false !== strpos( $low_ua, $bot_kw ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function get_blocked_server_ips(): array {
		$cfg  = self::get_config();
		$list = self::DEFAULT_BLOCKED_SERVER_IPS;
		if ( ! empty( $cfg['blocked_server_ips'] ) ) {
			if ( is_string( $cfg['blocked_server_ips'] ) ) {
				$list = array_merge( $list, explode( ',', $cfg['blocked_server_ips'] ) );
			} elseif ( is_array( $cfg['blocked_server_ips'] ) ) {
				$list = array_merge( $list, $cfg['blocked_server_ips'] );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'trim', $list ) ) ) );
	}

	/**
	 * Verify an IP is a genuine public end-user IP (not private/reserved, not Cloudflare edge, not our own AWS server IP).
	 */
	public static function is_valid_public_buyer_ip( string $ip ): bool {
		$ip = trim( $ip );
		if ( '' === $ip ) {
			return false;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( in_array( $ip, self::get_blocked_server_ips(), true ) ) {
			return false;
		}
		if ( self::ip_in_cidrs( $ip, self::CLOUDFLARE_CIDRS ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Extract real buyer public IP at browser interaction / checkout moment.
	 *
	 * Priority:
	 * 1. `$GLOBALS['wprlo_verified_client_ip']` (from Cloak edge router at 1st ms, validated as public non-server IP)
	 * 2. When `REMOTE_ADDR` is Cloudflare / local reverse proxy / configured trusted proxy:
	 *    `CF-Connecting-IP` -> right-to-left peeled `X-Forwarded-For` -> `X-Real-IP`
	 * 3. Public non-server `REMOTE_ADDR` (preserves full 128-bit IPv6)
	 */
	public static function extract_buyer_ip(): string {
		if ( ! empty( $GLOBALS['wprlo_verified_client_ip'] ) && is_string( $GLOBALS['wprlo_verified_client_ip'] ) ) {
			$cloak_ip = trim( $GLOBALS['wprlo_verified_client_ip'] );
			if ( self::is_valid_public_buyer_ip( $cloak_ip ) ) {
				return $cloak_ip;
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( '' === $remote || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$trusted_hops = array_merge( self::CLOUDFLARE_CIDRS, self::get_trusted_proxy_list() );
		if ( self::ip_in_cidrs( $remote, $trusted_hops ) ) {
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$cf_ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
				if ( self::is_valid_public_buyer_ip( $cf_ip ) ) {
					return $cf_ip;
				}
			}

			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$xff_parts = array_values(
					array_filter(
						array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) )
					)
				);
				// Right-to-left peel of trusted proxies/Cloudflare/private hops to get authentic public client hop:
				for ( $i = count( $xff_parts ) - 1; $i >= 0; $i-- ) {
					$hop = $xff_parts[ $i ];
					if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
						continue;
					}
					if ( self::ip_in_cidrs( $hop, $trusted_hops ) || self::ip_in_cidrs( $hop, self::LOCAL_PROXY_CIDRS ) ) {
						continue;
					}
					if ( self::is_valid_public_buyer_ip( $hop ) ) {
						return $hop;
					}
				}
				// Fallback to first valid public buyer hop from left:
				foreach ( $xff_parts as $hop ) {
					if ( self::is_valid_public_buyer_ip( $hop ) ) {
						return $hop;
					}
				}
			}

			if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$real_ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ) );
				if ( self::is_valid_public_buyer_ip( $real_ip ) ) {
					return $real_ip;
				}
			}
		}

		return self::is_valid_public_buyer_ip( $remote ) ? $remote : '';
	}

	public static function get_trusted_proxy_list(): array {
		$cfg  = self::get_config();
		$list = self::LOCAL_PROXY_CIDRS;

		if ( defined( 'PEPTIDE_CORE_TRUSTED_PROXIES' ) && is_string( PEPTIDE_CORE_TRUSTED_PROXIES ) ) {
			$list = array_merge( $list, explode( ',', PEPTIDE_CORE_TRUSTED_PROXIES ) );
		}
		if ( ! empty( $cfg['trusted_proxies'] ) ) {
			if ( is_string( $cfg['trusted_proxies'] ) ) {
				$list = array_merge( $list, explode( ',', $cfg['trusted_proxies'] ) );
			} elseif ( is_array( $cfg['trusted_proxies'] ) ) {
				$list = array_merge( $list, $cfg['trusted_proxies'] );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $list ) ) ) );
	}

	public static function ip_in_cidrs( string $ip, array $cidrs ): bool {
		$ip_bin = @inet_pton( $ip );
		if ( false === $ip_bin ) {
			return false;
		}
		$ip_len = strlen( $ip_bin );

		foreach ( $cidrs as $entry ) {
			if ( '*' === $entry ) {
				return true;
			}
			if ( false === strpos( $entry, '/' ) ) {
				$net_bin = @inet_pton( $entry );
				if ( false !== $net_bin && $net_bin === $ip_bin ) {
					return true;
				}
				continue;
			}
			list( $subnet, $bits_str ) = explode( '/', $entry, 2 );
			$net_bin = @inet_pton( trim( $subnet ) );
			if ( false === $net_bin || strlen( $net_bin ) !== $ip_len ) {
				continue;
			}
			$bits     = (int) $bits_str;
			$max_bits = $ip_len * 8;
			if ( $bits < 0 || $bits > $max_bits ) {
				continue;
			}
			$full_bytes = intdiv( $bits, 8 );
			$rem_bits   = $bits % 8;

			if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $net_bin, 0, $full_bytes ) ) {
				continue;
			}
			if ( $rem_bits > 0 ) {
				$mask = ( 0xFF << ( 8 - $rem_bits ) ) & 0xFF;
				if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) !== ( ord( $net_bin[ $full_bytes ] ) & $mask ) ) {
					continue;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Lossless raw `fbclid` extractor from original query string (zero strtolower, zero parse_str space mutation, zero truncation).
	 */
	public static function extract_raw_fbclid( ?string $query_string = null ): string {
		$qs = null !== $query_string
			? $query_string
			: ( isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $qs && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$qs = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		if ( '' !== $qs && preg_match( '/(?:^|&)fbclid=([^&#]+)/', $qs, $m ) ) {
			$decoded = trim( rawurldecode( $m[1] ) );
			if ( '' !== $decoded && preg_match( '/^[A-Za-z0-9._\-]{1,512}$/', $decoded ) ) {
				return $decoded;
			}
		}
		return '';
	}

	public static function sanitize_fbp_cookie( string $val ): string {
		$val = trim( $val );
		return ( '' !== $val && preg_match( self::FBP_REGEX, $val ) ) ? $val : '';
	}

	public static function sanitize_fbc_cookie( string $val ): string {
		$val = trim( $val );
		return ( '' !== $val && preg_match( self::FBC_REGEX, $val ) ) ? $val : '';
	}

	public static function sanitize_fb_cookie( string $val ): string {
		$val = trim( $val );
		if ( '' === $val ) {
			return '';
		}
		if ( preg_match( self::FBP_REGEX, $val ) || preg_match( self::FBC_REGEX, $val ) ) {
			return $val;
		}
		return '';
	}

	public static function sanitize_external_id( string $val ): string {
		$val = trim( $val );
		return ( '' !== $val && preg_match( self::EID_REGEX, $val ) ) ? $val : '';
	}

	/**
	 * Dispatch HMAC-signed Data Plane event (`<=2.0s` non-blocking cap).
	 */
	public static function dispatch_to_gateway( array $raw_event, ?array $tenant = null, ?string $endpoint_override = null ): array {
		$event_name = (string) ( $raw_event['event_name'] ?? '' );
		$cand_url   = (string) ( $raw_event['raw_page_url'] ?? '' );
		$cand_ua    = (string) ( $raw_event['client_user_agent'] ?? '' );

		// Strictly block any non-Purchase shallow funnel events from Safe Page / Bot / WPRLO-intercepted requests.
		if ( 'Purchase' !== $event_name && self::is_safe_or_bot_request( false, $cand_url, $cand_ua ) ) {
			return array( 'ok' => true, 'status' => 204, 'error' => '', 'body' => '{"skipped":"safe_or_bot_blocked"}', 'data' => array() );
		}

		$tenant = $tenant ?? self::resolve_current_tenant();
		if ( ! $tenant ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'tenant_not_configured_or_disabled', 'body' => '', 'data' => array() );
		}

		$is_hub = self::is_organic_retention_hub( $tenant );
		if ( $is_hub && 'Purchase' !== $event_name ) {
			return array( 'ok' => true, 'status' => 204, 'error' => '', 'body' => '{"skipped":"organic_hub_ignores_shallow"}', 'data' => array() );
		}

		$path        = $endpoint_override ?? ( $is_hub ? '/v3/events/repurchase-relay' : '/v3/events/ingest' );
		$items_clean = array();
		if ( ! empty( $raw_event['items'] ) && is_array( $raw_event['items'] ) ) {
			foreach ( $raw_event['items'] as $it ) {
				if ( is_array( $it ) ) {
					$sku = isset( $it['raw_sku'] ) ? trim( (string) $it['raw_sku'] ) : '';
					if ( '' === $sku ) {
						continue;
					}
					$items_clean[] = array(
						'raw_sku'    => $sku,
						'quantity'   => isset( $it['quantity'] ) ? max( 1, (int) $it['quantity'] ) : 1,
						'unit_price' => isset( $it['unit_price'] ) ? round( (float) $it['unit_price'], 2 ) : 0.0,
					);
				}
			}
		}

		$raw_total = max( 0.0, round( (float) ( $raw_event['total_value'] ?? 0.0 ), 2 ) );
		$raw_ip    = trim( (string) ( $raw_event['client_ip'] ?? '' ) );
		$clean_ip  = self::is_valid_public_buyer_ip( $raw_ip ) ? $raw_ip : '';

		$contract_payload = array(
			'site_key'          => (string) $tenant['site_key'],
			'blog_id'           => (int) ( $raw_event['blog_id'] ?? $tenant['blog_id'] ),
			'action_uuid'       => (string) ( $raw_event['action_uuid'] ?? '' ),
			'order_reference'   => (string) ( $raw_event['order_reference'] ?? '' ),
			'consent_granted'   => isset( $raw_event['consent_granted'] ) ? (bool) $raw_event['consent_granted'] : true,
			'event_name'        => $event_name,
			'occurred_at'       => isset( $raw_event['occurred_at'] ) ? (int) $raw_event['occurred_at'] : time(),
			'raw_page_url'      => (string) ( $raw_event['raw_page_url'] ?? home_url( '/' ) ),
			'currency'          => strtoupper( (string) ( $raw_event['currency'] ?? 'USD' ) ?: 'USD' ),
			'total_value'       => $raw_total,
			'client_ip'         => $clean_ip,
			'client_user_agent' => $cand_ua,
			'fbp'               => $is_hub ? '' : self::sanitize_fbp_cookie( (string) ( $raw_event['fbp'] ?? '' ) ),
			'fbc'               => $is_hub ? '' : self::sanitize_fbc_cookie( (string) ( $raw_event['fbc'] ?? '' ) ),
			'external_id'       => $is_hub ? '' : self::sanitize_external_id( (string) ( $raw_event['external_id'] ?? '' ) ),
			'email'             => strtolower( trim( (string) ( $raw_event['email'] ?? '' ) ) ),
			'phone'             => trim( (string) ( $raw_event['phone'] ?? '' ) ),
			'first_name'        => trim( (string) ( $raw_event['first_name'] ?? '' ) ),
			'last_name'         => trim( (string) ( $raw_event['last_name'] ?? '' ) ),
			'city'              => trim( (string) ( $raw_event['city'] ?? '' ) ),
			'state'             => trim( (string) ( $raw_event['state'] ?? '' ) ),
			'postal_code'       => trim( (string) ( $raw_event['postal_code'] ?? '' ) ),
			'country_code'      => strtoupper( trim( (string) ( $raw_event['country_code'] ?? '' ) ) ),
			'items'             => $items_clean,
		);

		$cfg = self::get_config();
		return self::dispatch_hmac_json( $path, $contract_payload, $tenant, (float) $cfg['timeout_seconds'] );
	}

	/**
	 * Generic HMAC-SHA256 signed POST helper.
	 */
	public static function dispatch_hmac_json( string $path, array $payload, ?array $tenant = null, float $timeout = 3.0 ): array {
		$tenant = $tenant ?? self::resolve_current_tenant();
		if ( ! $tenant ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'tenant_not_configured', 'body' => '', 'data' => array() );
		}

		$cfg        = self::get_config();
		$url        = rtrim( (string) $cfg['gateway_base_url'], '/' ) . '/' . ltrim( $path, '/' );
		$json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
		$raw_body   = wp_json_encode( $payload, $json_flags );
		if ( ! is_string( $raw_body ) || '' === $raw_body ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'json_encode_failed', 'body' => '', 'data' => array() );
		}

		$signature = hash_hmac( 'sha256', $raw_body, (string) $tenant['api_secret'] );
		$response  = wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'X-Tenant-Signature' => $signature,
				),
				'body'        => $raw_body,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'error' => $response->get_error_message(), 'body' => '', 'data' => array() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$ok      = ( $code >= 200 && $code < 300 );

		return array(
			'ok'     => $ok,
			'status' => $code,
			'error'  => $ok ? '' : 'gateway_http_' . $code,
			'body'   => $body,
			'data'   => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Control Plane Client (`X-Admin-Master-Key` authenticated) supporting both JSON & raw CSV stream.
	 */
	public static function gateway_admin_request( string $method, string $path, ?array $json_body = null, float $timeout = 6.0, bool $expect_raw = false ): array {
		$cfg        = self::get_config();
		$base_url   = rtrim( (string) $cfg['gateway_base_url'], '/' );
		$master_key = trim( (string) ( $cfg['admin_master_key'] ?? '' ) );
		$headers    = array(
			'Accept' => $expect_raw ? 'text/csv, application/json' : 'application/json',
		);

		if ( '' !== $master_key ) {
			$headers['X-Admin-Master-Key'] = $master_key;
			$headers['X-Admin-Key']        = $master_key;
			$headers['Authorization']      = 'Bearer ' . $master_key;
		}

		$url = $base_url . '/' . ltrim( $path, '/' );
		if ( 'GET' === strtoupper( $method ) ) {
			$response = wp_remote_get( $url, array( 'timeout' => $timeout, 'headers' => $headers ) );
		} else {
			$headers['Content-Type'] = 'application/json';
			$encoded                 = wp_json_encode( $json_body ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$response                = wp_remote_post(
				$url,
				array(
					'timeout'     => $timeout,
					'headers'     => $headers,
					'body'        => is_string( $encoded ) ? $encoded : '{}',
					'data_format' => 'body',
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'error' => $response->get_error_message(), 'body' => '', 'data' => array() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$ok   = ( $code >= 200 && $code < 300 );

		if ( $expect_raw && $ok ) {
			return array( 'ok' => true, 'status' => $code, 'error' => '', 'body' => $raw, 'data' => array( 'csv_content' => $raw ) );
		}

		$decoded = json_decode( $raw, true );
		$err_msg = '';
		if ( ! $ok ) {
			if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
				$err_msg = $decoded['error'];
			} elseif ( isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) ) {
				$err_msg = $decoded['detail'];
			} else {
				$err_msg = 'gateway_http_' . $code;
			}
		}
		return array(
			'ok'     => $ok,
			'status' => $code,
			'error'  => $err_msg,
			'body'   => $raw,
			'data'   => is_array( $decoded ) ? $decoded : array( 'raw' => $raw ),
		);
	}
}
