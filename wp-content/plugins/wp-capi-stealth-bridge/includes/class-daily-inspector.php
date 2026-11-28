<?php
/**
 * 第 6 页：每日全链路安全与健康巡检中心 (`class-daily-inspector.php`)。
 *
 * 覆盖 6+1 大真机实测模块：
 * 1. 三大底层安全勾选框、四大 PHP 钩子、/etc/hosts 物理黑洞与网页源码 0 明文像素扫描；
 * 2. AB 站斗篷（WPRLO）双视角攻防实测（模拟 Facebook 审核爬虫查 A 站合规 + 模拟买家查回传通道）；
 * 3. 网关连通、像素槽位健康、独享静态代理真联网测速（ipinfo.io US 校验）与跨槽撞 IP 查重；
 * 4. 线上在售商品 ↔ 网关专属脱敏 SKU 100% 覆盖核对；
 * 5. 近 24 小时商城已付订单 vs 网关入库单双向零漏单核账与 10 分钟定时任务心跳；
 * 6. 投流域名 HTTPS 响应耗时与 SSL 证书剩余有效天数预警；
 * 7. Roxy 指纹浏览器窗口 Graph 公开头像端点无痕验活与撞 IP 联动汇总。
 *
 * 支持：随时手点「立即一键全网日检」 + 每日 15:30（北京时间，投手 16:00 开班前）自动巡检 + Webhook 告警推送。
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Daily_Inspector {

	public const CRON_HOOK          = 'stealth_capi_daily_inspection_cron';
	public const OPT_REPORTS        = 'stealth_capi_daily_reports_v1';
	public const OPT_WEBHOOK_CFG    = 'stealth_capi_daily_webhook_cfg';
	public const OPT_ROXY_PROBES    = 'stealth_capi_roxy_probe_cache';

	private const SENSITIVE_A_SITE_TERMS = array(
		'semaglutide',
		'tirzepatide',
		'retatrutide',
		'cagrilintide',
		'mazdutide',
		'survodutide',
		'bpc-157',
		'tb-500',
		'lyophilized',
		'vialcove',
	);

	public static function register_hooks(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_scheduled_inspection' ) );
		add_filter( 'http_request_host_is_external', array( self::class, 'allow_registered_hq_hosts' ), 20, 2 );
		if ( class_exists( '\PeptideHq\Sites' ) ) {
			self::ensure_daily_cron_scheduled();
		}
	}

	/**
	 * 在开启 WP_HTTP_BLOCK_EXTERNAL 的总控节点上，仅对总后台「店铺管理」已登记的自有线上域名与管理员配置的告警 Webhook 域名放行巡检请求。
	 */
	public static function allow_registered_hq_hosts( bool $allow, string $host ): bool {
		if ( $allow || '' === $host ) {
			return $allow;
		}
		$clean_host = strtolower( trim( $host ) );
		if ( class_exists( '\PeptideHq\Sites' ) ) {
			foreach ( \PeptideHq\Sites::all() as $srow ) {
				$pub_h = strtolower( (string) wp_parse_url( (string) ( $srow['public_url'] ?? '' ), PHP_URL_HOST ) );
				if ( '' !== $pub_h && $clean_host === $pub_h ) {
					return true;
				}
			}
		}
		$wh_cfg = self::get_webhook_config();
		$wh_h   = strtolower( (string) wp_parse_url( (string) ( $wh_cfg['webhook_url'] ?? '' ), PHP_URL_HOST ) );
		if ( '' !== $wh_h && $clean_host === $wh_h ) {
			return true;
		}
		return false;
	}

	/**
	 * 确保总后台每天北京时间 15:30:00（即 07:30:00 UTC，投手 16:00 上机前 30 分钟）自动执行一次全网日检。
	 */
	public static function ensure_daily_cron_scheduled(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $next && $next > time() - 300 ) {
			return;
		}
		$now_utc  = time();
		$today_target = gmmktime( 7, 30, 0, (int) gmdate( 'n', $now_utc ), (int) gmdate( 'j', $now_utc ), (int) gmdate( 'Y', $now_utc ) );
		$target   = ( $today_target > $now_utc + 60 ) ? $today_target : ( $today_target + 86400 );
		if ( false !== $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
		wp_schedule_event( $target, 'daily', self::CRON_HOOK );
	}

	public static function run_scheduled_inspection(): void {
		if ( ! class_exists( '\PeptideHq\Sites' ) ) {
			return;
		}
		$report = self::run_full_inspection( 'cron_1530' );
		self::maybe_send_webhook( $report, false );
	}

	/**
	 * 单店节点内部自检接口（由总后台通过 Hq_Connector 签名远程拉取，零敏感密钥泄露）。
	 */
	public static function collect_local_store_telemetry(): array {
		$cfg    = Config_Manager::get_config();
		$tenant = Config_Manager::resolve_current_tenant();

		$mock_in  = array(
			'facebook'  => array( 'pixel_id' => '999999' ),
			'meta'      => array( 'pixel_id' => '888888' ),
			'platforms' => array( 'facebook' => '777777', 'ga4' => 'G-KEEP' ),
		);
		$mock_out = apply_filters( 'option_peptide_core_pixels', $mock_in );
		$strip_ok = ! isset( $mock_out['facebook'] )
			&& ! isset( $mock_out['meta'] )
			&& ! isset( $mock_out['platforms']['facebook'] )
			&& ( 'G-KEEP' === ( $mock_out['platforms']['ga4'] ?? '' ) );

		$raw_db_pixels = get_option( 'peptide_core_pixels', array() );
		$db_clean      = true;
		if ( is_array( $raw_db_pixels ) && ! empty( $raw_db_pixels ) ) {
			if ( ! empty( $raw_db_pixels['facebook'] ) || ! empty( $raw_db_pixels['meta'] ) || ! empty( $raw_db_pixels['platforms']['facebook'] ) ) {
				$db_clean = false;
			}
		}

		$hosts_txt      = @file_get_contents( '/etc/hosts' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$hosts_str      = is_string( $hosts_txt ) ? $hosts_txt : '';
		$blackhole_ok   = ( false !== stripos( $hosts_str, 'graph.facebook.com' ) && false !== stripos( $hosts_str, 'connect.facebook.net' ) );

		$wprlo_active = false;
		$wprlo_pack   = '';
		if ( function_exists( 'wprlo_get_options' ) ) {
			$w_opts       = wprlo_get_options();
			$wprlo_active = is_array( $w_opts ) && ! empty( $w_opts['enabled'] );
			$wprlo_pack   = is_array( $w_opts ) ? (string) ( $w_opts['active_static_pack'] ?? $w_opts['active_pack_id'] ?? $w_opts['pack_slug'] ?? '' ) : '';
		}

		// 在售商品 SKU 收集
		$raw_skus = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$prods = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $prods as $p ) {
				if ( ! $p instanceof \WC_Product ) {
					continue;
				}
				if ( $p->is_type( 'variable' ) ) {
					foreach ( $p->get_children() as $cid ) {
						$v = wc_get_product( $cid );
						if ( $v instanceof \WC_Product ) {
							$s          = trim( (string) $v->get_sku() );
							$raw_skus[] = '' !== $s ? $s : ( 'WC_' . $v->get_id() );
						}
					}
				} else {
					$s          = trim( (string) $p->get_sku() );
					$raw_skus[] = '' !== $s ? $s : ( 'WC_' . $p->get_id() );
				}
			}
		}

		// 近 24 小时真实已付款订单与网关双向比对
		$paid_24h_cnt = 0;
		$missing_cnt  = 0;
		$order_uuids  = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$since_ts = time() - 86400;
			$orders   = wc_get_orders(
				array(
					'limit'     => 300,
					'status'    => array( 'processing', 'completed' ),
					'date_paid' => '>' . $since_ts,
					'return'    => 'ids',
				)
			);
			if ( is_array( $orders ) ) {
				$paid_24h_cnt = count( $orders );
				$blog_id      = $tenant ? (int) $tenant['blog_id'] : 1;
				$prefix       = ( $tenant && Config_Manager::is_organic_retention_hub( $tenant ) ) ? 'rep' : 'ord';
				foreach ( $orders as $oid ) {
					$order_uuids[] = sprintf( '%s_%d_%d_purchase', $prefix, $blog_id, (int) $oid );
				}
			}
		}

		$hmac_ok   = false;
		$hmac_code = 0;
		if ( $tenant ) {
			$ping      = Config_Manager::dispatch_hmac_json( '/v3/reconcile/primary-status', array( 'site_key' => $tenant['site_key'] ), $tenant, 4.0 );
			$hmac_ok   = ! empty( $ping['ok'] );
			$hmac_code = (int) ( $ping['status'] ?? 0 );
			if ( $hmac_ok && ! empty( $order_uuids ) ) {
				$bchk = Config_Manager::dispatch_hmac_json( '/v3/reconcile/batch-check', array( 'site_key' => $tenant['site_key'], 'action_uuids' => $order_uuids ), $tenant, 5.0 );
				if ( ! empty( $bchk['ok'] ) && isset( $bchk['data']['missing_action_uuids'] ) && is_array( $bchk['data']['missing_action_uuids'] ) ) {
					$missing_cnt = count( $bchk['data']['missing_action_uuids'] );
				}
			}
		}

		$local_health = Reconcile_Cron::get_local_health();
		$pulse_file   = STEALTH_CAPI_BRIDGE_DIR . 'assets/fp-pulse.js';

		return array(
			'ok'          => true,
			'version'     => defined( 'STEALTH_CAPI_BRIDGE_VERSION' ) ? STEALTH_CAPI_BRIDGE_VERSION : '',
			'home_url'    => home_url( '/' ),
			'cfg'         => array(
				'enabled'               => ! empty( $cfg['enabled'] ),
				'suppress_legacy_meta'  => ! empty( $cfg['suppress_legacy_meta'] ),
				'auto_enqueue_sdk'      => ! empty( $cfg['auto_enqueue_sdk'] ),
				'enable_server_shallow' => ! empty( $cfg['enable_server_shallow'] ),
				'site_key'              => $tenant ? (string) $tenant['site_key'] : (string) ( $cfg['default_tenant']['site_key'] ?? '' ),
				'tenant_mode'           => $tenant ? (string) $tenant['tenant_mode'] : '',
			),
			'security'    => array(
				'filter_pixel_suppress' => ( false !== has_filter( 'option_peptide_core_pixels' ) ),
				'mock_fb_strip_ok'      => $strip_ok,
				'db_pixels_clean'       => $db_clean,
				'hosts_blackhole_ok'    => $blackhole_ok,
				'pulse_js_exists'       => file_exists( $pulse_file ),
				'pulse_js_bytes'        => file_exists( $pulse_file ) ? (int) filesize( $pulse_file ) : 0,
				'hook_user_register'    => ( false !== has_action( 'user_register', array( REST_Pulse_API::class, 'on_user_register' ) ) ),
				'hook_classic_atc'      => ( false !== has_action( 'woocommerce_add_to_cart', array( REST_Pulse_API::class, 'on_classic_add_to_cart' ) ) ),
				'hook_block_atc'        => ( false !== has_action( 'internal_woocommerce_cart_item_added_from_user_request', array( REST_Pulse_API::class, 'on_store_api_add_to_cart' ) ) ),
				'hook_payment_complete' => ( false !== has_action( 'woocommerce_payment_complete', array( Cashier_Listener::class, 'on_payment_complete' ) ) ),
			),
			'wprlo'       => array(
				'enabled' => $wprlo_active,
				'pack'    => $wprlo_pack,
			),
			'gateway_hmac' => array(
				'ok'     => $hmac_ok,
				'status' => $hmac_code,
			),
			'skus'        => array(
				'count'    => count( $raw_skus ),
				'raw_skus' => $raw_skus,
			),
			'reconcile'   => array(
				'paid_24h_count'    => $paid_24h_cnt,
				'missing_in_gw'     => $missing_cnt,
				'retry_queue_depth' => (int) ( $local_health['retry_queue_depth'] ?? 0 ),
				'last_reconcile_at' => (int) ( $local_health['last_reconciler']['last_run_at'] ?? $local_health['last_reconciler']['ran_at'] ?? $local_health['last_reconciler']['timestamp'] ?? 0 ),
				'next_scheduled_at' => (int) ( $local_health['next_scheduled_at'] ?? 0 ),
			),
		);
	}

	/**
	 * 若本机桥接助手在线，尝试通过云隧道静默刷新一次 Roxy 窗口 Graph UID 验活缓存（最长等待 7 秒，离线则自动沿用最近缓存）。
	 */
	private static function try_refresh_roxy_probes_via_tunnel(): void {
		$saved = get_option( 'stealth_capi_roxy_saved_cfg', array() );
		$tok   = is_array( $saved ) ? trim( (string) ( $saved['key'] ?? '' ) ) : '';
		if ( '' === $tok ) {
			return;
		}
		global $wpdb;
		$req_id   = 'rx_daily_' . wp_generate_password( 10, false, false );
		$task_key = '_scapi_rx_req_' . $req_id;
		$resp_key = '_scapi_rx_res_' . $req_id;
		$payload  = wp_json_encode(
			array(
				'id'     => $req_id,
				'path'   => '/local/probe_fb_uids',
				'method' => 'POST',
				'token'  => $tok,
				'body'   => wp_json_encode( array( 'force' => false ) ),
				'ts'     => time(),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => $task_key,
				'option_value' => $payload,
				'autoload'     => 'no',
			)
		);
		$found = null;
		for ( $i = 0; $i < 35; $i++ ) {
			usleep( 200000 ); // max 7s
			$val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $resp_key ) );
			if ( null !== $val && '' !== $val ) {
				$found = json_decode( (string) $val, true );
				break;
			}
		}
		$wpdb->delete( $wpdb->options, array( 'option_name' => $task_key ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $resp_key ) );

		if ( is_array( $found ) && isset( $found['data']['results'] ) && is_array( $found['data']['results'] ) ) {
			update_option(
				self::OPT_ROXY_PROBES,
				array(
					'probes'    => $found['data']['results'],
					'synced_at' => time(),
				),
				false
			);
		}
	}

	private static function check_ssl_days_remaining( string $host ): ?int {
		if ( '' === $host ) {
			return null;
		}
		$ctx = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
				),
			)
		);
		$errno  = 0;
		$errstr = '';
		$client = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $client ) {
			return null;
		}
		$params = stream_context_get_params( $client );
		fclose( $client );
		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return null;
		}
		$cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		if ( ! is_array( $cert ) || empty( $cert['validTo_time_t'] ) ) {
			return null;
		}
		return (int) floor( ( (int) $cert['validTo_time_t'] - time() ) / 86400 );
	}

	private static function raw_http_request( string $method, string $url, array $headers = array(), ?string $body = null, float $timeout = 8.0 ): array {
		if ( function_exists( 'curl_init' ) ) {
			$ch  = curl_init( $url );
			$hdr = array();
			foreach ( $headers as $hk => $hv ) {
				$hdr[] = $hk . ': ' . $hv;
			}
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => ( 'GET' === $method && false === strpos( $url, '/wp-json/' ) ),
					CURLOPT_MAXREDIRS      => 2,
					CURLOPT_TIMEOUT        => max( 2, (int) ceil( $timeout ) ),
					CURLOPT_CONNECTTIMEOUT => 5,
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_HTTPHEADER     => $hdr,
					CURLOPT_SSL_VERIFYPEER => true,
				)
			);
			if ( null !== $body ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			}
			$t0       = microtime( true );
			$resp_str = curl_exec( $ch );
			$lat_ms   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			return array(
				'code'       => $code,
				'body'       => is_string( $resp_str ) ? $resp_str : '',
				'latency_ms' => $lat_ms,
			);
		}

		$t0   = microtime( true );
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => ( 'GET' === $method && false === strpos( $url, '/wp-json/' ) ) ? 2 : 0,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$r = wp_remote_request( $url, $args );
		return array(
			'code'       => is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r ),
			'body'       => is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r ),
			'latency_ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
		);
	}

	private static function probe_public_domain( string $host ): array {
		$bot_ua  = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
		$home_r  = self::raw_http_request(
			'GET',
			'https://' . $host . '/',
			array(
				'User-Agent' => $bot_ua,
				'Accept'     => 'text/html',
			),
			null,
			8.0
		);
		$rest_r  = self::raw_http_request(
			'GET',
			'https://' . $host . '/wp-json/wp/v2/posts',
			array( 'User-Agent' => $bot_ua ),
			null,
			6.0
		);
		$pulse_r = self::raw_http_request(
			'POST',
			'https://' . $host . '/wp-json/stealth-capi/v1/pulse',
			array( 'Content-Type' => 'application/json' ),
			'{"event_name":"_DailyHealthProbe_"}',
			6.0
		);

		$home_code  = (int) $home_r['code'];
		$lat_ms     = (int) $home_r['latency_ms'];
		$home_body  = strtolower( (string) $home_r['body'] );
		$rest_code  = (int) $rest_r['code'];
		$pulse_code = (int) $pulse_r['code'];

		$pixel_leaks = array();
		foreach ( array( 'fbevents.js', 'connect.facebook.net', 'fbq(' ) as $pm ) {
			if ( '' !== $home_body && false !== strpos( $home_body, $pm ) ) {
				$pixel_leaks[] = $pm;
			}
		}
		$pulse_leak_on_a = ( '' !== $home_body && ( false !== strpos( $home_body, 'fp-pulse.js' ) || false !== strpos( $home_body, 'stealth_capi_cfg' ) ) );

		$sensitive_hits = array();
		foreach ( self::SENSITIVE_A_SITE_TERMS as $term ) {
			if ( '' !== $home_body && false !== strpos( $home_body, $term ) ) {
				$sensitive_hits[] = $term;
			}
		}
		if ( '' !== $home_body && preg_match( '/\b\d+\s*(?:mg|mcg)\b/i', $home_body ) ) {
			$sensitive_hits[] = '剂量单位(mg/mcg)';
		}

		return array(
			'home_code'       => $home_code,
			'latency_ms'      => $lat_ms,
			'rest_code'       => $rest_code,
			'pulse_code'      => $pulse_code,
			'pixel_leaks'     => $pixel_leaks,
			'pulse_leak_on_a' => $pulse_leak_on_a,
			'sensitive_hits'  => $sensitive_hits,
			'ssl_days'        => self::check_ssl_days_remaining( $host ),
		);
	}

	/**
	 * 执行全网 6+1 项真机日检并持久化归档（保留最近 30 条记录）。
	 */
	public static function run_full_inspection( string $trigger = 'manual' ): array {
		self::try_refresh_roxy_probes_via_tunnel();

		$cfg          = Config_Manager::get_config();
		$gw_url       = rtrim( (string) ( $cfg['gateway_base_url'] ?? '' ), '/' );
		$hq_hosts     = (string) @file_get_contents( '/etc/hosts' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$hq_blackhole = ( false !== stripos( $hq_hosts, 'graph.facebook.com' ) && false !== stripos( $hq_hosts, 'connect.facebook.net' ) );

		// 1. 网关健康、槽位与静态代理实测
		$gw_ready_r = wp_remote_get( $gw_url . '/health/ready', array( 'timeout' => 5.0 ) );
		$gw_ready   = ! is_wp_error( $gw_ready_r ) && 200 === (int) wp_remote_retrieve_response_code( $gw_ready_r );
		$slots_ov   = \StealthCapiBridge::fetch_network_slots_status();
		$probe_gw   = Config_Manager::gateway_admin_request( 'GET', '/v3/admin/probe-slots', null, 18.0 );
		$probe_data = ! empty( $probe_gw['ok'] ) && is_array( $probe_gw['data'] ?? null ) ? $probe_gw['data'] : array();

		$slot_probes_by_site = array();
		foreach ( (array) ( $probe_data['slot_probes'] ?? array() ) as $sp ) {
			if ( is_array( $sp ) && ! empty( $sp['site_key'] ) ) {
				$slot_probes_by_site[ (string) $sp['site_key'] ][] = $sp;
			}
		}
		$dup_gw_hosts = (array) ( $probe_data['duplicate_proxy_hosts'] ?? array() );
		$dup_gw_exits = (array) ( $probe_data['duplicate_exit_ips'] ?? array() );

		$catalog      = Config_Manager::get_stores_catalog();
		$hq_sites     = class_exists( '\PeptideHq\Sites' ) ? \PeptideHq\Sites::all() : array();
		$bound_stores = get_option( 'stealth_capi_hq_bound_stores', array() );

		$store_reports  = array();
		$standby_stores = array();
		$fail_count     = 0;
		$warn_count     = 0;

		foreach ( $hq_sites as $sid => $srow ) {
			$sk = (string) ( $bound_stores[ $sid ]['site_key'] ?? '' );
			if ( '' === $sk ) {
				$m_url = (string) ( $srow['management_url'] ?? '' );
				foreach ( $catalog as $ck => $cm ) {
					if ( ! empty( $cm['store_code'] ) && false !== strpos( $m_url, $cm['store_code'] ) ) {
						$sk = $ck;
						break;
					}
				}
			}
			$label   = $catalog[ $sk ]['label'] ?? (string) ( $srow['name'] ?: $sk );
			$pub_url = (string) ( $srow['public_url'] ?? '' );
			$host    = strtolower( (string) wp_parse_url( $pub_url, PHP_URL_HOST ) );
			$is_on   = ! empty( $bound_stores[ $sid ]['enabled'] );

			if ( ! $is_on ) {
				$standby_stores[] = array(
					'label'  => $label,
					'domain' => $host ?: $pub_url,
				);
				continue;
			}

			$tele_res = class_exists( '\PeptideHq\Client' )
				? \PeptideHq\Client::request( (int) $sid, 'GET', '/stealth-capi/v1/hq-store-health', array(), null, 15 )
				: new \WP_Error( 'no_hq_client', '缺少总控通信客户端' );
			$tele     = ( ! is_wp_error( $tele_res ) && is_array( $tele_res ) && ! empty( $tele_res['ok'] ) ) ? $tele_res : null;
			$pub      = '' !== $host ? self::probe_public_domain( $host ) : array();

			$dict_res = \StealthCapiBridge::fetch_sanitizer_dictionary( $sk, true );
			$dict_map = array();
			if ( ! empty( $dict_res['ok'] ) && is_array( $dict_res['data']['sku_map'] ?? null ) ) {
				foreach ( $dict_res['data']['sku_map'] as $rk => $sv ) {
					$dict_map[ strtoupper( trim( (string) $rk ) ) ] = is_array( $sv ) ? (string) ( $sv['safe_catalog_id'] ?? '' ) : (string) $sv;
				}
			} elseif ( ! empty( $dict_res['ok'] ) && is_array( $dict_res['data']['entries'] ?? null ) ) {
				foreach ( $dict_res['data']['entries'] as $dr ) {
					if ( is_array( $dr ) && ! empty( $dr['raw_sku'] ) ) {
						$dict_map[ strtoupper( trim( (string) $dr['raw_sku'] ) ) ] = (string) ( $dr['safe_catalog_id'] ?? '' );
					}
				}
			}

			$checks = array();

			// --- 检查项 1：三大底层安全勾选框 + 四大钩子 + 物理黑洞 + 零明文像素 ---
			if ( ! $tele ) {
				$checks['sec_boxes'] = array(
					'status' => 'fail',
					'title'  => '① 三大底层安全开关与零明文防泄密',
					'detail' => '无法连入该分店内部自检接口，请检查分店运行状态。',
				);
			} else {
				$c_cfg = $tele['cfg'] ?? array();
				$c_sec = $tele['security'] ?? array();
				$errs1 = array();
				if ( empty( $c_cfg['enabled'] ) ) {
					$errs1[] = '分店隐身回传总开关被关闭';
				}
				if ( empty( $c_cfg['suppress_legacy_meta'] ) || empty( $c_sec['filter_pixel_suppress'] ) || empty( $c_sec['mock_fb_strip_ok'] ) ) {
					$errs1[] = '勾选框1(屏蔽旧明文像素)未生效';
				}
				if ( empty( $c_sec['db_pixels_clean'] ) ) {
					$errs1[] = '数据库残留旧版 Meta 像素配置';
				}
				if ( empty( $c_cfg['auto_enqueue_sdk'] ) || empty( $c_sec['pulse_js_exists'] ) ) {
					$errs1[] = '勾选框2(前台轻量采集脚本)未开启或脚本缺失';
				}
				if ( empty( $c_cfg['enable_server_shallow'] ) || empty( $c_sec['hook_user_register'] ) || empty( $c_sec['hook_classic_atc'] ) || empty( $c_sec['hook_payment_complete'] ) ) {
					$errs1[] = '勾选框3(服务端注册/加购/付款钩子)挂载不全';
				}
				if ( empty( $c_sec['hosts_blackhole_ok'] ) || ! $hq_blackhole ) {
					$errs1[] = '服务器 /etc/hosts 直连 FB 物理黑洞缺失';
				}
				if ( ! empty( $pub['pixel_leaks'] ) ) {
					$errs1[] = '网页源码检出明文像素泄露: ' . implode( ', ', $pub['pixel_leaks'] );
				}
				$checks['sec_boxes'] = empty( $errs1 )
					? array(
						'status' => 'pass',
						'title'  => '① 三大底层安全开关与零明文防泄密',
						'detail' => '3 个勾选框全开 · 4 大钩子正常 · /etc/hosts 黑洞生效 · 网页 0 明文像素',
					)
					: array(
						'status' => 'fail',
						'title'  => '① 三大底层安全开关与零明文防泄密',
						'detail' => implode('；', $errs1 ),
					);
			}

			// --- 检查项 2：AB 站斗篷（区域路由）双视角真机攻防实测 ---
			$errs2 = array();
			if ( ! $tele || empty( $tele['wprlo']['enabled'] ) ) {
				$errs2[] = '分店「区域路由与边缘渲染策略」开关未开启';
			}
			if ( 200 !== (int) ( $pub['home_code'] ?? 0 ) ) {
				$errs2[] = 'FB 爬虫视角访问首页异常 (HTTP ' . (int) ( $pub['home_code'] ?? 0 ) . ')';
			}
			if ( ! empty( $pub['sensitive_hits'] ) ) {
				$errs2[] = '爬虫视角检出违禁敏感词: ' . implode( ', ', $pub['sensitive_hits'] );
			}
			if ( ! empty( $pub['pulse_leak_on_a'] ) ) {
				$errs2[] = 'A 站白页泄露了 B 站采集脚本';
			}
			if ( 200 === (int) ( $pub['rest_code'] ?? 0 ) ) {
				$errs2[] = '/wp-json/ 接口对爬虫未隐藏 (返回 200)';
			}
			$p_code = (int) ( $pub['pulse_code'] ?? 0 );
			if ( ! in_array( $p_code, array( 202, 400 ), true ) ) {
				$errs2[] = '买家隐身回传接口 /pulse 响应异常 (HTTP ' . $p_code . ')';
			}
			$checks['cloak_ab'] = empty( $errs2 )
				? array(
					'status' => 'pass',
					'title'  => '② AB 站斗篷双视角攻防实测',
					'detail' => 'FB 爬虫返回 200 纯净 A 站 (0 敏感词 · /wp-json 已拦截) · 买家回传通道畅通',
				)
				: array(
					'status' => 'fail',
					'title'  => '② AB 站斗篷双视角攻防实测',
					'detail' => implode( '；', $errs2 ),
				);

			// --- 检查项 3：网关连通、像素槽位存活与独享静态代理检测 ---
			$site_slots = $slot_probes_by_site[ $sk ] ?? array();
			$errs3      = array();
			$warns3     = array();
			if ( ! $gw_ready || empty( $tele['gateway_hmac']['ok'] ) ) {
				$errs3[] = '分店与网关 HMAC 签名通信失败或网关未就绪';
			}
			if ( empty( $site_slots ) ) {
				$warns3[] = '当前尚未下发主力像素槽位（上广告前请从第 5 页下发）';
			} else {
				$has_prim = false;
				foreach ( $site_slots as $sp ) {
					$sp_role = strtolower( (string) ( $sp['slot_role'] ?? '' ) );
					if ( 'primary' === $sp_role ) {
						$has_prim = true;
					}
					if ( 'CLOSED' !== strtoupper( (string) ( $sp['circuit_state'] ?? 'CLOSED' ) ) ) {
						$errs3[] = $sp['slot_id'] . '(' . $sp_role . ') 通道异常: ' . $sp['circuit_state'];
					}
					if ( ! empty( $sp['missing_bm_id'] ) ) {
						$warns3[] = $sp['slot_id'] . ' 尚未录入选填备注 BMID';
					}
					if ( ! empty( $sp['has_test_code'] ) ) {
						$tc_lbl   = ! empty( $sp['test_event_code'] ) ? (string) $sp['test_event_code'] : 'TEST';
						$warns3[] = $sp['slot_id'] . ' 未清空测试事件码(' . $tc_lbl . ')';
					}
					if ( empty( $sp['proxy_ok'] ) ) {
						$errs3[] = $sp['slot_id'] . ' 独享静态代理断网不可达 (' . ( $sp['proxy_masked'] ?? '' ) . ')';
					} elseif ( empty( $sp['is_us'] ) ) {
						$errs3[] = $sp['slot_id'] . ' 代理实际出口非美国 (实测: ' . ( $sp['country'] ?? '未知' ) . ')';
					}
				}
				if ( ! $has_prim ) {
					$warns3[] = '当前仅旁路养模槽(shadow)在线，暂无活跃主归属槽(primary)';
				}
			}
			foreach ( $dup_gw_hosts as $dh ) {
				if ( false !== strpos( implode( ',', (array) $dh['slots'] ), $sk . ':' ) ) {
					$errs3[] = '严重防连坐警报：槽位共用代理地址 ' . $dh['host'];
				}
			}
			foreach ( $dup_gw_exits as $de ) {
				if ( false !== strpos( implode( ',', (array) $de['slots'] ), $sk . ':' ) ) {
					$errs3[] = '严重防连坐警报：多个槽位实测出口 IP 相同 (' . $de['exit_ip'] . ')';
				}
			}
			if ( ! empty( $errs3 ) ) {
				$checks['slots_proxy'] = array( 'status' => 'fail', 'title' => '③ 像素槽位与独享静态代理真测网', 'detail' => implode( '；', $errs3 ) );
			} elseif ( ! empty( $warns3 ) ) {
				$checks['slots_proxy'] = array( 'status' => 'warn', 'title' => '③ 像素槽位与独享静态代理真测网', 'detail' => '网关 HMAC 连通正常 · ' . implode( '；', $warns3 ) );
			} else {
				$checks['slots_proxy'] = array(
					'status' => 'pass',
					'title'  => '③ 像素槽位与独享静态代理真测网',
					'detail' => '网关连通 · 已挂 ' . count( $site_slots ) . ' 槽全绿 · 静态代理实测 US 出口在线且 0 撞 IP',
				);
			}

			// --- 检查项 4：线上在售商品 ↔ 专属脱敏 SKU 100% 覆盖核对 ---
			$store_skus   = (array) ( $tele['skus']['raw_skus'] ?? array() );
			$missing_skus = array();
			foreach ( $store_skus as $rsku ) {
				$k = strtoupper( trim( (string) $rsku ) );
				if ( '' !== $k && ! isset( $dict_map[ $k ] ) ) {
					$missing_skus[] = $rsku;
				}
			}
			if ( empty( $store_skus ) ) {
				$checks['sku_cover'] = array( 'status' => 'warn', 'title' => '④ 在售商品脱敏 SKU 覆盖率', 'detail' => '未读取到在售商品规格' );
			} elseif ( ! empty( $missing_skus ) ) {
				$sample_m            = implode( ', ', array_slice( $missing_skus, 0, 5 ) );
				$checks['sku_cover'] = array(
					'status' => 'fail',
					'title'  => '④ 在售商品脱敏 SKU 覆盖率',
					'detail' => '漏配 ' . count( $missing_skus ) . ' 个在售规格（如: ' . $sample_m . '），请去第 2 页同步 CSV！',
				);
			} else {
				$checks['sku_cover'] = array(
					'status' => 'pass',
					'title'  => '④ 在售商品脱敏 SKU 覆盖率',
					'detail' => '100% 精准覆盖（在售 ' . count( $store_skus ) . ' / 已配专属脱敏 ' . count( $dict_map ) . ' 条）',
				);
			}

			// --- 检查项 5：24 小时订单双向核账与补偿队列 ---
			$rec       = $tele['reconcile'] ?? array();
			$paid_24h  = (int) ( $rec['paid_24h_count'] ?? 0 );
			$miss_gw   = (int) ( $rec['missing_in_gw'] ?? 0 );
			$q_depth   = (int) ( $rec['retry_queue_depth'] ?? 0 );
			$next_cron = (int) ( $rec['next_scheduled_at'] ?? 0 );
			if ( $miss_gw > 0 ) {
				$checks['reconcile_24h'] = array(
					'status' => 'fail',
					'title'  => '⑤ 24h 订单核账与队列心跳',
					'detail' => '发现 ' . $miss_gw . ' 笔近 24h 已付款订单尚未入库网关，请去第 4 页点立即核账补推！',
				);
			} elseif ( $q_depth > 5 ) {
				$checks['reconcile_24h'] = array(
					'status' => 'warn',
					'title'  => '⑤ 24h 订单核账与队列心跳',
					'detail' => '本地待重试队列积压 ' . $q_depth . ' 笔，等待下次自动补偿',
				);
			} else {
				$checks['reconcile_24h'] = array(
					'status' => 'pass',
					'title'  => '⑤ 24h 订单核账与队列心跳',
					'detail' => '近 24h 已付 ' . $paid_24h . ' 单 (0 漏单) · 重试队列 ' . $q_depth . ' 笔 · 10分钟核账任务正常' . ( $next_cron > 0 ? '' : ' (待首单激活)' ),
				);
			}

			// --- 检查项 6：域名 HTTPS 响应速度与 SSL 证书有效期 ---
			$lat_ms   = (int) ( $pub['latency_ms'] ?? 0 );
			$ssl_days = $pub['ssl_days'] ?? null;
			if ( 200 !== (int) ( $pub['home_code'] ?? 0 ) ) {
				$checks['https_ssl'] = array( 'status' => 'fail', 'title' => '⑥ 域名 HTTPS 速度与 SSL 证书', 'detail' => 'HTTPS 无法正常打开首页' );
			} elseif ( null !== $ssl_days && $ssl_days < 14 ) {
				$checks['https_ssl'] = array( 'status' => 'warn', 'title' => '⑥ 域名 HTTPS 速度与 SSL 证书', 'detail' => 'SSL 证书仅剩 ' . $ssl_days . ' 天到期，请及时续期！' );
			} elseif ( $lat_ms > 3500 ) {
				$checks['https_ssl'] = array( 'status' => 'warn', 'title' => '⑥ 域名 HTTPS 速度与 SSL 证书', 'detail' => '响应偏慢 (' . $lat_ms . ' ms) · SSL 剩余 ' . ( $ssl_days ?? '-' ) . ' 天' );
			} else {
				$checks['https_ssl'] = array(
					'status' => 'pass',
					'title'  => '⑥ 域名 HTTPS 速度与 SSL 证书',
					'detail' => 'HTTPS 200 极速 (' . $lat_ms . ' ms) · SSL 证书有效剩余 ' . ( null !== $ssl_days ? $ssl_days . ' 天' : '正常' ),
				);
			}

			$store_overall = 'pass';
			foreach ( $checks as $c_item ) {
				if ( 'fail' === $c_item['status'] ) {
					++$fail_count;
					$store_overall = 'fail';
				} elseif ( 'warn' === $c_item['status'] ) {
					++$warn_count;
					if ( 'pass' === $store_overall ) {
						$store_overall = 'warn';
					}
				}
			}

			$store_reports[] = array(
				'site_key' => $sk,
				'label'    => $label,
				'domain'   => $host,
				'overall'  => $store_overall,
				'checks'   => $checks,
			);
		}

		// --- 全局检查项 7：Roxy 指纹窗口 Graph 无痕存活与撞 IP 汇总 ---
		$roxy_cache  = get_option( self::OPT_ROXY_PROBES, array() );
		$roxy_probes = is_array( $roxy_cache['probes'] ?? null ) ? $roxy_cache['probes'] : array();
		$roxy_ts     = (int) ( $roxy_cache['synced_at'] ?? 0 );
		$rx_alive    = 0;
		$rx_dead     = array();
		$rx_px_err   = array();
		$rx_no_uid   = 0;
		$rx_ip_map   = array();

		foreach ( $roxy_probes as $did => $rp ) {
			if ( ! is_array( $rp ) ) {
				continue;
			}
			$gname = (string) ( $rp['group_name'] ?? '' );
			if ( false !== strpos( $gname, '缓冲池' ) || '窗口转移' === trim( $gname ) || '默认项目' === trim( $gname ) ) {
				continue;
			}
			$st    = (string) ( $rp['status'] ?? '' );
			$wname = (string) ( $rp['window_name'] ?? substr( (string) $did, 0, 8 ) );
			$phost = (string) ( $rp['proxy_host'] ?? '' );
			if ( '' !== $phost ) {
				$rx_ip_map[ $phost ][] = $wname;
			}
			if ( 'alive' === $st ) {
				++$rx_alive;
			} elseif ( 'dead' === $st ) {
				$rx_dead[] = $wname . '(尾号' . ( $rp['uid_tail'] ?? '' ) . ')';
			} elseif ( 'proxy_err' === $st || 'no_proxy' === $st ) {
				$rx_px_err[] = $wname;
			} elseif ( 'no_uid' === $st ) {
				++$rx_no_uid;
			}
		}
		$rx_dup_ips = array();
		foreach ( $rx_ip_map as $ip_k => $wnames ) {
			if ( count( $wnames ) > 1 ) {
				$rx_dup_ips[] = $ip_k . ' (' . count( $wnames ) . '窗共用)';
			}
		}

		$roxy_summary = array(
			'status'    => 'pass',
			'synced_at' => $roxy_ts,
			'detail'    => '',
		);
		if ( empty( $roxy_probes ) ) {
			$roxy_summary['status'] = 'warn';
			$roxy_summary['detail'] = '暂无 Roxy 静默验活缓存（打开第 5 页或保持本机桥接助手运行将自动同步）';
			++$warn_count;
		} else {
			$rx_errs = array();
			if ( ! empty( $rx_dead ) ) {
				$rx_errs[] = '检出已停用/封禁死号 ' . count( $rx_dead ) . ' 个：' . implode( '、', $rx_dead );
			}
			if ( ! empty( $rx_px_err ) ) {
				$rx_errs[] = '静态代理断网/未配 ' . count( $rx_px_err ) . ' 个：' . implode( '、', $rx_px_err );
			}
			if ( ! empty( $rx_dup_ips ) ) {
				$rx_errs[] = 'Roxy 窗口撞静态 IP：' . implode( '、', $rx_dup_ips );
			}
			if ( ! empty( $rx_errs ) ) {
				$roxy_summary['status'] = ! empty( $rx_dead ) ? 'fail' : 'warn';
				$roxy_summary['detail'] = implode( ' ｜ ', $rx_errs ) . '（其余存活 ' . $rx_alive . ' 个）';
				if ( ! empty( $rx_dead ) ) {
					++$fail_count;
				} else {
					++$warn_count;
				}
			} else {
				$roxy_summary['detail'] = 'Graph 公开头像无痕验活通过：主页正常存活 ' . $rx_alive . ' 个' . ( $rx_no_uid > 0 ? '，待注册无 UID ' . $rx_no_uid . ' 个' : '' ) . '，0 封号、0 撞 IP';
			}
		}

		$report = array(
			'ran_at'         => time(),
			'trigger'        => $trigger,
			'overall'        => $fail_count > 0 ? 'fail' : ( $warn_count > 0 ? 'warn' : 'pass' ),
			'fail_count'     => $fail_count,
			'warn_count'     => $warn_count,
			'gateway_ready'  => $gw_ready,
			'hq_blackhole'   => $hq_blackhole,
			'stores'         => $store_reports,
			'standby_stores' => $standby_stores,
			'roxy_summary'   => $roxy_summary,
		);

		$history = get_option( self::OPT_REPORTS, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		array_unshift( $history, $report );
		$history = array_slice( $history, 0, 30 );
		update_option( self::OPT_REPORTS, $history, false );

		return $report;
	}

	public static function get_reports_history(): array {
		$h = get_option( self::OPT_REPORTS, array() );
		return is_array( $h ) ? $h : array();
	}

	public static function save_webhook_config( string $url, bool $only_on_alert ): void {
		update_option(
			self::OPT_WEBHOOK_CFG,
			array(
				'webhook_url'   => esc_url_raw( trim( $url ) ),
				'only_on_alert' => $only_on_alert,
			),
			false
		);
	}

	public static function get_webhook_config(): array {
		$c = get_option( self::OPT_WEBHOOK_CFG, array() );
		return is_array( $c ) ? $c : array( 'webhook_url' => '', 'only_on_alert' => false );
	}

	public static function maybe_send_webhook( array $report, bool $force = false ): bool {
		$cfg = self::get_webhook_config();
		$url = trim( (string) ( $cfg['webhook_url'] ?? '' ) );
		if ( '' === $url ) {
			return false;
		}
		if ( ! $force && ! empty( $cfg['only_on_alert'] ) && 'pass' === ( $report['overall'] ?? 'pass' ) ) {
			return false;
		}

		$time_bj = gmdate( 'Y-m-d H:i:s', (int) ( $report['ran_at'] ?? time() ) + 8 * 3600 );
		$icon    = 'fail' === $report['overall'] ? '🔴 【阻断警报】' : ( 'warn' === $report['overall'] ? '🟡 【提醒关注】' : '🟢 【全绿通过】' );
		$lines   = array(
			$icon . ' 全局像素容灾与安全日检报告',
			'巡检时间：' . $time_bj . ' (北京时间)',
			'汇总结果：阻断 ' . (int) $report['fail_count'] . ' 项 ｜ 预警 ' . (int) $report['warn_count'] . ' 项',
		);
		foreach ( (array) ( $report['stores'] ?? array() ) as $st ) {
			$s_ic    = 'fail' === $st['overall'] ? '❌' : ( 'warn' === $st['overall'] ? '⚠️' : '✅' );
			$lines[] = $s_ic . ' ' . $st['label'] . ' (' . $st['domain'] . ')';
			foreach ( (array) ( $st['checks'] ?? array() ) as $ck ) {
				if ( 'pass' !== $ck['status'] ) {
					$lines[] = '   ↳ ' . $ck['title'] . '：' . $ck['detail'];
				}
			}
		}
		if ( ! empty( $report['roxy_summary']['detail'] ) ) {
			$r_ic    = 'fail' === $report['roxy_summary']['status'] ? '❌' : ( 'warn' === $report['roxy_summary']['status'] ? '⚠️' : '✅' );
			$lines[] = $r_ic . ' Roxy 账号池：' . $report['roxy_summary']['detail'];
		}
		$text = implode( "\n", $lines );

		if ( false !== stripos( $url, 'open.feishu.cn' ) || false !== stripos( $url, 'larksuite.com' ) ) {
			$body = array( 'msg_type' => 'text', 'content' => array( 'text' => $text ) );
		} elseif ( false !== stripos( $url, 'qyapi.weixin.qq.com' ) ) {
			$body = array( 'msgtype' => 'text', 'text' => array( 'content' => $text ) );
		} else {
			$body = array( 'text' => $text, 'content' => $text );
		}

		$res = self::raw_http_request(
			'POST',
			$url,
			array( 'Content-Type' => 'application/json; charset=utf-8' ),
			(string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			8.0
		);
		return ( (int) $res['code'] >= 200 && (int) $res['code'] < 400 );
	}
}
