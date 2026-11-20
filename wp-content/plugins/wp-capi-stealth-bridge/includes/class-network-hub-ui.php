<?php
/**
 * 5-in-1 Master Admin Console & Control Plane SDK (`class-network-hub-ui.php`).
 *
 * Implements 100% of `b_wp 终极版.md` & `a_capi终极版.md`:
 * - Global 6-Store × (1 Primary + 9 Standby) = 60 BM Slots Dashboard
 * - 7-Day Cold Lock Icon & Secondary Confirmation (`POST /v3/admin/promote-slot`)
 * - Replace Dead BM & Trigger 6.5-Day Historical Backfill Modal (`POST /v3/admin/replace-dead-slot`)
 * - Streaming 90%~95% Stratified Random Shuffled VIP Dealer CSV (`GET /v3/admin/export-seed-csv`)
 * - Live 292-SKU Disguised Catalog Dictionary Manager (`/v3/admin/dictionary`)
 * - Native drop-in compatibility with Claude's `peptide-hq` (`\PeptideHq\Admin\Capi_Bridge_Page`)
 */

namespace StealthCapiBridge {

	defined( 'ABSPATH' ) || exit;

	final class Network_Hub_UI {

		public const SLUG_HQ   = 'pchq-capi-bridge';
		public const SLUG_SITE = 'stealth-capi-bridge';

		private static bool $bound = false;

		public static function register_hooks(): void {
			if ( self::$bound ) {
				return;
			}
			self::$bound = true;

			add_action( 'rest_api_init', array( self::class, 'register_admin_rest_routes' ) );
			add_action( 'admin_menu', array( self::class, 'register_menus' ), 20 );
			add_action( 'network_admin_menu', array( self::class, 'register_network_menu' ), 20 );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
			add_action( 'admin_post_pchq_capi', array( self::class, 'handle_post_action' ) );
		}

		public static function enqueue_assets( string $hook_suffix = '' ): void {
			if ( false === strpos( $hook_suffix, self::SLUG_HQ ) && false === strpos( $hook_suffix, self::SLUG_SITE ) && false === strpos( $hook_suffix, Subsite_Buyer_UI::SLUG ) ) {
				return;
			}
			wp_enqueue_style( 'stealth-capi-admin-hub', STEALTH_CAPI_BRIDGE_URL . 'assets/admin-hub.css', array(), STEALTH_CAPI_BRIDGE_VERSION );
			wp_enqueue_script( 'stealth-capi-admin-hub', STEALTH_CAPI_BRIDGE_URL . 'assets/admin-hub.js', array(), STEALTH_CAPI_BRIDGE_VERSION, true );
		}

		public static function register_menus(): void {
			$has_pchq = class_exists( '\PeptideHq\Admin\Dashboard_Page' )
				|| isset( $GLOBALS['submenu']['pchq'] )
				|| self::top_menu_exists( 'pchq' );

			if ( $has_pchq ) {
				add_submenu_page(
					'pchq',
					'全局像素容灾与经销商种子中心',
					'全局像素容灾中心',
					'manage_options',
					self::SLUG_HQ,
					array( self::class, 'render' )
				);
				return;
			}

			add_options_page(
				'全局像素容灾与经销商种子中心',
				'Stealth CAPI 总控',
				'manage_options',
				self::SLUG_SITE,
				array( self::class, 'render' )
			);
		}

		public static function register_network_menu(): void {
			add_submenu_page(
				'settings.php',
				'全局像素容灾与经销商种子中心',
				'全局像素容灾中心',
				'manage_network_options',
				self::SLUG_SITE,
				array( self::class, 'render' )
			);
		}

		private static function top_menu_exists( string $slug ): bool {
			if ( empty( $GLOBALS['menu'] ) || ! is_array( $GLOBALS['menu'] ) ) {
				return false;
			}
			foreach ( $GLOBALS['menu'] as $item ) {
				if ( isset( $item[2] ) && $slug === $item[2] ) {
					return true;
				}
			}
			return false;
		}

		// --- Cross-Server REST Admin Routes ---
		public static function register_admin_rest_routes(): void {
			$routes = array(
				array( '/admin/status', \WP_REST_Server::READABLE, 'rest_status' ),
				array( '/admin/promote-slot', \WP_REST_Server::CREATABLE, 'rest_promote_slot' ),
				array( '/admin/replace-dead-slot', \WP_REST_Server::CREATABLE, 'rest_replace_dead_slot' ),
				array( '/admin/reload-config', \WP_REST_Server::CREATABLE, 'rest_reload_config' ),
				array( '/admin/export-seed-csv', \WP_REST_Server::READABLE, 'rest_export_seed_csv' ),
				array( '/admin/dictionary', \WP_REST_Server::READABLE, 'rest_dictionary_get' ),
				array( '/admin/dictionary/upsert', \WP_REST_Server::CREATABLE, 'rest_dictionary_upsert' ),
				array( '/admin/dictionary/delete', \WP_REST_Server::CREATABLE, 'rest_dictionary_delete' ),
				array( '/admin/reconcile', \WP_REST_Server::CREATABLE, 'rest_reconcile' ),
				array( '/admin/backfill-wc', \WP_REST_Server::CREATABLE, 'rest_backfill_wc' ),
			);

			foreach ( $routes as $r ) {
				register_rest_route(
					'stealth-capi/v1',
					$r[0],
					array(
						'methods'             => $r[1],
						'callback'            => array( self::class, $r[2] ),
						'permission_callback' => array( self::class, 'check_admin_permission' ),
					)
				);
			}
		}

		public static function check_admin_permission( \WP_REST_Request $request ): bool {
			if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
				return true;
			}
			$cfg        = Config_Manager::get_config();
			$master_key = trim( (string) ( $cfg['admin_master_key'] ?? '' ) );
			if ( '' === $master_key ) {
				return false;
			}
			$candidates = array(
				(string) $request->get_header( 'X-Admin-Master-Key' ),
				(string) $request->get_header( 'X-Stealth-Admin-Key' ),
				(string) $request->get_header( 'X-Admin-Key' ),
			);
			$auth = (string) $request->get_header( 'Authorization' );
			if ( 0 === stripos( $auth, 'Bearer ' ) ) {
				$candidates[] = trim( substr( $auth, 7 ) );
			}
			foreach ( $candidates as $cand ) {
				if ( '' !== $cand && hash_equals( $master_key, $cand ) ) {
					return true;
				}
			}
			return false;
		}

