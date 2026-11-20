<?php
/**
 * Configuration Manager, Cloak-Verified Real IP Extractor & Gateway Transport (V3.2 Ultimate).
 *
 * Aligns 100% with `b_wp 终极版.md` & `a_capi终极版.md`:
 * - Priority #1 buyer IP: `$GLOBALS['wprlo_verified_client_ip']` (Cloak traffic router) -> Cloudflare -> X-Real-IP -> XFF.
 * - Supports `tenant_mode`: `'AD_ACQUISITION'` (6 ad stores) vs `'ORGANIC_RETENTION_HUB'` (Dec Station #2).
 * - Supports HMAC-SHA256 `X-Tenant-Signature` for `/ingest`, `/repurchase-relay`, and `/v3/reconcile/batch-check`.
 * - Sends `X-Admin-Master-Key` for all `/v3/admin/*` endpoints (including streaming `export-seed-csv`).
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Config_Manager {

	public const OPTION_NETWORK = 'stealth_capi_network_config';
	public const OPTION_SITE    = 'stealth_capi_site_config';
	public const FBP_FBC_REGEX  = '/^fb\.[012]\.\d{10,16}\..+$/';

	public const MODE_AD_ACQUISITION        = 'AD_ACQUISITION';
	public const MODE_ORGANIC_RETENTION_HUB = 'ORGANIC_RETENTION_HUB';

	public static function get_stores_catalog(): array {
		return array(
			'site_01'           => array( 'blog_id' => 1, 'store_code' => 's0006', 'label' => '投手 1 店 - 李军 (s0006)' ),
			'site_02'           => array( 'blog_id' => 1, 'store_code' => 's0007', 'label' => '投手 2 店 - 阳 (s0007)' ),
			'site_03'           => array( 'blog_id' => 1, 'store_code' => 's0008', 'label' => '投手 3 店 - 光辉 (s0008)' ),
			'site_04'           => array( 'blog_id' => 1, 'store_code' => 's0009', 'label' => '投手 4 店 - 老毛 (s0009)' ),
			'site_05'           => array( 'blog_id' => 1, 'store_code' => 's0010', 'label' => '投手 5 店 - 芒果 (s0010)' ),
			'site_06'           => array( 'blog_id' => 1, 'store_code' => 's0001', 'label' => '机动预留主店 (s0001)' ),
			'repurchase_hub_02' => array( 'blog_id' => 1, 'store_code' => 'hub_02', 'label' => '12月 2号纯复购总站 (0像素)' ),
		);
	}

	public static function get_config(): array {
		$defaults = array(
			'enabled'               => false,
			'gateway_base_url'      => '',
			'admin_master_key'      => '',
			'timeout_seconds'       => 2.0,
			'suppress_legacy_meta'  => true,
			'auto_enqueue_sdk'      => true,
			'enable_server_shallow' => true,
			'trust_private_proxies' => false,
			'trusted_proxies'       => array(),
			'default_tenant'        => array(
				'enabled'               => false,
				'blog_id'               => 1,
				'site_key'              => '',
				'api_secret'            => '',
				'store_code'            => '',
				'tenant_mode'           => self::MODE_AD_ACQUISITION,
				'is_repurchase_station' => false,
			),
			'tenants_by_blog_id'    => array(),
			'tenants_by_domain'     => array(),
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

	public static function is_connector_ready(): bool {
		return '' !== self::configured_gateway_url( self::get_config() );
	}

	private static function configured_gateway_url( array $cfg ): string {
		if ( empty( $cfg['enabled'] ) || ! is_string( $cfg['gateway_base_url'] ) ) {
			return '';
		}

		$url = rtrim( trim( $cfg['gateway_base_url'] ), '/' );
		if ( ! preg_match( '#^https?://#i', $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		return $url;
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
		if ( '' === self::configured_gateway_url( $cfg ) ) {
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
	 * Extract real buyer public IP at browser interaction / checkout moment.
	 *
	 * Priority:
	 * 1. `$GLOBALS['wprlo_verified_client_ip']` (from refactored Cloak traffic router at 1st ms)
	 * 2. Trusted Proxy CIDR verified headers: `HTTP_CF_CONNECTING_IP` -> `HTTP_X_REAL_IP` -> first public IP in `HTTP_X_FORWARDED_FOR`
	 * 3. `REMOTE_ADDR` (preserves full 128-bit IPv6 without /64 truncation)
	 */
	public static function extract_buyer_ip(): string {
		if ( ! empty( $GLOBALS['wprlo_verified_client_ip'] ) && is_string( $GLOBALS['wprlo_verified_client_ip'] ) ) {
			$cloak_ip = trim( $GLOBALS['wprlo_verified_client_ip'] );
			if ( filter_var( $cloak_ip, FILTER_VALIDATE_IP ) ) {
				return $cloak_ip;
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$trusted_list = self::get_trusted_proxy_list();
		if ( ! empty( $trusted_list ) && self::ip_in_cidrs( $remote, $trusted_list ) ) {
			$candidates = array();
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$candidates[] = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
			}
			if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$candidates[] = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ) );
			}
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) as $part ) {
					$candidates[] = trim( $part );
				}
			}

			$first_valid = '';
			foreach ( $candidates as $cand ) {
				if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
					if ( '' === $first_valid ) {
						$first_valid = $cand;
					}
					if ( filter_var( $cand, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						return $cand;
					}
				}
			}
			if ( '' !== $first_valid ) {
				return $first_valid;
			}
		}

		return $remote;
	}

	public static function get_trusted_proxy_list(): array {
		$cfg  = self::get_config();
		$list = array();

		if ( defined( 'PEPTIDE_CORE_TRUSTED_PROXIES' ) && is_string( PEPTIDE_CORE_TRUSTED_PROXIES ) ) {
			$list = array_merge( $list, explode( ',', PEPTIDE_CORE_TRUSTED_PROXIES ) );
		}
		if ( ! empty( $cfg['trusted_proxies'] ) ) {
			if ( is_string( $cfg['trusted_proxies'] ) ) {
				$list = array_merge( $list, explode( ',', $cfg['trusted_proxies'] ) );
			} elseif ( is_array( $cfg['trusted_proxies'] ) ) {
				$list = array_merge( $cfg['trusted_proxies'] );
			}
		}
		if ( ! empty( $cfg['trust_private_proxies'] ) ) {
			$list = array_merge(
				$list,
				array( '127.0.0.1/32', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7' )
			);
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

	public static function sanitize_fb_cookie( string $val ): string {
		$val = trim( $val );
		return ( '' !== $val && preg_match( self::FBP_FBC_REGEX, $val ) ) ? $val : '';
	}

	/**
	 * Dispatch HMAC-signed Data Plane event (`<=2.0s` non-blocking cap).
	 */
	public static function dispatch_to_gateway( array $raw_event, ?array $tenant = null, ?string $endpoint_override = null ): array {
		$tenant = $tenant ?? self::resolve_current_tenant();
		if ( ! $tenant ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'tenant_not_configured_or_disabled', 'body' => '' );
		}

		$is_hub     = self::is_organic_retention_hub( $tenant );
		$event_name = (string) ( $raw_event['event_name'] ?? '' );
		if ( $is_hub && 'Purchase' !== $event_name ) {
			return array( 'ok' => true, 'status' => 204, 'error' => '', 'body' => '{"skipped":"organic_hub_ignores_shallow"}' );
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
			'client_ip'         => (string) ( $raw_event['client_ip'] ?? '' ),
			'client_user_agent' => (string) ( $raw_event['client_user_agent'] ?? '' ),
			'fbp'               => $is_hub ? '' : self::sanitize_fb_cookie( (string) ( $raw_event['fbp'] ?? '' ) ),
			'fbc'               => $is_hub ? '' : self::sanitize_fb_cookie( (string) ( $raw_event['fbc'] ?? '' ) ),
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
	 * Generic HMAC-SHA256 signed POST helper (shared by `/ingest`, `/repurchase-relay` & `/v3/reconcile/batch-check`).
	 */
	public static function dispatch_hmac_json( string $path, array $payload, ?array $tenant = null, float $timeout = 3.0 ): array {
		$tenant = $tenant ?? self::resolve_current_tenant();
		if ( ! $tenant ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'tenant_not_configured', 'body' => '', 'data' => array() );
		}

		$base_url = self::configured_gateway_url( self::get_config() );
		if ( '' === $base_url ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'gateway_not_configured', 'body' => '', 'data' => array() );
		}

		$url        = $base_url . '/' . ltrim( $path, '/' );
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
		$base_url   = self::configured_gateway_url( $cfg );
		if ( '' === $base_url ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'gateway_not_configured', 'body' => '', 'data' => array() );
		}

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