		private static function http_code_from_res( array $res ): int {
			if ( ! empty( $res['ok'] ) ) {
				return 200;
			}
			$st = (int) ( $res['status'] ?? 502 );
			return ( $st >= 400 && $st < 600 ) ? $st : 502;
		}

		public static function rest_status(): \WP_REST_Response {
			$sk = isset( $_GET['site_key'] ) ? sanitize_text_field( wp_unslash( $_GET['site_key'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return new \WP_REST_Response( \StealthCapiBridge::fetch_network_slots_status( $sk ?: null ), 200 );
		}

		public static function rest_promote_slot( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::promote_slot(
				(string) ( $p['site_key'] ?? '' ),
				(string) ( $p['slot_id'] ?? $p['target_slot_id'] ?? '' ),
				! empty( $p['force_override'] )
			);
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_replace_dead_slot( \WP_REST_Request $req ): \WP_REST_Response {
			$res = \StealthCapiBridge::replace_dead_slot( $req->get_json_params() ?: array() );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_reload_config( \WP_REST_Request $req ): \WP_REST_Response {
			$res = \StealthCapiBridge::reload_gateway_config( $req->get_json_params() ?: array() );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_export_seed_csv(): \WP_REST_Response {
			$site_key = isset( $_GET['site_key'] ) ? sanitize_text_field( wp_unslash( $_GET['site_key'] ) ) : 'ALL'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$vip_only = ! isset( $_GET['vip_only'] ) || ( 'false' !== strtolower( sanitize_text_field( wp_unslash( $_GET['vip_only'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ratio    = isset( $_GET['sample_ratio'] ) ? (float) $_GET['sample_ratio'] : 0.92; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$res      = \StealthCapiBridge::export_seed_csv( $site_key, $vip_only, 600.0, $ratio );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_dictionary_get(): \WP_REST_Response {
			$res = \StealthCapiBridge::fetch_sanitizer_dictionary();
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_dictionary_upsert( \WP_REST_Request $req ): \WP_REST_Response {
			$res = \StealthCapiBridge::upsert_dictionary_entry( $req->get_json_params() ?: array() );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_dictionary_delete( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::delete_dictionary_entry( (string) ( $p['raw_sku'] ?? '' ) );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_reconcile(): \WP_REST_Response {
			$stats = Reconcile_Cron::run_all_compensation();
			return new \WP_REST_Response( array( 'ok' => true, 'stats' => $stats ), 200 );
		}

		public static function rest_backfill_wc( \WP_REST_Request $req ): \WP_REST_Response {
			$p     = $req->get_json_params() ?: array();
			$days  = isset( $p['days'] ) ? (int) $p['days'] : 7;
			$stats = Reconcile_Cron::backfill_historical_paid_orders( $days );
			return new \WP_REST_Response( array( 'ok' => true, 'stats' => $stats ), 200 );
		}

		// --- Admin Form POST Handler ---
		public static function handle_post_action(): void {
			$cap = ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_network_admin' ) && is_network_admin() )
				? 'manage_network_options'
				: 'manage_options';

			if ( ! current_user_can( $cap ) ) {
				wp_die( 'Forbidden', 403 );
			}
			check_admin_referer( 'pchq_capi_action_nonce' );

			$sub_action = isset( $_POST['capi_sub_action'] ) ? sanitize_text_field( wp_unslash( $_POST['capi_sub_action'] ) ) : '';
			$tab        = isset( $_POST['return_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['return_tab'] ) ) : 'slots';
			$site_sel   = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : 'site_01';

			if ( 'export_seed_csv' === $sub_action ) {
				$origin   = isset( $_POST['origin_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['origin_site_key'] ) ) : 'ALL';
				$vip_only = ! empty( $_POST['vip_only'] );
				$min_ltv  = isset( $_POST['min_ltv_usd'] ) ? (float) $_POST['min_ltv_usd'] : 600.0;
				$ratio    = round( max( 50, min( 95, isset( $_POST['sample_ratio_pct'] ) ? (int) $_POST['sample_ratio_pct'] : 92 ) ) / 100, 2 );

				$res = \StealthCapiBridge::export_seed_csv( $origin, $vip_only, $min_ltv, $ratio );
				if ( ! empty( $res['ok'] ) && ! empty( $res['csv_content'] ) ) {
					$md5      = substr( md5( $res['csv_content'] ), 0, 12 );
					$filename = 'vip-dealers-seed-' . strtolower( $origin ) . '-' . $md5 . '.csv';
					nocache_headers();
					header( 'Content-Type: text/csv; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
					echo $res['csv_content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					exit;
				}
				self::redirect_back( 'audience', $site_sel, 'err', $res['error'] ?? 'csv_export_failed' );
			}

			$msg_type = 'ok';
			$msg_code = 'saved';

			switch ( $sub_action ) {
				case 'promote_slot':
					$slot_id = isset( $_POST['slot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_id'] ) ) : '';
					$force   = ! empty( $_POST['force_override'] ) && '1' === (string) $_POST['force_override'];
					$res     = \StealthCapiBridge::promote_slot( $site_sel, $slot_id, $force );
					if ( empty( $res['ok'] ) ) {
						$msg_type = 'err';
						$msg_code = ( 409 === (int) ( $res['status'] ?? 0 ) ) ? 'cold_lock_active_7d' : ( $res['error'] ?: 'promote_failed' );
					} else {
						$msg_code = 'slot_promoted';
					}
					break;

				case 'replace_dead_slot':
					$res = \StealthCapiBridge::replace_dead_slot(
						array(
							'site_key'         => $site_sel,
							'slot_id'          => isset( $_POST['slot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_id'] ) ) : 'slot_10',
							'slot_role'        => isset( $_POST['slot_role'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_role'] ) ) : 'STANDBY',
							'bm_label'         => isset( $_POST['bm_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bm_label'] ) ) : '',
							'dataset_id'       => isset( $_POST['dataset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dataset_id'] ) ) : '',
							'access_token'     => isset( $_POST['access_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) ) : '',
							'egress_proxy'     => isset( $_POST['egress_proxy'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['egress_proxy'] ) ) ) : '',
							'event_domain'     => isset( $_POST['event_domain'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['event_domain'] ) ) ) ) : '',
							'test_event_code'  => isset( $_POST['test_event_code'] ) ? sanitize_text_field( wp_unslash( $_POST['test_event_code'] ) ) : '',
							'trigger_backfill' => ! empty( $_POST['trigger_backfill'] ),
							'backfill_hours'   => 156,
						)
					);
					if ( empty( $res['ok'] ) ) {
						$msg_type = 'err';
						$msg_code = $res['error'] ?: 'replace_dead_slot_failed';
					} else {
						$msg_code = 'slot_replaced_and_6_5d_backfilled';
					}
					break;

				case 'reload_gateway':
					$res      = \StealthCapiBridge::reload_gateway_config();
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'gateway_reloaded' : ( $res['error'] ?: 'reload_failed' );
					break;

				case 'dict_upsert':
					$res = \StealthCapiBridge::upsert_dictionary_entry(
						array(
							'raw_sku'         => isset( $_POST['raw_sku'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['raw_sku'] ) ) ) : '',
							'safe_catalog_id' => isset( $_POST['safe_catalog_id'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['safe_catalog_id'] ) ) ) : '',
							'category_tag'    => isset( $_POST['category_tag'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['category_tag'] ) ) ) : 'DAILY_WELLNESS',
						)
					);
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'dict_updated' : 'dict_upsert_failed';
					break;

				case 'dict_bulk_import':
					$lines_raw = isset( $_POST['bulk_lines'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['bulk_lines'] ) ) ) : '';
					$imported  = 0;
					if ( '' !== $lines_raw ) {
						foreach ( preg_split( '/\r\n|\r|\n/', $lines_raw ) as $line ) {
							$parts = array_map( 'trim', explode( ',', $line ) );
							if ( count( $parts ) >= 2 && '' !== $parts[0] && '' !== $parts[1] && 'RAW_SKU' !== strtoupper( $parts[0] ) ) {
								$r = \StealthCapiBridge::upsert_dictionary_entry(
									array(
										'raw_sku'         => strtoupper( $parts[0] ),
										'safe_catalog_id' => strtoupper( $parts[1] ),
										'category_tag'    => isset( $parts[2] ) && '' !== $parts[2] ? strtoupper( $parts[2] ) : 'DAILY_WELLNESS',
									)
								);
								if ( ! empty( $r['ok'] ) ) {
									++$imported;
								}
							}
						}
					}
					$msg_code = 'dict_bulk_imported_' . $imported;
					break;

				case 'dict_delete':
					$raw_sku  = isset( $_POST['raw_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['raw_sku'] ) ) : '';
					$res      = \StealthCapiBridge::delete_dictionary_entry( $raw_sku );
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'dict_deleted' : 'dict_delete_failed';
					break;

				case 'save_plugin_config':
					$gateway_url      = isset( $_POST['gateway_base_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['gateway_base_url'] ) ), array( 'http', 'https' ) ) : '';
					$admin_master_key = isset( $_POST['admin_master_key'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_master_key'] ) ) : '';
					$trusted_proxies  = isset( $_POST['trusted_proxies'] ) ? sanitize_text_field( wp_unslash( $_POST['trusted_proxies'] ) ) : '';
					$t_site_key       = isset( $_POST['tenant_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['tenant_site_key'] ) ) : '';
					$t_secret         = isset( $_POST['tenant_api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['tenant_api_secret'] ) ) : '';
					$t_blog_id        = isset( $_POST['tenant_blog_id'] ) ? max( 1, (int) $_POST['tenant_blog_id'] ) : 1;
					$t_store_code     = isset( $_POST['tenant_store_code'] ) ? sanitize_text_field( wp_unslash( $_POST['tenant_store_code'] ) ) : '';
					$t_mode           = isset( $_POST['tenant_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['tenant_mode'] ) ) : Config_Manager::MODE_AD_ACQUISITION;
					$tenants_json     = isset( $_POST['tenants_by_blog_id_json'] ) ? trim( wp_unslash( $_POST['tenants_by_blog_id_json'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

					$tenants_map = array();
					if ( '' !== $tenants_json ) {
						$decoded = json_decode( $tenants_json, true );
						if ( is_array( $decoded ) ) {
							$tenants_map = $decoded;
						}
					}

					$is_hub = ( Config_Manager::MODE_ORGANIC_RETENTION_HUB === $t_mode );
					Config_Manager::save_config(
						array(
							'enabled'               => ! empty( $_POST['enabled'] ),
							'gateway_base_url'      => $gateway_url,
							'admin_master_key'      => $admin_master_key,
							'suppress_legacy_meta'  => ! empty( $_POST['suppress_legacy_meta'] ),
							'auto_enqueue_sdk'      => ! empty( $_POST['auto_enqueue_sdk'] ),
							'enable_server_shallow' => ! empty( $_POST['enable_server_shallow'] ),
							'trust_private_proxies' => ! empty( $_POST['trust_private_proxies'] ),
							'trusted_proxies'       => array_values( array_filter( array_map( 'trim', explode( ',', $trusted_proxies ) ) ) ),
							'default_tenant'        => array(
								'enabled'               => ( '' !== $t_site_key && '' !== $t_secret ),
								'blog_id'               => $t_blog_id,
								'site_key'              => $t_site_key,
								'api_secret'            => $t_secret,
								'store_code'            => $t_store_code,
								'tenant_mode'           => $t_mode,
								'is_repurchase_station' => $is_hub,
							),
							'tenants_by_blog_id'    => $tenants_map,
						)
					);
					$msg_code = 'config_saved';
					break;

				case 'reconcile_now':
					Reconcile_Cron::run_all_compensation();
					$msg_code = 'batch_reconcile_completed';
					break;

				case 'backfill_local_wc':
					$days = isset( $_POST['backfill_days'] ) ? (int) $_POST['backfill_days'] : 7;
					Reconcile_Cron::backfill_historical_paid_orders( $days );
					$msg_code = 'local_wc_backfill_done';
					break;
			}

			self::redirect_back( $tab, $site_sel, $msg_type, $msg_code );
		}

		private static function redirect_back( string $tab, string $site_key, string $status, string $msg ): void {
			$referer = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG_HQ );
			$url     = add_query_arg(
				array(
					'tab'       => $tab,
					'site_view' => $site_key,
					'capi_st'   => $status,
					'capi_msg'  => $msg,
				),
				$referer
			);
			wp_safe_redirect( $url );
			exit;
		}

		// --- Render Full 4-Tab Super Admin Console ---
		public static function render(): void {
			$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'slots'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$site_view  = isset( $_GET['site_view'] ) ? sanitize_text_field( wp_unslash( $_GET['site_view'] ) ) : 'site_01'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$capi_st    = isset( $_GET['capi_st'] ) ? sanitize_text_field( wp_unslash( $_GET['capi_st'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$capi_msg   = isset( $_GET['capi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['capi_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$catalog    = Config_Manager::get_stores_catalog();
			if ( ! isset( $catalog[ $site_view ] ) ) {
				$site_view = 'site_01';
			}

			$page_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : self::SLUG_SITE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$base_page = admin_url( ( self::SLUG_HQ === $page_slug ? 'admin.php' : 'options-general.php' ) . '?page=' . rawurlencode( $page_slug ) );
			?>
			<div class="wrap scapi-console">
				<h1>全局像素容灾与经销商种子中心 <span style="font-size:13px;font-weight:normal;color:#64748b;">(V3.2 终极全量版 · 6店×1主9备=60槽 · 7天冷置锁 · 6.5天换号回填 · LTV随机洗牌CSV)</span></h1>

				<?php if ( ! Config_Manager::is_connector_ready() ) : ?>
					<div class="notice notice-info"><p>连接器待命。启用连接器并配置网关地址后可连接网关。</p></div>
				<?php endif; ?>

				<?php if ( '' !== $capi_msg ) : ?>
					<div class="notice <?php echo 'err' === $capi_st ? 'notice-error' : 'notice-success'; ?>">
						<p><strong><?php echo 'err' === $capi_st ? '拦截 / 异常：' : '执行完成：'; ?></strong> <code><?php echo esc_html( $capi_msg ); ?></code></p>
					</div>
				<?php endif; ?>

				<nav class="scapi-tabs">
					<a class="scapi-tab <?php echo 'slots' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'slots', 'site_view' => $site_view ), $base_page ) ); ?>">1. 全局像素槽位热切大盘 (1主+9备)</a>
					<a class="scapi-tab <?php echo 'dictionary' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'dictionary', $base_page ) ); ?>">2. 伪装 SKU 脱敏字典 (292条预载)</a>
					<a class="scapi-tab <?php echo 'audience' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'audience', $base_page ) ); ?>">3. 高价值经销商防关联 CSV 导出</a>
					<a class="scapi-tab <?php echo 'settings' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_page ) ); ?>">4. 节点模式与 10分钟双向核账中心</a>
				</nav>

				<?php
				if ( 'dictionary' === $active_tab ) {
					self::render_tab_dictionary();
				} elseif ( 'audience' === $active_tab ) {
					self::render_tab_audience( $catalog );
				} elseif ( 'settings' === $active_tab ) {
					self::render_tab_settings();
				} else {
					self::render_tab_slots( $site_view, $catalog, $base_page );
				}
				?>
			</div>
			<?php
		}

		private static function render_tab_slots( string $site_view, array $catalog, string $base_page ): void {
			$overview  = \StealthCapiBridge::fetch_network_slots_status( $site_view );
			$gw_slots  = $overview['gateway_slots'] ?? array();
			$sites_map = $gw_slots['sites'] ?? array();
			$site_info = $sites_map[ $site_view ] ?? array();
			$slots     = isset( $site_info['slots'] ) && is_array( $site_info['slots'] ) ? $site_info['slots'] : array();
			if ( empty( $slots ) && ! empty( $gw_slots['slots'] ) && is_array( $gw_slots['slots'] ) ) {
				foreach ( $gw_slots['slots'] as $item ) {
					if ( is_array( $item ) && ( ! isset( $item['site_key'] ) || $site_view === (string) $item['site_key'] ) ) {
						$slots[] = $item;
					}
				}
			}
			?>
			<div class="scapi-store-pills">
				<?php foreach ( $catalog as $sk => $meta ) : ?>
					<?php if ( 'repurchase_hub_02' === $sk ) { continue; } ?>
					<a class="scapi-pill <?php echo $sk === $site_view ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'slots', 'site_view' => $sk ), $base_page ) ); ?>">
						<?php echo esc_html( $meta['label'] ); ?> (<?php echo esc_html( $sk ); ?>)
					</a>
				<?php endforeach; ?>
			</div>

			<div class="scapi-card">
				<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
					<h2 style="margin:0;">当前站点：<?php echo esc_html( $catalog[ $site_view ]['label'] ?? $site_view ); ?> — 1 主 + 9 备槽位实时状态</h2>
					<div>
						<button type="button" class="button button-primary" onclick="window.StealthHubUI && StealthHubUI.openReplaceModal('<?php echo esc_js( $site_view ); ?>','slot_10','STANDBY','');">+ 替换死号 / 补新绿盾 BM (回填6.5天单)</button>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:6px;">
							<input type="hidden" name="action" value="pchq_capi" />
							<input type="hidden" name="capi_sub_action" value="reload_gateway" />
							<input type="hidden" name="return_tab" value="slots" />
							<input type="hidden" name="site_key" value="<?php echo esc_attr( $site_view ); ?>" />
							<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
							<button type="submit" class="button">热重载网关配置</button>
						</form>
					</div>
				</div>

				<table class="scapi-table">
					<thead>
						<tr>
							<th>槽位</th>
							<th>角色</th>
							<th>绿盾 BM ID / 比特环境备注</th>
							<th>Dataset (Pixel) ID</th>
							<th>独享住宅代理</th>
							<th>电路灯态</th>
							<th>7天冷置保护锁</th>
							<th>深层购买 / 浅层信号</th>
							<th>操作</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $slots ) ) : ?>
							<tr><td colspan="9" style="color:#64748b;">暂无槽位数据，请点击右上角按钮添加槽位。</td></tr>
						<?php else : ?>
							<?php foreach ( $slots as $sl ) : ?>
								<?php
								$s_id       = (string) ( $sl['slot_id'] ?? '' );
								$role       = strtoupper( (string) ( $sl['slot_role'] ?? $sl['role'] ?? 'STANDBY' ) );
								$enabled_v  = $sl['is_enabled'] ?? $sl['enabled'] ?? true;
								$is_enabled = ! empty( $enabled_v );
								$circuit    = strtoupper( (string) ( $sl['circuit_state'] ?? 'CLOSED' ) );
								$w_days     = (float) ( $sl['warmup_days'] ?? 0 );
								$min_days   = (int) ( $sl['min_warmup_days'] ?? $sl['min_warmup_days_before_promote'] ?? $sl['silent_lock_days'] ?? 7 );
								$eligible   = ! empty( $sl['promotion_eligible'] ) || ( $w_days >= $min_days && 'CLOSED' === $circuit );
								$dot_cls    = 'CLOSED' === $circuit ? 'green' : ( 'PAUSED_RATE_LIMIT' === $circuit ? 'yellow' : 'red' );
								$badge_cls  = 'CLOSED' === $circuit ? 'ok' : ( 'PAUSED_RATE_LIMIT' === $circuit ? 'warn' : 'err' );
								$bm_label   = (string) ( $sl['bm_label'] ?? trim( ( $sl['bm_id'] ?? '' ) . ' ' . ( $sl['profile_memo'] ?? '' ) ) );
								$p_cnt      = (int) ( $sl['delivered_purchase_count'] ?? $sl['total_delivered'] ?? 0 );
								$sh_cnt     = (int) ( $sl['delivered_shallow_count'] ?? 0 );
								?>
								<tr style="<?php echo ! $is_enabled ? 'opacity:0.55;' : ''; ?>">
									<td><code><?php echo esc_html( $s_id ); ?></code></td>
									<td>
										<?php if ( ! $is_enabled ) : ?>
											<span class="scapi-badge err">已停用下线</span>
										<?php elseif ( 'PRIMARY' === $role ) : ?>
											<span class="scapi-badge primary">PRIMARY 主力 (留_fbc)</span>
										<?php else : ?>
											<span class="scapi-badge ok">STANDBY 热备 (剥_fbc)</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $bm_label ?: '-' ); ?></td>
									<td><code><?php echo esc_html( (string) ( $sl['dataset_id'] ?? '-' ) ); ?></code></td>
									<td><code><?php echo esc_html( (string) ( $sl['egress_proxy_masked'] ?? '-' ) ); ?></code></td>
									<td><span class="scapi-badge <?php echo esc_attr( $badge_cls ); ?>"><span class="scapi-dot <?php echo esc_attr( $dot_cls ); ?>"></span><?php echo esc_html( $circuit ); ?></span></td>
									<td>
										<?php if ( 'PRIMARY' === $role && $is_enabled ) : ?>
											<strong>主力实时投递中</strong>
										<?php else : ?>
											<?php echo esc_html( number_format( $w_days, 1 ) . ' / ' . $min_days . ' 天' ); ?>
											<?php echo $eligible ? '<span class="scapi-badge ok">🔓 已满7天可晋升</span>' : '<span class="scapi-badge warn">🔒 冷置保护中</span>'; ?>
										<?php endif; ?>
									</td>
									<td><strong><?php echo esc_html( (string) $p_cnt ); ?></strong> 单 / <?php echo esc_html( (string) $sh_cnt ); ?> 浅层</td>
									<td>
										<?php if ( 'STANDBY' === $role && $is_enabled ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:4px;" onsubmit="return window.StealthHubUI ? StealthHubUI.confirmPromote(this, <?php echo $eligible ? 'false' : 'true'; ?>, '<?php echo esc_js( number_format( $w_days, 1 ) ); ?>', '<?php echo esc_js( (string) $min_days ); ?>') : true;">
												<input type="hidden" name="action" value="pchq_capi" />
												<input type="hidden" name="capi_sub_action" value="promote_slot" />
												<input type="hidden" name="return_tab" value="slots" />
												<input type="hidden" name="site_key" value="<?php echo esc_attr( $site_view ); ?>" />
												<input type="hidden" name="slot_id" value="<?php echo esc_attr( $s_id ); ?>" />
												<input type="hidden" name="force_override" value="0" />
												<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
												<button type="submit" class="button button-small <?php echo $eligible ? 'button-primary' : ''; ?>">一键晋升为主力</button>
											</form>
										<?php endif; ?>
										<button type="button" class="button button-small" onclick="window.StealthHubUI && StealthHubUI.openReplaceModal('<?php echo esc_js( $site_view ); ?>','<?php echo esc_js( $s_id ); ?>','<?php echo esc_js( $role ); ?>','<?php echo esc_js( $bm_label ); ?>');">换死号并回填6.5天</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Replace Dead BM & 6.5-Day Historical Backfill Modal -->
			<div id="scapi-replace-modal" class="scapi-modal-backdrop">
				<div class="scapi-modal">
					<h2 style="margin-top:0;">替换死号 BM / 补充新绿盾老 BM（自动触发过去 6.5 天订单补推）</h2>
					<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#92400e;">
						<strong>入池防封铁律：</strong> ① 仅限带绿盾 <code>Verified</code> 欧美企业认证 BM（150元不包跨BM分享号完全可用，严禁白BM）；② 严禁跨 BM 分享像素；③ 绑定独享美国双 ISP 静态住宅 IP。
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="replace_dead_slot" />
						<input type="hidden" name="return_tab" value="slots" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<p>
							站点 Key: <input type="text" name="site_key" value="<?php echo esc_attr( $site_view ); ?>" style="width:110px;" readonly />
							槽位编号: <input type="text" name="slot_id" value="slot_10" style="width:110px;" required />
							角色: <select name="slot_role"><option value="STANDBY" selected>STANDBY 热备</option><option value="PRIMARY">PRIMARY 主力</option></select>
						</p>
						<p><label>绿盾 BM ID 与比特窗口台账备注：<br /><input type="text" name="bm_label" class="large-text" placeholder="例如：BM-8802991 | Bit-#210 (Verizon US)" required /></label></p>
						<p><label>新 Dataset (Pixel) ID：<br /><input type="text" name="dataset_id" class="large-text" placeholder="109283746501928" required /></label></p>
						<p><label>新 System User CAPI Access Token：<br /><input type="password" name="access_token" class="large-text" placeholder="EAAB..." required /></label></p>
						<p><label>该绿盾 BM 验证绑定的专属独立域名 (`event_domain`，一域名一BM隔离)：<br /><input type="text" name="event_domain" class="large-text" placeholder="例如：luminacare-wellness.com（留空则继承站点默认域名）" /></label></p>
						<p><label>独享美国双 ISP 静态住宅代理：<br /><input type="text" name="egress_proxy" class="large-text" placeholder="http://user:pass@198.51.100.88:8000" /></label></p>
						<p><label>可选 Test Event Code：<input type="text" name="test_event_code" style="width:150px;" /></label></p>
						<p><label><input type="checkbox" name="trigger_backfill" value="1" checked /> <strong>立即将本站过去 6.5 天（156小时）真实转化订单解密回填至该新槽位热身</strong></label></p>
						<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
							<button type="button" class="button" onclick="window.StealthHubUI && StealthHubUI.closeReplaceModal();">取消</button>
							<button type="submit" class="button button-primary">确认替换并启动 6.5 天回填</button>
						</div>
					</form>
				</div>
			</div>

			<!-- Fallback inline form if JS modal not clicked -->
			<details class="scapi-card">
				<summary style="cursor:pointer;font-weight:600;">展开页内快捷补号表单（免弹窗模式）</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="pchq_capi" />
					<input type="hidden" name="capi_sub_action" value="replace_dead_slot" />
					<input type="hidden" name="return_tab" value="slots" />
					<input type="hidden" name="site_key" value="<?php echo esc_attr( $site_view ); ?>" />
					<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
					<p>槽位: <input type="text" name="slot_id" value="slot_10" style="width:90px;" /> 备注: <input type="text" name="bm_label" placeholder="BM-ID | Bit窗口号" style="width:200px;" /> Dataset ID: <input type="text" name="dataset_id" style="width:170px;" required /> Token: <input type="password" name="access_token" style="width:180px;" required /> 代理: <input type="text" name="egress_proxy" placeholder="http://u:p@ip:port" style="width:200px;" /> <input type="hidden" name="slot_role" value="STANDBY" /><input type="hidden" name="trigger_backfill" value="1" /><button type="submit" class="button button-primary">写入并回填6.5天</button></p>
				</form>
			</details>
			<?php
		}

		private static function render_tab_dictionary(): void {
			$dict_res = \StealthCapiBridge::fetch_sanitizer_dictionary();
			$sku_map  = $dict_res['data']['sku_map'] ?? array();
			?>
			<div class="scapi-grid-2">
				<div class="scapi-card">
					<h2 style="margin-top:0;">已生效伪装 SKU 映射字典（当前共 <?php echo esc_html( (string) count( $sku_map ) ); ?> 条）</h2>
					<div style="max-height:480px;overflow:auto;">
						<table class="scapi-table">
							<thead><tr><th>原下单 SKU</th><th>伪装合规目录 ID</th><th>类目</th><th>操作</th></tr></thead>
							<tbody>
								<?php foreach ( $sku_map as $raw_sku => $info ) : ?>
									<?php
									$safe_id = is_array( $info ) ? (string) ( $info['safe_catalog_id'] ?? $info['clean_code'] ?? '' ) : (string) $info;
									$cat     = is_array( $info ) ? (string) ( $info['category_tag'] ?? 'DAILY_WELLNESS' ) : 'DAILY_WELLNESS';
									?>
									<tr>
										<td><code><?php echo esc_html( (string) $raw_sku ); ?></code></td>
										<td><span class="scapi-badge ok"><?php echo esc_html( $safe_id ); ?></span></td>
										<td><code><?php echo esc_html( $cat ); ?></code></td>
										<td>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<input type="hidden" name="action" value="pchq_capi" />
												<input type="hidden" name="capi_sub_action" value="dict_delete" />
												<input type="hidden" name="return_tab" value="dictionary" />
												<input type="hidden" name="raw_sku" value="<?php echo esc_attr( (string) $raw_sku ); ?>" />
												<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
												<button type="submit" class="button button-small button-link-delete">删除</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<div class="scapi-card">
					<h2 style="margin-top:0;">新增 / 批量导入新品伪装 SKU</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="dict_upsert" />
						<input type="hidden" name="return_tab" value="dictionary" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<p><label>原始下单 SKU：<br /><input type="text" name="raw_sku" class="regular-text" placeholder="TR20" required /></label></p>
						<p><label>伪装合规 ID (`safe_catalog_id`)：<br /><input type="text" name="safe_catalog_id" class="regular-text" placeholder="WLN-110-03" required /></label></p>
						<p><label>类目标签：<br /><input type="text" name="category_tag" class="regular-text" value="DAILY_WELLNESS" /></label></p>
						<?php submit_button( '保存单条映射' ); ?>
					</form>
					<hr />
					<h4>批量粘贴导入（每行：<code>原SKU,伪装ID,类目</code>）</h4>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="dict_bulk_import" />
						<input type="hidden" name="return_tab" value="dictionary" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<textarea name="bulk_lines" rows="4" class="large-text code" placeholder="NEW-SKU-01,WLN-199-01,DAILY_WELLNESS"></textarea>
						<?php submit_button( '批量导入并热生效', 'secondary' ); ?>
					</form>
				</div>
			</div>
			<?php
		}

		private static function render_tab_audience( array $catalog ): void {
			?>
			<div class="scapi-card">
				<h2 style="margin-top:0;">高价值批发经销商（LTV &gt;= $600）随机抽样洗牌 CSV 导出</h2>
				<p style="color:#475569;font-size:13px;">调用网关 <code>GET /v3/admin/export-seed-csv</code>，强制执行 <strong>90% ~ 95% 随机分层抽样与行序洗牌</strong>，使每次导出的 CSV 文件 MD5 哈希值与行数均不相同，彻底斩断跨 BM 上传同源种子人群包的关联风控。</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pchq_capi" />
					<input type="hidden" name="capi_sub_action" value="export_seed_csv" />
					<input type="hidden" name="return_tab" value="audience" />
					<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th>来源站点范围</th>
							<td>
								<select name="origin_site_key">
									<option value="ALL">全网聚合池 (ALL)</option>
									<?php foreach ( $catalog as $sk => $meta ) : ?>
										<?php if ( 'repurchase_hub_02' === $sk ) { continue; } ?>
										<option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $meta['label'] . ' (' . $sk . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th>LTV 门槛与 VIP 过滤</th>
							<td><input type="number" name="min_ltv_usd" value="600" style="width:110px;" /> USD &nbsp; <label><input type="checkbox" name="vip_only" value="1" checked /> 仅导出满 $600 批发经销商 (`vip_only=true`)</label></td>
						</tr>
						<tr>
							<th>随机抽样比例 (%)</th>
							<td><input type="number" name="sample_ratio_pct" value="92" min="80" max="95" style="width:90px;" /> % <span class="description">（标准范围 90% ~ 95%）</span></td>
						</tr>
					</table>
					<?php submit_button( '一键随机抽样洗牌并下载经销商 CSV' ); ?>
				</form>
			</div>
			<?php
		}

		private static function render_tab_settings(): void {
			$cfg    = Config_Manager::get_config();
			$dt     = $cfg['default_tenant'] ?? array();
			$health = Reconcile_Cron::get_local_health();
			$mode   = ! empty( $dt['is_repurchase_station'] ) ? Config_Manager::MODE_ORGANIC_RETENTION_HUB : (string) ( $dt['tenant_mode'] ?? Config_Manager::MODE_AD_ACQUISITION );
			?>
			<div class="scapi-grid-2">
				<div class="scapi-card">
					<h2 style="margin-top:0;">站点连接器与模式配置 (`AD_ACQUISITION` / `ORGANIC_RETENTION_HUB`)</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="save_plugin_config" />
						<input type="hidden" name="return_tab" value="settings" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> /> <strong>启用本节点 Stealth CAPI 连接器</strong></label></p>
						<p><label>网关内网 URL：<br /><input type="url" class="large-text" name="gateway_base_url" value="<?php echo esc_attr( (string) $cfg['gateway_base_url'] ); ?>" /></label></p>
						<p><label>网关 `X-Admin-Master-Key`：<br /><input type="password" class="large-text" name="admin_master_key" value="<?php echo esc_attr( (string) $cfg['admin_master_key'] ); ?>" /></label></p>
						<p>
							<label>租户运行模式 (`tenant_mode`)：<br />
								<select name="tenant_mode">
									<option value="<?php echo esc_attr( Config_Manager::MODE_AD_ACQUISITION ); ?>" <?php selected( Config_Manager::MODE_AD_ACQUISITION, $mode ); ?>>AD_ACQUISITION — 前端投流获客站 (注入 fp-pulse.js，推 /v3/events/ingest)</option>
									<option value="<?php echo esc_attr( Config_Manager::MODE_ORGANIC_RETENTION_HUB ); ?>" <?php selected( Config_Manager::MODE_ORGANIC_RETENTION_HUB, $mode ); ?>>ORGANIC_RETENTION_HUB — 12月 2号纯复购总站 (前台 0 脚本纯净，推 /v3/events/repurchase-relay)</option>
								</select>
							</label>
						</p>
						<p>Blog ID: <input type="number" name="tenant_blog_id" value="<?php echo esc_attr( (string) ( $dt['blog_id'] ?? 1 ) ); ?>" style="width:75px;" /> 店铺号: <input type="text" name="tenant_store_code" value="<?php echo esc_attr( (string) ( $dt['store_code'] ?? '' ) ); ?>" style="width:95px;" /> Site Key: <input type="text" name="tenant_site_key" value="<?php echo esc_attr( (string) ( $dt['site_key'] ?? '' ) ); ?>" style="width:110px;" /></p>
						<p><label>站点 HMAC Secret：<br /><input type="password" class="large-text" name="tenant_api_secret" value="<?php echo esc_attr( (string) ( $dt['api_secret'] ?? '' ) ); ?>" /></label></p>
						<p><label><input type="checkbox" name="suppress_legacy_meta" value="1" <?php checked( ! empty( $cfg['suppress_legacy_meta'] ) ); ?> /> 自动屏蔽 `peptide-core` 旧版 Facebook 像素防双发（保留 GA4/GAds/TikTok 不动）</label></p>
						<p><label><input type="checkbox" name="auto_enqueue_sdk" value="1" <?php checked( ! empty( $cfg['auto_enqueue_sdk'] ) ); ?> /> 自动注入 `fp-pulse.js` (&lt;3KB)</label></p>
						<p><label><input type="checkbox" name="enable_server_shallow" value="1" <?php checked( ! empty( $cfg['enable_server_shallow'] ) ); ?> /> 启用服务端注册与双路径加购钩子兜底</label></p>
						<p><label>可信反向代理 CIDR：<br /><input type="text" class="large-text" name="trusted_proxies" value="<?php echo esc_attr( implode( ',', (array) ( $cfg['trusted_proxies'] ?? array() ) ) ); ?>" /></label></p>
						<p><label><input type="checkbox" name="trust_private_proxies" value="1" <?php checked( ! empty( $cfg['trust_private_proxies'] ) ); ?> /> 信任本地/VPC 私有代理网段</label></p>
						<p><label>Multisite 子站映射 JSON (可选)：<br /><textarea name="tenants_by_blog_id_json" rows="3" class="large-text code"><?php echo esc_textarea( wp_json_encode( $cfg['tenants_by_blog_id'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea></label></p>
						<?php submit_button( '保存配置' ); ?>
					</form>
				</div>

				<div class="scapi-card">
					<h2 style="margin-top:0;">10 分钟双向差额核账 (`/v3/reconcile/batch-check`) 与历史补推</h2>
					<pre style="background:#0f172a;color:#f8fafc;padding:14px;border-radius:8px;font-size:12px;"><?php echo esc_html( wp_json_encode( $health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="reconcile_now" />
						<input type="hidden" name="return_tab" value="settings" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<?php submit_button( '立即向网关发起 batch-check 双向差额核账补推', 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="backfill_local_wc" />
						<input type="hidden" name="return_tab" value="settings" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						回溯天数：<input type="number" name="backfill_days" value="7" min="1" max="7" style="width:65px;" /> 天 &nbsp;
						<?php submit_button( '补推本店本地 WooCommerce 历史已付订单', 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>
			<?php
		}
	}
}

// Global PHP Facade `\StealthCapiBridge` & `peptide-hq` drop-in `\PeptideHq\Admin\Capi_Bridge_Page`
namespace {

	defined( 'ABSPATH' ) || exit;

	if ( ! class_exists( 'StealthCapiBridge', false ) ) {
		final class StealthCapiBridge {

			public static function fetch_network_slots_status( ?string $site_key = null ): array {
				$path = '/v3/admin/slots-status';
				if ( null !== $site_key && '' !== trim( $site_key ) ) {
					$path .= '?site_key=' . rawurlencode( trim( $site_key ) );
				}
				$gw     = \StealthCapiBridge\Config_Manager::gateway_admin_request( 'GET', $path, null, 4.0 );
				$cfg    = \StealthCapiBridge\Config_Manager::get_config();
				$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();

				return array(
					'ok'             => $gw['ok'],
					'status'         => $gw['status'],
					'gateway_url'    => rtrim( (string) $cfg['gateway_base_url'], '/' ),
					'gateway_error'  => $gw['error'],
					'gateway_slots'  => $gw['data'],
					'stores_catalog' => \StealthCapiBridge\Config_Manager::get_stores_catalog(),
					'current_tenant' => $tenant ? array(
						'site_key'    => $tenant['site_key'],
						'blog_id'     => $tenant['blog_id'],
						'store_code'  => $tenant['store_code'],
						'tenant_mode' => $tenant['tenant_mode'],
					) : null,
					'local_health'   => \StealthCapiBridge\Reconcile_Cron::get_local_health(),
				);
			}

			public static function promote_slot( string $site_key, string $slot_id, bool $force_override = false ): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/promote-slot',
					array(
						'site_key'       => trim( $site_key ),
						'slot_id'        => trim( $slot_id ),
						'force_override' => $force_override,
					),
					5.0
				);
			}

			public static function replace_dead_slot( array $slot_data ): array {
				$proxy     = trim( (string) ( $slot_data['egress_proxy'] ?? '' ) );
				$ev_domain = strtolower( trim( (string) ( $slot_data['event_domain'] ?? '' ) ) );
				$ev_domain = preg_replace( '#^https?://#i', '', $ev_domain );
				$ev_domain = trim( (string) $ev_domain, '/' );
				$test_code = trim( (string) ( $slot_data['test_event_code'] ?? '' ) );
				$payload   = array(
					'site_key'         => trim( (string) ( $slot_data['site_key'] ?? '' ) ),
					'slot_id'          => trim( (string) ( $slot_data['slot_id'] ?? 'slot_10' ) ),
					'bm_label'         => sanitize_text_field( (string) ( $slot_data['bm_label'] ?? '' ) ),
					'dataset_id'       => preg_replace( '/\s+/', '', (string) ( $slot_data['dataset_id'] ?? '' ) ),
					'access_token'     => trim( (string) ( $slot_data['access_token'] ?? '' ) ),
					'egress_proxy'     => '' !== $proxy ? $proxy : null,
					'event_domain'     => '' !== $ev_domain ? $ev_domain : null,
					'test_event_code'  => '' !== $test_code ? $test_code : null,
					'trigger_backfill' => ! isset( $slot_data['trigger_backfill'] ) || ! empty( $slot_data['trigger_backfill'] ),
				);
				if ( '' === $payload['site_key'] || '' === $payload['dataset_id'] || '' === $payload['access_token'] ) {
					return array( 'ok' => false, 'status' => 400, 'error' => 'missing_required_bm_slot_fields', 'data' => array() );
				}
				return \StealthCapiBridge\Config_Manager::gateway_admin_request( 'POST', '/v3/admin/replace-dead-slot', $payload, 8.0 );
			}

			public static function export_seed_csv( string $site_key = 'ALL', bool $vip_only = true, float $min_ltv = 600.0, float $sample_ratio = 0.92 ): array {
				$qs  = http_build_query(
					array(
						'site_key'     => $site_key,
						'vip_only'     => $vip_only ? 'true' : 'false',
						'min_ltv'      => $min_ltv,
						'sample_ratio' => max( 0.50, min( 0.95, $sample_ratio ) ),
					)
				);
				$res = \StealthCapiBridge\Config_Manager::gateway_admin_request( 'GET', '/v3/admin/export-seed-csv?' . $qs, null, 10.0, true );
				if ( ! $res['ok'] ) {
					return $res;
				}
				$csv = (string) ( $res['data']['csv_content'] ?? $res['body'] ?? '' );
				return array(
					'ok'                => true,
					'status'            => 200,
					'csv_content'       => $csv,
					'batch_fingerprint' => md5( $csv ),
				);
			}

			public static function fetch_sanitizer_dictionary(): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request( 'GET', '/v3/admin/dictionary', null, 4.0 );
			}

			public static function upsert_dictionary_entry( array $entry ): array {
				if ( isset( $entry['clean_code'] ) && empty( $entry['safe_catalog_id'] ) ) {
					$entry['safe_catalog_id'] = $entry['clean_code'];
				}
				return \StealthCapiBridge\Config_Manager::gateway_admin_request( 'POST', '/v3/admin/dictionary/upsert', $entry, 4.0 );
			}

			public static function delete_dictionary_entry( string $raw_sku ): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request( 'POST', '/v3/admin/dictionary/delete', array( 'raw_sku' => trim( $raw_sku ) ), 4.0 );
			}

			public static function reload_gateway_config( array $payload = array() ): array {
				if ( ! empty( $payload['wp_plugin_config'] ) && is_array( $payload['wp_plugin_config'] ) ) {
					\StealthCapiBridge\Config_Manager::save_config( $payload['wp_plugin_config'] );
				}
				$res = \StealthCapiBridge\Config_Manager::gateway_admin_request( 'POST', '/v3/admin/reload-config', $payload, 4.0 );
				return array( 'ok' => $res['ok'], 'status' => $res['status'], 'error' => $res['error'], 'response' => $res['data'] );
			}

			public static function relay_repurchase_order( $order_or_id ): array {
				if ( ! function_exists( 'wc_get_order' ) ) {
					return array( 'ok' => false, 'error' => 'woocommerce_unavailable' );
				}
				$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
				if ( ! $order instanceof \WC_Order ) {
					return array( 'ok' => false, 'error' => 'order_not_found' );
				}
				$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
				if ( ! $tenant ) {
					return array( 'ok' => false, 'error' => 'tenant_not_configured' );
				}
				$frozen_ctx          = \StealthCapiBridge\Context_Freezer::read_frozen_context( $order );
				$action_uuid         = sprintf( 'rep_%d_%d_purchase', (int) $tenant['blog_id'], (int) $order->get_id() );
				$payload             = \StealthCapiBridge\Context_Freezer::build_order_payload( $order, 'Purchase', $action_uuid, $frozen_ctx, $tenant );
				$payload['fbp']      = '';
				$payload['fbc']      = '';
				return \StealthCapiBridge\Config_Manager::dispatch_to_gateway( $payload, $tenant, '/v3/events/repurchase-relay' );
			}
		}
	}
}

namespace PeptideHq\Admin {

	defined( 'ABSPATH' ) || exit;

	if ( ! class_exists( 'PeptideHq\Admin\Capi_Bridge_Page', false ) ) {
		final class Capi_Bridge_Page {
			public const SLUG = 'pchq-capi-bridge';

			public static function hooks(): void {
				\StealthCapiBridge\Network_Hub_UI::register_hooks();
			}

			public static function render(): void {
				\StealthCapiBridge\Network_Hub_UI::render();
			}

			public static function handle(): void {
				\StealthCapiBridge\Network_Hub_UI::handle_post_action();
			}
		}
	}
}
