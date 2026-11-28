<?php
/**
 * 五合一全局像素容灾与经销商种子总控台 (`class-network-hub-ui.php`)。
 *
 * 功能覆盖：
 * - 六店 ×（一主 + 九备）= 六十个企业号槽位实时热切大盘
 * - 七天冷置保护锁与二次确认晋升（POST /v3/admin/promote-slot）
 * - 死号替换并自动触发过去 6.5 天历史订单补推弹窗（POST /v3/admin/replace-dead-slot）
 * - 90%~95% 随机抽样洗牌高价值批发客户防关联名单导出（GET /v3/admin/export-seed-csv）
 * - 292 条伪装货号映射字典实时在线增删改查（/v3/admin/dictionary）
 * - 原生兼容总控后台菜单（\PeptideHq\Admin\Capi_Bridge_Page）
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
			add_action( 'wp_ajax_scapi_roxy_browser_relay', array( self::class, 'ajax_roxy_browser_relay' ) );
			add_action( 'wp_ajax_scapi_roxy_save_cfg', array( self::class, 'ajax_roxy_save_cfg' ) );
			add_action( 'wp_ajax_scapi_roxy_save_sop', array( self::class, 'ajax_roxy_save_sop' ) );
			add_action( 'wp_ajax_scapi_roxy_agent_tunnel', array( self::class, 'ajax_roxy_agent_tunnel' ) );
			add_action( 'wp_ajax_nopriv_scapi_roxy_agent_tunnel', array( self::class, 'ajax_roxy_agent_tunnel' ) );
		}

		public static function enqueue_assets( string $hook_suffix = '' ): void {
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( false === strpos( $hook_suffix, self::SLUG_HQ ) && false === strpos( $hook_suffix, self::SLUG_SITE ) && false === strpos( $hook_suffix, Subsite_Buyer_UI::SLUG ) && 'pc-buyer-ads' !== $page && 'pc-pixels' !== $page ) {
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
			}
			// 单店/投手后台严格隔离：不注册任何总控菜单（所有配置均由总后台远程一键加密下发）。
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

		private static function format_slot_name_chinese( string $slot_id ): string {
			if ( preg_match( '/^slot_0*(\d+)$/i', trim( $slot_id ), $m ) ) {
				return $m[1] . '号槽';
			}
			return '' !== $slot_id ? $slot_id : '未命名槽';
		}

		private static function format_circuit_chinese( string $circuit ): string {
			switch ( strtoupper( $circuit ) ) {
				case 'CLOSED':
					return '正常连通';
				case 'PAUSED_RATE_LIMIT':
					return '限流暂停';
				case 'HALF_OPEN':
					return '恢复探测';
				case 'CIRCUIT_OPEN_AUTH':
				case 'OPEN':
					return '授权失效·已熔断';
				case 'DISABLED':
					return '已停用下线';
				default:
					return '状态检测中';
			}
		}

		private static function format_category_chinese( string $cat ): string {
			$map = array(
				'DAILY_WELLNESS' => '日常健康养护',
				'VITALITY_CARE'  => '活力机能营养',
				'LONGEVITY_LAB'  => '焕活修护系列',
				'CALM_BALANCE'   => '舒缓平衡护理',
				'REPAIR_COMPLEX' => '密集修护精华',
				'ESSENTIAL_CARE' => '基础温和护理',
			);
			$key = strtoupper( trim( $cat ) );
			return $map[ $key ] ?? '日常健康养护';
		}

		private static function format_notice_chinese( string $code ): string {
			if ( 0 === strpos( $code, 'dict_bulk_imported_' ) ) {
				$cnt = (int) substr( $code, strlen( 'dict_bulk_imported_' ) );
				return '已成功批量导入并热生效 ' . $cnt . ' 条伪装货号映射。';
			}
			if ( 0 === strpos( $code, 'slot_saved_test_' ) ) {
				$tc = substr( $code, strlen( 'slot_saved_test_' ) );
				return '绿盾企业号槽位已保存生效！当前正在启用测试事件码【' . $tc . '】（验收完毕后请点「修改 / 换号」删空该测试码并点保存关闭）。';
			}
			if ( 0 === strpos( $code, 'test_data_cleared_' ) ) {
				$cnt = (int) substr( $code, strlen( 'test_data_cleared_' ) );
				return '已成功清空当前站点 ' . $cnt . ' 条测试回传记录（正式真实成交与客户数据 100% 完整保留，且已打标防核账重推）！';
			}
			$map = array(
				'saved'                            => '配置已成功保存。',
				'slot_promoted'                    => '目标旁路/替补槽位已成功晋升为主归属投流槽位。',
				'cold_lock_active_7d'              => '触发七天冷置保护锁拦截：该槽位静默养号未满七天，禁止普通晋升。',
				'promote_failed'                   => '槽位晋升请求未通过，请检查网关连接状态。',
				'slot_toggled_on'                  => '槽位已成功启用，现已恢复按该槽白名单与独享代理接收并行回传。',
				'slot_toggled_off'                 => '槽位已成功停用（已立即摘除推送且不计入单域名 3 槽位并发上限）。',
				'slot_toggle_failed'               => '切换槽位启用/停用状态失败，请核查单域名活跃槽位上限（≤3）或代理冲突。',
				'active_slots_limit_exceeded'      => '防呆闸门拦截：同一域名下最多同时启用 3 个活跃槽位（is_active=true）！请先停用旧槽位后再启用新槽位。',
				'duplicate_proxy_endpoint_on_domain' => '防连坐闸门拦截：同一域名下的活跃槽位严禁共用相同的代理出口（IP:端口相同即拦截）！请更换独享静态代理。',
				'slot_replaced_and_6_5d_backfilled'=> '像素槽位配置已成功保存并实时热生效（正式回传模式）。',
				'clear_test_data_failed'           => '清空测试数据请求未通过，请检查网关连接状态后重试。',
				'bm_id_required_for_primary_slot'  => '提交提示：BM ID 格式不合法，请留空或填写 6~24 位纯数字编号。',
				'invalid_bm_id_format'             => '提交拦截：【BM ID 备注】格式不正确，若填写请输入 6~24 位纯数字编号（外部户可留空）。',
				'standby_cannot_share_primary_bm_id' => '提交拦截：旁路养模槽的 BM ID 不能与同站主归属槽相同，请保持物理资产隔离。',
				'replace_dead_slot_failed'         => '保存槽位失败，请核对必填的数据集编号（不可与其他槽位重复）、访问令牌及独享代理是否合规。',
				'slot_deleted'                     => '指定槽位已成功删除下线。',
				'slot_delete_failed'               => '删除槽位失败，请检查网关连接状态。',
				'missing_egress_proxy'             => '提交拦截：为严防多个企业号共用同一网关服务器 IP 引发跨号连坐风控，【独享静态住宅代理地址】为必填项！',
				'egress_proxy_required'            => '提交拦截：请填写该绿盾企业号绑定的独享静态住宅代理地址（格式如 http://账号:密码@IP:端口）。',
				'tenant_domain_saved'              => '当前投手的投流主域名已自动同步至网关白名单并实时生效。',
				'tenant_domain_failed'             => '同步投流域名失败，请检查店铺公开域名配置。',
				'store_skus_refreshed'             => '已重新从线上店铺实时抓取最新在售商品与规格列表。',
				'missing_required_bm_slot_fields'  => '提交失败：请完整填写数据集编号与访问令牌。',
				'gateway_reloaded'                 => '网关全局配置已完成热重载并立即生效（后台录入的正式槽位与专属货号完整保留）。',
				'reload_failed'                    => '网关热重载失败，请检查网关服务是否在线。',
				'dict_updated'                     => '单条伪装货号映射已保存并实时生效。',
				'dict_upsert_failed'               => '伪装货号映射保存失败，请检查伪装编号是否误含敏感词或毫克单位。',
				'dict_deleted'                     => '指定伪装货号映射已成功移除。',
				'dict_delete_failed'               => '删除伪装货号映射失败。',
				'dict_cleared'                     => '已清空当前投手名下的全部专属 A 站货号映射（现自动回退使用全局 292 条默认兜底字典）。',
				'dict_clear_failed'                => '清空专属货号映射失败。',
				'config_saved'                     => '本节点连接器与运行模式配置已保存生效。',
				'batch_reconcile_completed'        => '已向网关发起双向差额核账与漏单补推。',
				'local_wc_backfill_done'           => '本店历史已付订单回溯补推已执行完毕。',
				'csv_export_failed'                => '客户名单导出失败，请确认网关内已有高价值成交记录。',
				'hq_push_store_ok'                 => '已通过总后台加密通道将该店铺的通信配置远程下发并立即生效（已自动同步店铺公开域名，仅实时回传新单）。',
				'hq_push_store_failed'             => '远程下发至店铺失败，请确认该店铺已在总后台「插件管理」安装启用本插件且在线。',
				'daily_inspection_done'            => '已完成全网 6+1 项真机安全与健康巡检，最新报告已生成并留档。',
				'daily_webhook_saved'              => '每日巡检告警推送配置已保存生效。',
				'daily_webhook_tested'             => '每日巡检告警配置已保存，并已向您的 Webhook 机器人发送了一条测试播报！',
				'invalid slot replacement'         => '槽位保存失败：请检查数据集编号（仅限数字/字母/下划线）、访问令牌及独享静态住宅代理 URL 格式（例如 http://账号:密码@IP:端口 或 socks5://账号:密码@IP:端口）。',
				'invalid_slot_replacement'         => '槽位保存失败：请检查数据集编号、访问令牌及独享静态住宅代理 URL 格式是否正确。',
				'max_jitter_sec must be >= min_jitter_sec' => '提交失败：热备随机延迟上限（分钟）必须大于或等于延迟下限（分钟）。',
				'maximum 10 slots per store reached'       => '提交拦截：单店最多配置 10 个像素容灾槽位，请直接覆写闲置槽位或先删除旧槽位。',
				'ad tenant not found'              => '提交失败：网关尚未找到当前投手站点记录，请刷新页面自动同步后重试。',
				'gateway_http_422'                 => '网关参数校验未通过（HTTP 422）：请核对数据集编号、访问令牌、代理地址与延迟设置是否合规。',
				'database unavailable'             => '网关数据库写入校验异常或连接繁忙（HTTP 503），请重试或联系技术核查数据库约束配置。',
			);
			if ( isset( $map[ $code ] ) ) {
				return $map[ $code ];
			}
			if ( false !== stripos( $code, 'active_slots_limit_exceeded' ) || false !== stripos( $code, 'maximum 3 active' ) ) {
				return '防呆闸门拦截：同一域名下同时启用的活跃槽位最多允许 3 个！请先停用不再使用的槽位（' . $code . '）。';
			}
			if ( false !== stripos( $code, 'duplicate_proxy_endpoint' ) || false !== stripos( $code, 'proxy endpoint' ) ) {
				return '防连坐闸门拦截：同一域名下任意两个活跃槽位严禁配置相同的代理 IP:端口（' . $code . '）。';
			}
			if ( false !== stripos( $code, 'dataset_id' ) && false !== stripos( $code, 'already' ) ) {
				return '提交拦截：该 Meta 数据集编号已被其他店铺/槽位绑定占用，禁止跨号复用（' . $code . '）。';
			}
			if ( false !== stripos( $code, 'egress_proxy' ) || false !== stripos( $code, 'proxy' ) ) {
				return '代理校验未通过：必须填写合规的独享静态住宅代理 URL（http/https/socks5），详情：' . $code;
			}
			return '' !== $code && 'saved' !== $code
				? '操作提示：' . $code
				: '操作处理结果：已完成请求。';
		}

		// --- 跨服管理接口路由 ---
		public static function register_admin_rest_routes(): void {
			// 供总后台通过 Hq_Connector 加密签名远程下发单店配置（单店物理不存总控管理密钥！）
			register_rest_route(
				'stealth-capi/v1',
				'/hq-push-config',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_hq_push_config' ),
					'permission_callback' => array( self::class, 'check_hq_signed_permission' ),
				)
			);

			// 供总后台双栏 SKU 智能配对台实时拉取单店在售原始商品与规格列表
			register_rest_route(
				'stealth-capi/v1',
				'/hq-store-skus',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_hq_store_skus' ),
					'permission_callback' => array( self::class, 'check_hq_signed_permission' ),
				)
			);

			// 供总后台第 6 页「每日安全与健康巡检」远程拉取单店内部三大安全开关、PHP 钩子与近 24h 核账状态
			register_rest_route(
				'stealth-capi/v1',
				'/hq-store-health',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_hq_store_health' ),
					'permission_callback' => array( self::class, 'check_hq_signed_permission' ),
				)
			);

			$routes = array(
				array( '/admin/status', \WP_REST_Server::READABLE, 'rest_status' ),
				array( '/admin/promote-slot', \WP_REST_Server::CREATABLE, 'rest_promote_slot' ),
				array( '/admin/replace-dead-slot', \WP_REST_Server::CREATABLE, 'rest_replace_dead_slot' ),
				array( '/admin/toggle-slot', \WP_REST_Server::CREATABLE, 'rest_toggle_slot' ),
				array( '/admin/delete-slot', \WP_REST_Server::CREATABLE, 'rest_delete_slot' ),
				array( '/admin/tenant-domains', \WP_REST_Server::CREATABLE, 'rest_tenant_domains' ),
				array( '/admin/reload-config', \WP_REST_Server::CREATABLE, 'rest_reload_config' ),
				array( '/admin/export-seed-csv', \WP_REST_Server::READABLE, 'rest_export_seed_csv' ),
				array( '/admin/dictionary', \WP_REST_Server::READABLE, 'rest_dictionary_get' ),
				array( '/admin/dictionary/upsert', \WP_REST_Server::CREATABLE, 'rest_dictionary_upsert' ),
				array( '/admin/dictionary/bulk-upsert', \WP_REST_Server::CREATABLE, 'rest_dictionary_bulk_upsert' ),
				array( '/admin/dictionary/delete', \WP_REST_Server::CREATABLE, 'rest_dictionary_delete' ),
				array( '/admin/dictionary/clear', \WP_REST_Server::CREATABLE, 'rest_dictionary_clear' ),
				array( '/admin/reconcile', \WP_REST_Server::CREATABLE, 'rest_reconcile' ),
				array( '/admin/backfill-wc', \WP_REST_Server::CREATABLE, 'rest_backfill_wc' ),
				array( '/admin/debug-log-toggle', \WP_REST_Server::CREATABLE, 'rest_debug_log_toggle' ),
				array( '/admin/outbound-debug-logs', \WP_REST_Server::READABLE, 'rest_outbound_debug_logs' ),
				array( '/admin/clear-test-data', \WP_REST_Server::CREATABLE, 'rest_clear_test_data' ),
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

		public static function check_hq_signed_permission( \WP_REST_Request $request ) {
			if ( class_exists( '\PeptideCore\Hq_Connector' ) && method_exists( '\PeptideCore\Hq_Connector', 'verify' ) ) {
				return \PeptideCore\Hq_Connector::verify( $request );
			}
			return self::check_admin_permission( $request );
		}

		public static function rest_hq_push_config( \WP_REST_Request $req ): \WP_REST_Response {
			$p           = $req->get_json_params() ?: array();
			$gateway_url = isset( $p['gateway_base_url'] ) ? esc_url_raw( trim( (string) $p['gateway_base_url'] ) ) : '';
			$t_site_key  = isset( $p['site_key'] ) ? sanitize_text_field( (string) $p['site_key'] ) : '';
			$t_secret    = isset( $p['api_secret'] ) ? trim( sanitize_text_field( (string) $p['api_secret'] ) ) : '';
			$t_store     = isset( $p['store_code'] ) ? sanitize_text_field( (string) $p['store_code'] ) : '';
			$t_mode      = isset( $p['tenant_mode'] ) ? sanitize_text_field( (string) $p['tenant_mode'] ) : Config_Manager::MODE_AD_ACQUISITION;
			$is_hub      = ( Config_Manager::MODE_ORGANIC_RETENTION_HUB === $t_mode );

			if ( '' === $gateway_url || '' === $t_site_key || '' === $t_secret ) {
				return new \WP_REST_Response( array( 'ok' => false, 'error' => 'missing_fields' ), 400 );
			}

			// 注意：严禁把 admin_master_key 写入单店数据库，确保单店物理零总控权限！
			Config_Manager::save_config(
				array(
					'enabled'               => ! isset( $p['enabled'] ) || ! empty( $p['enabled'] ),
					'gateway_base_url'      => $gateway_url,
					'admin_master_key'      => '',
					'suppress_legacy_meta'  => true,
					'auto_enqueue_sdk'      => ! $is_hub,
					'enable_server_shallow' => ! $is_hub,
					'trust_private_proxies' => true,
					'default_tenant'        => array(
						'enabled'               => true,
						'blog_id'               => 1,
						'site_key'              => $t_site_key,
						'api_secret'            => $t_secret,
						'store_code'            => $t_store,
						'tenant_mode'           => $t_mode,
						'is_repurchase_station' => $is_hub,
					),
				)
			);

			$backfill_stats = array();
			if ( ! empty( $p['trigger_local_backfill'] ) ) {
				$backfill_stats = Reconcile_Cron::backfill_historical_paid_orders( 7 );
			}

			return new \WP_REST_Response(
				array(
					'ok'             => true,
					'site_key'       => $t_site_key,
					'tenant_mode'    => $t_mode,
					'backfill_stats' => $backfill_stats,
				),
				200
			);
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
				! empty( $p['force_override'] ),
				(string) ( $p['bm_id'] ?? '' )
			);
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_replace_dead_slot( \WP_REST_Request $req ): \WP_REST_Response {
			$res = \StealthCapiBridge::replace_dead_slot( $req->get_json_params() ?: array() );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_toggle_slot( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::toggle_slot(
				(string) ( $p['site_key'] ?? '' ),
				(string) ( $p['slot_id'] ?? '' ),
				! empty( $p['is_active'] )
			);
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_delete_slot( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::delete_slot(
				(string) ( $p['site_key'] ?? '' ),
				(string) ( $p['slot_id'] ?? '' )
			);
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_tenant_domains( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::update_tenant_domains(
				(string) ( $p['site_key'] ?? '' ),
				(string) ( $p['primary_domain'] ?? '' ),
				is_array( $p['extra_domains'] ?? null ) ? $p['extra_domains'] : array()
			);
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
			$sk   = isset( $_GET['site_key'] ) ? sanitize_text_field( wp_unslash( $_GET['site_key'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$only = ! empty( $_GET['only_site'] ) && 'false' !== strtolower( sanitize_text_field( wp_unslash( $_GET['only_site'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$res  = \StealthCapiBridge::fetch_sanitizer_dictionary( $sk ?: null, $only );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_hq_store_skus(): \WP_REST_Response {
			return new \WP_REST_Response(
				array(
					'ok'    => true,
					'items' => self::collect_local_wc_products(),
				),
				200
			);
		}

		public static function rest_hq_store_health(): \WP_REST_Response {
			return new \WP_REST_Response( Daily_Inspector::collect_local_store_telemetry(), 200 );
		}

		public static function rest_debug_log_toggle( \WP_REST_Request $req ): \WP_REST_Response {
			$p       = $req->get_json_params() ?: array();
			$enabled = ! empty( $p['enabled'] );
			$res     = Config_Manager::gateway_admin_request( 'POST', '/v3/admin/debug-log-toggle', array( 'enabled' => $enabled ), 5.0 );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_outbound_debug_logs(): \WP_REST_Response {
			$args = array();
			if ( ! empty( $_GET['site_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args['site_key'] = sanitize_text_field( wp_unslash( $_GET['site_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( ! empty( $_GET['event_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args['event_name'] = sanitize_text_field( wp_unslash( $_GET['event_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$args['limit'] = isset( $_GET['limit'] ) ? max( 1, min( 200, (int) $_GET['limit'] ) ) : 20; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$path          = '/v3/admin/outbound-debug-logs?' . http_build_query( $args );
			$res           = Config_Manager::gateway_admin_request( 'GET', $path, null, 6.0 );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_clear_test_data( \WP_REST_Request $req ): \WP_REST_Response {
			$p      = $req->get_json_params() ?: array();
			$sk     = isset( $p['site_key'] ) ? sanitize_text_field( (string) $p['site_key'] ) : '';
			$clearc = ! empty( $p['also_clear_test_code'] );
			$res    = \StealthCapiBridge::clear_test_data( $sk, $clearc );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		private static function collect_local_wc_products(): array {
			if ( ! function_exists( 'wc_get_products' ) ) {
				return array();
			}
			$prods = wc_get_products(
				array(
					'limit'   => -1,
					'status'  => 'publish',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$items = array();
			foreach ( $prods as $p ) {
				if ( ! $p instanceof \WC_Product ) {
					continue;
				}
				if ( $p->is_type( 'variable' ) ) {
					foreach ( $p->get_children() as $cid ) {
						$v = wc_get_product( $cid );
						if ( ! $v instanceof \WC_Product ) {
							continue;
						}
						$attrs = array();
						foreach ( (array) $v->get_attributes() as $ak => $av ) {
							if ( '' !== (string) $av ) {
								$attrs[] = (string) $av;
							}
						}
						$raw_sku = trim( (string) $v->get_sku() );
						$items[] = array(
							'pid'        => (int) $p->get_id(),
							'vid'        => (int) $v->get_id(),
							'spu_name'   => (string) $p->get_name(),
							'spec_label' => implode( ' / ', $attrs ),
							'raw_sku'    => '' !== $raw_sku ? $raw_sku : ( 'WC_' . $v->get_id() ),
							'price'      => round( (float) $v->get_price(), 2 ),
						);
					}
				} else {
					$raw_sku = trim( (string) $p->get_sku() );
					$items[] = array(
						'pid'        => (int) $p->get_id(),
						'vid'        => (int) $p->get_id(),
						'spu_name'   => (string) $p->get_name(),
						'spec_label' => '单规格',
						'raw_sku'    => '' !== $raw_sku ? $raw_sku : ( 'WC_' . $p->get_id() ),
						'price'      => round( (float) $p->get_price(), 2 ),
					);
				}
			}
			return $items;
		}

		private static function resolve_hq_store_record( string $site_key ): ?array {
			if ( ! class_exists( '\PeptideHq\Sites' ) ) {
				return null;
			}
			$catalog    = Config_Manager::get_stores_catalog();
			$store_code = (string) ( $catalog[ $site_key ]['store_code'] ?? '' );
			$hq_sites   = \PeptideHq\Sites::all();
			foreach ( $hq_sites as $sid => $srow ) {
				$m_url = (string) ( $srow['management_url'] ?? '' );
				if ( '' !== $store_code && false !== strpos( $m_url, $store_code ) ) {
					$srow['_hq_site_id'] = (int) $sid;
					return $srow;
				}
			}
			return null;
		}

		private static function auto_sync_buyer_domain( string $site_key ): string {
			$srow = self::resolve_hq_store_record( $site_key );
			if ( ! $srow || empty( $srow['public_url'] ) ) {
				return '';
			}
			$host = strtolower( trim( (string) wp_parse_url( (string) $srow['public_url'], PHP_URL_HOST ) ) );
			if ( '' === $host ) {
				return '';
			}
			$synced = get_option( 'stealth_capi_auto_synced_domains', array() );
			if ( ! is_array( $synced ) ) {
				$synced = array();
			}
			if ( ( $synced[ $site_key ] ?? '' ) !== $host ) {
				$res = \StealthCapiBridge::update_tenant_domains( $site_key, $host );
				if ( ! empty( $res['ok'] ) ) {
					$synced[ $site_key ] = $host;
					update_option( 'stealth_capi_auto_synced_domains', $synced, false );
				}
			}
			return $host;
		}

		private static function fetch_store_products_for_buyer( string $site_key, bool $force_refresh = false ): array {
			$clean_sk  = preg_replace( '/[^a-z0-9_-]/i', '', $site_key );
			$cache_key = 'scapi_skus_' . strtolower( (string) $clean_sk );
			if ( ! $force_refresh && function_exists( 'get_transient' ) ) {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) && ! empty( $cached ) ) {
					return $cached;
				}
			}
			$srow = self::resolve_hq_store_record( $site_key );
			if ( $srow && ! empty( $srow['_hq_site_id'] ) && class_exists( '\PeptideHq\Client' ) ) {
				$res = \PeptideHq\Client::request( (int) $srow['_hq_site_id'], 'GET', '/stealth-capi/v1/hq-store-skus', array(), null, 15 );
				if ( ! is_wp_error( $res ) && ! empty( $res['items'] ) && is_array( $res['items'] ) ) {
					if ( function_exists( 'set_transient' ) ) {
						set_transient( $cache_key, $res['items'], 180 );
					}
					return $res['items'];
				}
			}
			$local = self::collect_local_wc_products();
			if ( ! empty( $local ) && function_exists( 'set_transient' ) ) {
				set_transient( $cache_key, $local, 180 );
			}
			return $local;
		}

		public static function rest_dictionary_upsert( \WP_REST_Request $req ): \WP_REST_Response {
			$res = \StealthCapiBridge::upsert_dictionary_entry( $req->get_json_params() ?: array() );
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_dictionary_bulk_upsert( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::bulk_upsert_dictionary_entries(
				(string) ( $p['site_key'] ?? '__GLOBAL__' ),
				is_array( $p['entries'] ?? null ) ? $p['entries'] : array(),
				! isset( $p['replace_site'] ) || ! empty( $p['replace_site'] )
			);
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_dictionary_delete( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::delete_dictionary_entry(
				(string) ( $p['raw_sku'] ?? '' ),
				(string) ( $p['site_key'] ?? '__GLOBAL__' )
			);
			return new \WP_REST_Response( $res, self::http_code_from_res( $res ) );
		}

		public static function rest_dictionary_clear( \WP_REST_Request $req ): \WP_REST_Response {
			$p   = $req->get_json_params() ?: array();
			$res = \StealthCapiBridge::clear_dictionary_entries( (string) ( $p['site_key'] ?? '' ) );
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

		// --- 后台表单提交处理 ---
		public static function handle_post_action(): void {
			$cap = ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_network_admin' ) && is_network_admin() )
				? 'manage_network_options'
				: 'manage_options';

			if ( ! current_user_can( $cap ) ) {
				wp_die( '无权访问此操作', 403 );
			}
			check_admin_referer( 'pchq_capi_action_nonce' );

			$sub_action = isset( $_POST['capi_sub_action'] ) ? sanitize_text_field( wp_unslash( $_POST['capi_sub_action'] ) ) : '';
			$tab        = isset( $_POST['return_tab'] ) ? sanitize_text_field( wp_unslash( $_POST['return_tab'] ) ) : 'slots';
			$site_sel   = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : 'site_01';

			if ( 'download_roxy_bridge' === $sub_action ) {
				$py = self::build_roxy_local_bridge_script();
				nocache_headers();
				header( 'Content-Type: text/x-python; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="roxy_local_bridge.py"' );
				echo $py; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}

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
					$bm_id_p = isset( $_POST['bm_id'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['bm_id'] ) ) ) : '';
					$res     = \StealthCapiBridge::promote_slot( $site_sel, $slot_id, $force, $bm_id_p );
					if ( empty( $res['ok'] ) ) {
						$msg_type = 'err';
						$msg_code = ( 409 === (int) ( $res['status'] ?? 0 ) ) ? 'cold_lock_active_7d' : ( $res['error'] ?: 'promote_failed' );
					} else {
						$msg_code = 'slot_promoted';
					}
					break;

				case 'toggle_slot':
					$slot_id   = isset( $_POST['slot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_id'] ) ) : '';
					$is_active = ! empty( $_POST['is_active'] ) && '1' === (string) $_POST['is_active'];
					$res       = \StealthCapiBridge::toggle_slot( $site_sel, $slot_id, $is_active );
					if ( empty( $res['ok'] ) ) {
						$msg_type = 'err';
						$msg_code = $res['error'] ?: 'slot_toggle_failed';
					} else {
						$msg_code = $is_active ? 'slot_toggled_on' : 'slot_toggled_off';
					}
					break;

				case 'replace_dead_slot':
					$is_edit_ex = ! empty( $_POST['is_edit_existing'] ) && '1' === (string) $_POST['is_edit_existing'];
					$raw_proxy  = isset( $_POST['egress_proxy'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['egress_proxy'] ) ) ) : '';
					if ( '' === $raw_proxy && ! $is_edit_ex ) {
						$msg_type = 'err';
						$msg_code = 'missing_egress_proxy';
						break;
					}
					$role_in   = isset( $_POST['slot_role'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['slot_role'] ) ) ) ) : 'shadow';
					$is_shadow = in_array( $role_in, array( 'shadow', 'standby' ), true );
					$d_min_sec = isset( $_POST['delay_min_sec'] )
						? max( 0, (int) $_POST['delay_min_sec'] )
						: ( isset( $_POST['min_jitter_min'] ) ? max( 0, (int) $_POST['min_jitter_min'] ) * 60 : ( $is_shadow ? 15 : 0 ) );
					$d_max_sec = isset( $_POST['delay_max_sec'] )
						? max( $d_min_sec, (int) $_POST['delay_max_sec'] )
						: ( isset( $_POST['max_jitter_min'] ) ? max( $d_min_sec, (int) $_POST['max_jitter_min'] * 60 ) : ( $is_shadow ? 60 : 0 ) );
					$raw_evs   = isset( $_POST['enabled_events'] ) && is_array( $_POST['enabled_events'] )
						? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_events'] ) )
						: array();
					$strip_fbc = isset( $_POST['strip_fbc_present'] )
						? ( ! empty( $_POST['strip_fbc'] ) && '1' === (string) $_POST['strip_fbc'] )
						: $is_shadow;
					$is_active = isset( $_POST['is_active_present'] )
						? ( ! empty( $_POST['is_active'] ) && '1' === (string) $_POST['is_active'] )
						: true;
					$auto_dom  = self::auto_sync_buyer_domain( $site_sel );
					$ev_dom_in = isset( $_POST['event_domain'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['event_domain'] ) ) ) ) : '';
					$submitted_tcode = isset( $_POST['test_event_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['test_event_code'] ) ) ) : '';
					$submitted_bmid  = isset( $_POST['bm_id'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['bm_id'] ) ) ) : '';
					$res       = \StealthCapiBridge::replace_dead_slot(
						array(
							'site_key'              => $site_sel,
							'slot_id'               => isset( $_POST['slot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_id'] ) ) : 'slot_01',
							'slot_role'             => $is_shadow ? 'shadow' : 'primary',
							'bm_id'                 => $submitted_bmid,
							'bm_label'              => isset( $_POST['bm_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bm_label'] ) ) : '',
							'dataset_id'            => isset( $_POST['dataset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dataset_id'] ) ) : '',
							'access_token'          => isset( $_POST['access_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) ) : '',
							'egress_proxy'          => $raw_proxy,
							'event_domain'          => '' !== $ev_dom_in ? $ev_dom_in : $auto_dom,
							'test_event_code'       => $submitted_tcode,
							'clear_test_event_code' => ( $is_edit_ex && '' === $submitted_tcode ),
							'enabled_events'        => $raw_evs,
							'strip_fbc'             => $strip_fbc,
							'delay_min_sec'         => $d_min_sec,
							'delay_max_sec'         => $d_max_sec,
							'is_active'             => $is_active,
							'trigger_backfill'      => ! empty( $_POST['trigger_backfill'] ),
							'backfill_hours'        => 156,
							'is_edit_existing'      => $is_edit_ex,
						)
					);
					if ( empty( $res['ok'] ) ) {
						$msg_type = 'err';
						$msg_code = $res['error'] ?: 'replace_dead_slot_failed';
					} else {
						$msg_code = '' !== $submitted_tcode ? ( 'slot_saved_test_' . $submitted_tcode ) : 'slot_replaced_and_6_5d_backfilled';
					}
					break;

				case 'clear_test_data':
					$clear_tcode = ! empty( $_POST['also_clear_test_code'] ) && '1' === (string) $_POST['also_clear_test_code'];
					$res         = \StealthCapiBridge::clear_test_data( $site_sel, $clear_tcode );
					if ( empty( $res['ok'] ) ) {
						$msg_type = 'err';
						$msg_code = $res['error'] ?: 'clear_test_data_failed';
					} else {
						$purged_cnt = max(
							(int) ( $res['data']['purged_actions'] ?? 0 ),
							(int) ( $res['data']['purged_deliveries'] ?? 0 ),
							(int) ( $res['data']['purged_debug_logs'] ?? 0 )
						);
						$msg_code = 'test_data_cleared_' . $purged_cnt;
					}
					break;

				case 'delete_slot':
					$slot_id  = isset( $_POST['slot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_id'] ) ) : '';
					$res      = \StealthCapiBridge::delete_slot( $site_sel, $slot_id );
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'slot_deleted' : ( $res['error'] ?: 'slot_delete_failed' );
					break;

				case 'save_tenant_domain':
					$p_domain = isset( $_POST['primary_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_domain'] ) ) : '';
					$res      = \StealthCapiBridge::update_tenant_domains( $site_sel, $p_domain );
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'tenant_domain_saved' : 'tenant_domain_failed';
					break;

				case 'reload_gateway':
					$res      = \StealthCapiBridge::reload_gateway_config();
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'gateway_reloaded' : ( $res['error'] ?: 'reload_failed' );
					break;

				case 'dict_upsert':
					$dict_sk = isset( $_POST['dict_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['dict_scope'] ) ) : '__GLOBAL__';
					$site_sel = ( '__GLOBAL__' !== $dict_sk ) ? $dict_sk : $site_sel;
					$res = \StealthCapiBridge::upsert_dictionary_entry(
						array(
							'site_key'        => $dict_sk,
							'raw_sku'         => isset( $_POST['raw_sku'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['raw_sku'] ) ) ) : '',
							'safe_catalog_id' => isset( $_POST['safe_catalog_id'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['safe_catalog_id'] ) ) ) : '',
							'category_tag'    => isset( $_POST['category_tag'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['category_tag'] ) ) ) : 'DAILY_WELLNESS',
						)
					);
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'dict_updated' : 'dict_upsert_failed';
					self::redirect_back( 'dictionary', $site_sel, $msg_type, $msg_code, $dict_sk );
					break;

				case 'dict_bulk_import':
					$dict_sk   = isset( $_POST['dict_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['dict_scope'] ) ) : '__GLOBAL__';
					$site_sel  = ( '__GLOBAL__' !== $dict_sk ) ? $dict_sk : $site_sel;
					$lines_raw = isset( $_POST['bulk_lines'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['bulk_lines'] ) ) ) : '';
					$imported  = 0;
					if ( '' !== $lines_raw ) {
						foreach ( preg_split( '/\r\n|\r|\n/', $lines_raw ) as $line ) {
							$parts = array_map( 'trim', explode( ',', str_replace( array( '，', "\t" ), ',', $line ) ) );
							if ( count( $parts ) >= 2 && '' !== $parts[0] && '' !== $parts[1] && 'RAW_SKU' !== strtoupper( $parts[0] ) && '原货号' !== $parts[0] ) {
								$r = \StealthCapiBridge::upsert_dictionary_entry(
									array(
										'site_key'        => $dict_sk,
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
					self::redirect_back( 'dictionary', $site_sel, 'ok', $msg_code, $dict_sk );
					break;

				case 'dict_delete':
					$dict_sk  = isset( $_POST['dict_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['dict_scope'] ) ) : '__GLOBAL__';
					$site_sel = ( '__GLOBAL__' !== $dict_sk ) ? $dict_sk : $site_sel;
					$raw_sku  = isset( $_POST['raw_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['raw_sku'] ) ) : '';
					$res      = \StealthCapiBridge::delete_dictionary_entry( $raw_sku, $dict_sk );
					$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
					$msg_code = ! empty( $res['ok'] ) ? 'dict_deleted' : 'dict_delete_failed';
					self::redirect_back( 'dictionary', $site_sel, $msg_type, $msg_code, $dict_sk );
					break;

				case 'dict_bulk_json':
					$dict_sk   = isset( $_POST['dict_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['dict_scope'] ) ) : 'site_01';
					$site_sel  = ( '__GLOBAL__' !== $dict_sk ) ? $dict_sk : $site_sel;
					$raw_json  = isset( $_POST['entries_json'] ) ? wp_unslash( $_POST['entries_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$entries   = json_decode( (string) $raw_json, true );
					if ( is_array( $entries ) && ! empty( $entries ) ) {
						$res      = \StealthCapiBridge::bulk_upsert_dictionary_entries( $dict_sk, $entries, true );
						$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
						$cnt      = (int) ( $res['data']['imported_count'] ?? count( $entries ) );
						$msg_code = ! empty( $res['ok'] ) ? ( 'dict_bulk_imported_' . $cnt ) : 'dict_upsert_failed';
					} else {
						$msg_type = 'err';
						$msg_code = 'dict_upsert_failed';
					}
					self::redirect_back( 'dictionary', $site_sel, $msg_type, $msg_code, $dict_sk );
					break;

				case 'refresh_store_skus':
					$dict_sk  = isset( $_POST['dict_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['dict_scope'] ) ) : 'site_01';
					$site_sel = ( '__GLOBAL__' !== $dict_sk ) ? $dict_sk : $site_sel;
					self::fetch_store_products_for_buyer( $dict_sk, true );
					self::redirect_back( 'dictionary', $site_sel, 'ok', 'store_skus_refreshed', $dict_sk );
					break;

				case 'dict_clear_site':
					$dict_sk  = isset( $_POST['dict_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['dict_scope'] ) ) : '';
					$site_sel = ( '' !== $dict_sk && '__GLOBAL__' !== $dict_sk ) ? $dict_sk : $site_sel;
					if ( '' !== $dict_sk && '__GLOBAL__' !== $dict_sk ) {
						$res      = \StealthCapiBridge::clear_dictionary_entries( $dict_sk );
						$msg_type = ! empty( $res['ok'] ) ? 'ok' : 'err';
						$msg_code = ! empty( $res['ok'] ) ? 'dict_cleared' : 'dict_clear_failed';
					} else {
						$msg_type = 'err';
						$msg_code = 'dict_clear_failed';
					}
					self::redirect_back( 'dictionary', $site_sel, $msg_type, $msg_code, $dict_sk ?: 'site_01' );
					break;

				case 'save_plugin_config':
					$gateway_url      = isset( $_POST['gateway_base_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['gateway_base_url'] ) ) ) : 'http://127.0.0.1:8080';
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
							'gateway_base_url'      => $gateway_url ?: 'http://127.0.0.1:8080',
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

				case 'hq_push_to_store':
					$hq_site_id  = isset( $_POST['hq_target_site_id'] ) ? absint( $_POST['hq_target_site_id'] ) : 0;
					$bind_sk     = isset( $_POST['bind_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bind_site_key'] ) ) : 'site_01';
					$bind_secret = isset( $_POST['bind_api_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bind_api_secret'] ) ) ) : '';
					$enable_flag = ! isset( $_POST['store_enable_flag'] ) || '1' === (string) $_POST['store_enable_flag'];
					$do_bf       = $enable_flag && ! empty( $_POST['trigger_local_backfill'] );
					$cfg_now     = Config_Manager::get_config();
					$gw_url_now  = trim( (string) ( $cfg_now['gateway_base_url'] ?? '' ) );
					$cat_all     = Config_Manager::get_stores_catalog();
					$store_code  = (string) ( $cat_all[ $bind_sk ]['store_code'] ?? '' );
					$t_mode      = ( 'repurchase_hub_02' === $bind_sk ) ? Config_Manager::MODE_ORGANIC_RETENTION_HUB : Config_Manager::MODE_AD_ACQUISITION;

					if ( '' === $bind_secret ) {
						$stored_secrets = get_option( 'stealth_capi_hq_site_secrets', array() );
						if ( is_array( $stored_secrets ) && ! empty( $stored_secrets[ $bind_sk ] ) ) {
							$bind_secret = (string) $stored_secrets[ $bind_sk ];
						}
					}

					if ( $hq_site_id > 0 && '' !== $bind_secret && '' !== $gw_url_now && class_exists( '\PeptideHq\Client' ) ) {
						// 若该线上店铺在总后台配置了公开域名，下发时自动同步写入网关白名单，确保切流零 422 拦截！
						if ( class_exists( '\PeptideHq\Sites' ) ) {
							$all_hq_sites = \PeptideHq\Sites::all();
							$pub_url      = (string) ( $all_hq_sites[ $hq_site_id ]['public_url'] ?? '' );
							$pub_host     = (string) wp_parse_url( $pub_url, PHP_URL_HOST );
							if ( '' !== $pub_host ) {
								\StealthCapiBridge::update_tenant_domains( $bind_sk, $pub_host );
							}
						}

						$push_res = \PeptideHq\Client::request(
							$hq_site_id,
							'POST',
							'/stealth-capi/v1/hq-push-config',
							array(),
							array(
								'enabled'                => $enable_flag,
								'gateway_base_url'       => $gw_url_now,
								'site_key'               => $bind_sk,
								'api_secret'             => $bind_secret,
								'store_code'             => $store_code,
								'tenant_mode'            => $t_mode,
								'trigger_local_backfill' => $do_bf,
							),
							25
						);
						if ( ! is_wp_error( $push_res ) && ! empty( $push_res['ok'] ) ) {
							$saved_map = get_option( 'stealth_capi_hq_bound_stores', array() );
							if ( ! is_array( $saved_map ) ) {
								$saved_map = array();
							}
							$saved_map[ $hq_site_id ] = array(
								'site_key' => $bind_sk,
								'enabled'  => $enable_flag,
								'bound_at' => time(),
							);
							update_option( 'stealth_capi_hq_bound_stores', $saved_map, false );
							$msg_code = 'hq_push_store_ok';
						} else {
							$msg_type = 'err';
							$msg_code = 'hq_push_store_failed';
						}
					} else {
						$msg_type = 'err';
						$msg_code = 'hq_push_store_failed';
					}
					break;

				case 'run_daily_inspection':
					$rep = Daily_Inspector::run_full_inspection( 'manual' );
					Daily_Inspector::maybe_send_webhook( $rep, false );
					$msg_code = 'daily_inspection_done';
					break;

				case 'save_daily_webhook':
					$wh_url   = isset( $_POST['webhook_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['webhook_url'] ) ) ) : '';
					$wh_alert = ! empty( $_POST['only_on_alert'] );
					Daily_Inspector::save_webhook_config( $wh_url, $wh_alert );
					if ( ! empty( $_POST['send_test_push'] ) && '' !== $wh_url ) {
						$hist = Daily_Inspector::get_reports_history();
						$last = ! empty( $hist[0] ) ? $hist[0] : Daily_Inspector::run_full_inspection( 'manual_test' );
						Daily_Inspector::maybe_send_webhook( $last, true );
						$msg_code = 'daily_webhook_tested';
					} else {
						$msg_code = 'daily_webhook_saved';
					}
					break;
			}

			$ret_view = isset( $_POST['return_site_view'] ) ? sanitize_text_field( wp_unslash( $_POST['return_site_view'] ) ) : '';
			self::redirect_back( $tab, ( 'ALL' === $ret_view ? 'ALL' : $site_sel ), $msg_type, $msg_code );
		}

		private static function redirect_back( string $tab, string $site_key, string $status, string $msg, string $dict_scope = '' ): void {
			$is_net = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_network_admin' ) && is_network_admin();
			$has_hq = class_exists( '\PeptideHq\Admin\Dashboard_Page' ) || isset( $GLOBALS['submenu']['pchq'] );
			if ( $is_net ) {
				$base_url = network_admin_url( 'settings.php?page=' . self::SLUG_SITE );
			} elseif ( $has_hq ) {
				$base_url = admin_url( 'admin.php?page=' . self::SLUG_HQ );
			} else {
				$base_url = admin_url( 'options-general.php?page=' . self::SLUG_SITE );
			}
			$args = array(
				'tab'       => $tab,
				'site_view' => $site_key,
				'capi_st'   => $status,
				'capi_msg'  => $msg,
			);
			if ( '' !== $dict_scope ) {
				$args['dict_scope'] = $dict_scope;
			}
			$url = add_query_arg( $args, $base_url );
			wp_safe_redirect( $url );
			exit;
		}

		// --- 渲染四大核心管理分页 ---
		public static function render(): void {
			$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'slots'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$site_view  = isset( $_GET['site_view'] ) ? sanitize_text_field( wp_unslash( $_GET['site_view'] ) ) : 'site_03'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$dict_scope = isset( $_GET['dict_scope'] ) ? sanitize_text_field( wp_unslash( $_GET['dict_scope'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$capi_st    = isset( $_GET['capi_st'] ) ? sanitize_text_field( wp_unslash( $_GET['capi_st'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$capi_msg   = isset( $_GET['capi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['capi_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$catalog    = Config_Manager::get_stores_catalog();
			if ( 'ALL' !== $site_view && ! isset( $catalog[ $site_view ] ) ) {
				$site_view = 'site_03';
			}
			if ( '' === $dict_scope || ( '__GLOBAL__' !== $dict_scope && ! isset( $catalog[ $dict_scope ] ) ) ) {
				$dict_scope = ( 'ALL' !== $site_view && 'repurchase_hub_02' !== $site_view ) ? $site_view : 'site_03';
			}

			$page_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : self::SLUG_SITE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$base_page = admin_url( ( self::SLUG_HQ === $page_slug ? 'admin.php' : 'options-general.php' ) . '?page=' . rawurlencode( $page_slug ) );
			?>
			<div class="wrap scapi-console">
				<h1>全局像素容灾与战区资产中心 <span style="font-size:13px;font-weight:normal;color:#64748b;">（正式版 v3.9.3 · 全漏斗 6 大事件高匹配回传 · 单域名多槽位并行 · 主号 + 辅跑 + 旁路养模）</span></h1>
				<hr class="wp-header-end" />

				<?php if ( '' !== $capi_msg ) : ?>
					<div class="notice inline scapi-diag-box <?php echo 'err' === $capi_st ? 'err notice-error' : 'ok notice-success'; ?>" style="margin:12px 0 16px;padding:12px 16px;font-size:14px;border-left-width:4px;">
						<strong><?php echo 'err' === $capi_st ? '❌ 拦截与异常提示：' : '✅ 执行完成：'; ?></strong> <?php echo esc_html( self::format_notice_chinese( $capi_msg ) ); ?>
					</div>
				<?php endif; ?>

				<nav class="scapi-tabs">
					<a class="scapi-tab <?php echo 'slots' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'slots', 'site_view' => $site_view ), $base_page ) ); ?>">1. 域名多槽位并行大盘（主归属 + 旁路养模）</a>
					<a class="scapi-tab <?php echo 'dictionary' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'dictionary', 'dict_scope' => $dict_scope ), $base_page ) ); ?>">2. 双栏拖拽智能 SKU 配对台</a>
					<a class="scapi-tab <?php echo 'audience' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'audience', $base_page ) ); ?>">3. 高价值经销商防关联导出</a>
					<a class="scapi-tab <?php echo 'settings' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_page ) ); ?>">4. 六店一键下发与双向核账</a>
					<a class="scapi-tab <?php echo 'roxy' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'roxy', $base_page ) ); ?>">5. Roxy 战区编队作战台（主·保·辅跑·热备）</a>
					<a class="scapi-tab <?php echo 'daily_check' === $active_tab ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'daily_check', $base_page ) ); ?>">6. 每日安全与健康巡检</a>
				</nav>

				<?php
				if ( 'dictionary' === $active_tab ) {
					self::render_tab_dictionary( $dict_scope, $catalog, $base_page );
				} elseif ( 'audience' === $active_tab ) {
					self::render_tab_audience( $catalog );
				} elseif ( 'settings' === $active_tab ) {
					self::render_tab_settings();
				} elseif ( 'roxy' === $active_tab ) {
					self::render_tab_roxy( $catalog );
				} elseif ( 'daily_check' === $active_tab ) {
					self::render_tab_daily_check( $base_page );
				} else {
					self::render_tab_slots( $site_view, $catalog, $base_page );
				}
				?>
			</div>
			<?php
		}

		private static function render_tab_slots( string $site_view, array $catalog, string $base_page ): void {
			$is_all_view = ( 'ALL' === $site_view );
			$default_sk  = $is_all_view ? 'site_03' : $site_view;
			$auto_host   = ! $is_all_view ? self::auto_sync_buyer_domain( $site_view ) : '';
			$overview    = \StealthCapiBridge::fetch_network_slots_status( $is_all_view ? null : $site_view );
			$gw_slots    = $overview['gateway_slots'] ?? array();
			$sites_map   = $gw_slots['sites'] ?? array();
			$domains_gw  = isset( $gw_slots['domains'] ) && is_array( $gw_slots['domains'] ) ? $gw_slots['domains'] : array();
			$max_active  = (int) ( $gw_slots['max_active_slots_per_domain'] ?? 3 );

			$site_info      = $sites_map[ $default_sk ] ?? array();
			$cur_site_label = $is_all_view ? '全网多域名总览' : ( $catalog[ $site_view ]['label'] ?? '当前站点' );
			$primary_domain = '' !== $auto_host ? $auto_host : (string) ( $site_info['primary_domain'] ?? '' );
			$excl_sku_cnt   = (int) ( $site_info['exclusive_sku_count'] ?? 0 );

			$flat_slots = array();
			if ( ! empty( $gw_slots['slots'] ) && is_array( $gw_slots['slots'] ) ) {
				foreach ( $gw_slots['slots'] as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$it_sk = (string) ( $item['site_key'] ?? $default_sk );
					if ( ! $is_all_view && $it_sk !== $site_view ) {
						continue;
					}
					$item['site_key'] = $it_sk;
					$flat_slots[]     = $item;
				}
			} elseif ( ! empty( $site_info['slots'] ) && is_array( $site_info['slots'] ) ) {
				foreach ( $site_info['slots'] as $item ) {
					if ( is_array( $item ) ) {
						$item['site_key'] = $default_sk;
						$flat_slots[]     = $item;
					}
				}
			}

			// 按「域名」分组聚合（兼容多槽位并行与全网总览）
			$domain_groups     = array();
			$used_ids          = array();
			$has_prim          = false;
			$active_test_slots = array();
			$primary_tcode     = '';

			foreach ( $flat_slots as $it ) {
				$it_sk      = (string) ( $it['site_key'] ?? $default_sk );
				$sid_it     = (string) ( $it['slot_id'] ?? '' );
				$it_sinfo   = $sites_map[ $it_sk ] ?? array();
				$it_dom_raw = strtolower( trim( (string) ( $it['event_domain'] ?? $it_sinfo['primary_domain'] ?? $primary_domain ) ) );
				$dom_key    = (string) ( $it['canonical_domain'] ?? preg_replace( '/^www\./i', '', $it_dom_raw ) );
				if ( '' === $dom_key ) {
					$dom_key = $it_sk . ' (待绑域名)';
				}
				if ( ! isset( $domain_groups[ $dom_key ] ) ) {
					$domain_groups[ $dom_key ] = array(
						'domain_display' => '' !== $it_dom_raw ? $it_dom_raw : $dom_key,
						'site_key'       => $it_sk,
						'site_label'     => $catalog[ $it_sk ]['label'] ?? $it_sk,
						'active_count'   => 0,
						'primary_count'  => 0,
						'shadow_count'   => 0,
						'slots'          => array(),
					);
				}
				$domain_groups[ $dom_key ]['slots'][] = $it;

				if ( $it_sk === $default_sk && '' !== $sid_it ) {
					$used_ids[ $sid_it ] = true;
				}
				$it_active = ! empty( $it['is_active'] ?? $it['is_enabled'] ?? $it['enabled'] ?? true );
				$it_role   = strtolower( (string) ( $it['slot_role'] ?? $it['role'] ?? 'primary' ) );
				$is_shad   = in_array( $it_role, array( 'shadow', 'standby' ), true );
				$it_tcode  = trim( (string) ( $it['test_event_code'] ?? '' ) );

				if ( $it_active ) {
					++$domain_groups[ $dom_key ]['active_count'];
					if ( $is_shad ) {
						++$domain_groups[ $dom_key ]['shadow_count'];
					} else {
						++$domain_groups[ $dom_key ]['primary_count'];
						if ( $it_sk === $default_sk ) {
							$has_prim = true;
						}
						if ( '' !== $it_tcode && '' === $primary_tcode ) {
							$primary_tcode = $it_tcode;
						}
					}
					if ( '' !== $it_tcode ) {
						$active_test_slots[] = ( $is_all_view ? ( ( $catalog[ $it_sk ]['label'] ?? $it_sk ) . '·' ) : '' ) . self::format_slot_name_chinese( $sid_it ) . '：' . $it_tcode;
						if ( '' === $primary_tcode ) {
							$primary_tcode = $it_tcode;
						}
					}
				}
			}

			if ( empty( $domain_groups ) && ! $is_all_view ) {
				$fallback_dk                   = '' !== $primary_domain ? preg_replace( '/^www\./i', '', strtolower( $primary_domain ) ) : $site_view;
				$domain_groups[ $fallback_dk ] = array(
					'domain_display' => '' !== $primary_domain ? $primary_domain : '待绑定公开域名',
					'site_key'       => $site_view,
					'site_label'     => $cur_site_label,
					'active_count'   => 0,
					'primary_count'  => 0,
					'shadow_count'   => 0,
					'slots'          => array(),
				);
			}

			$tstats       = isset( $site_info['test_stats'] ) && is_array( $site_info['test_stats'] ) ? $site_info['test_stats'] : array();
			$t_unpurged   = (int) ( $tstats['unpurged_total'] ?? 0 );
			$t_purch      = (int) ( $tstats['purchase_count'] ?? 0 );
			$t_shal       = (int) ( $tstats['shallow_count'] ?? 0 );
			$t_last_src   = (string) ( $tstats['last_test_source'] ?? '' );
			$test_host    = '' !== $primary_domain ? $primary_domain : 'example.com';
			$fbclid_tag   = '' !== $primary_tcode ? ( 'IwAR0_' . $primary_tcode ) : 'IwAR0_test_123';
			$test_url_str = 'https://' . $test_host . '/?fbclid=' . rawurlencode( $fbclid_tag );
			$has_active_t = ! empty( $active_test_slots );

			$next_slot_id = 'slot_01';
			for ( $i = 1; $i <= 10; $i++ ) {
				$cand = sprintf( 'slot_%02d', $i );
				if ( ! isset( $used_ids[ $cand ] ) ) {
					$next_slot_id = $cand;
					break;
				}
			}
			$next_role = $has_prim ? 'shadow' : 'primary';
			$all_evs   = array( 'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase' );
			$new_cfg   = array(
				'site_key'        => $default_sk,
				'slot_id'         => $next_slot_id,
				'slot_role'       => $next_role,
				'bm_id'           => '',
				'bm_label'        => '',
				'dataset_id'      => '',
				'event_domain'    => $primary_domain,
				'test_event_code' => '',
				'has_proxy'       => false,
				'enabled_events'  => 'shadow' === $next_role ? array( 'Purchase' ) : $all_evs,
				'strip_fbc'       => ( 'shadow' === $next_role ),
				'delay_min_sec'   => 'shadow' === $next_role ? 15 : 0,
				'delay_max_sec'   => 'shadow' === $next_role ? 60 : 0,
				'is_active'       => true,
				'is_edit'         => false,
			);
			?>
			<div class="scapi-store-pills">
				<a class="scapi-pill <?php echo $is_all_view ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'slots', 'site_view' => 'ALL' ), $base_page ) ); ?>">
					🌐 全网按域名分组总览
				</a>
				<?php foreach ( $catalog as $sk => $meta ) : ?>
					<?php if ( 'repurchase_hub_02' === $sk ) { continue; } ?>
					<?php
					$sk_dom = (string) ( $sites_map[ $sk ]['primary_domain'] ?? '' );
					$sk_act = (int) ( $sites_map[ $sk ]['active_slot_count'] ?? 0 );
					?>
					<a class="scapi-pill <?php echo $sk === $site_view ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'slots', 'site_view' => $sk ), $base_page ) ); ?>">
						<?php echo esc_html( $meta['label'] ); ?><?php echo '' !== $sk_dom ? ' · ' . esc_html( $sk_dom ) : ''; ?><?php echo $sk_act > 0 ? ' (' . esc_html( (string) $sk_act ) . '/' . esc_html( (string) $max_active ) . ')' : ''; ?>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( ! $is_all_view ) : ?>
				<!-- 全自动绑定域名展示条（无需手动输入） -->
				<div class="scapi-card" style="margin-bottom:12px;background:#f0fdf4;border-left:4px solid #16a34a;">
					<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
						<div>
							<strong style="color:#14532d;"><?php echo esc_html( $cur_site_label ); ?> · 当前绑定域名：</strong>
							<span class="scapi-badge ok" style="font-size:13px;"><?php echo esc_html( $primary_domain ?: '待绑定店铺域名' ); ?></span>
							<span style="font-size:12px;color:#166534;margin-left:8px;">（单域名最多并行启用 <strong><?php echo esc_html( (string) $max_active ); ?></strong> 个活跃槽位，支持多 <code>primary</code> 消耗户平滑切户 + <code>shadow</code> 主像素保险箱物理隔离）</span>
						</div>
						<div>
							<span style="font-size:13px;color:#334155;margin-right:8px;">已配专属脱敏 SKU：<strong><?php echo esc_html( (string) $excl_sku_cnt ); ?></strong> 条</span>
							<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'dictionary', 'dict_scope' => $site_view ), $base_page ) ); ?>">拖拽配对该站脱敏 SKU →</a>
						</div>
					</div>
				</div>

				<!-- 测单入口链接、当前测试码回显与防污染隔离台 -->
				<div class="scapi-card" style="margin-bottom:14px;background:<?php echo $has_active_t ? '#fffbeb' : '#f8fafc'; ?>;border-left:4px solid <?php echo $has_active_t ? '#d97706' : '#64748b'; ?>;">
					<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
						<div style="flex:1 1 520px;min-width:0;">
							<div style="margin-bottom:6px;display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
								<?php if ( $has_active_t ) : ?>
									<strong style="color:#92400e;">🧪 独立测试事件码生效中：</strong>
									<?php foreach ( $active_test_slots as $ats_lbl ) : ?>
										<span class="scapi-badge warn" style="font-size:13px;"><?php echo esc_html( $ats_lbl ); ?></span>
									<?php endforeach; ?>
									<span style="font-size:12px;color:#92400e;">（防污染保护已激活：带测试标记的模拟单仅发往匹配测试码的槽位，正式模式下的 <code>shadow</code> 主像素保险箱将<strong>自动静默跳过</strong>）</span>
								<?php else : ?>
									<strong style="color:#334155;">⚪ 独立测试码状态：</strong>
									<span class="scapi-badge neutral" style="font-size:12px;">全部槽位均为纯净正式模式</span>
									<span style="font-size:12px;color:#64748b;">（如需单独测某个新外部户槽位，点该槽位「修改 / 换号」填入 <code>TESTxxxxx</code> 即可，互不串扰其他正式槽）</span>
								<?php endif; ?>
							</div>
							<div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:4px;">
								<strong style="font-size:13px;color:#0f172a;">🔗 专属测单链接：</strong>
								<code style="font-size:12px;background:#ffffff;border:1px solid #cbd5e1;padding:3px 8px;border-radius:4px;word-break:break-all;"><?php echo esc_html( $test_url_str ); ?></code>
								<button type="button" class="button button-small" onclick="window.StealthHubUI && StealthHubUI.copyTestLink('<?php echo esc_js( $test_url_str ); ?>', this);">📋 复制测试链接</button>
							</div>
						</div>
						<div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex:0 0 auto;">
							<?php if ( $t_unpurged > 0 ) : ?>
								<span class="scapi-badge warn" style="font-size:12px;" title="<?php echo esc_attr( '' !== $t_last_src ? '最近识别来源：' . $t_last_src : '' ); ?>">
									🧪 已识别测试记录：<?php echo esc_html( (string) $t_unpurged ); ?> 条（成交 <?php echo esc_html( (string) $t_purch ); ?> 单 / 浅层 <?php echo esc_html( (string) $t_shal ); ?> 条）
								</span>
							<?php else : ?>
								<span class="scapi-badge ok" style="font-size:12px;">✅ 本域名当前无残留测试数据（0 条）</span>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;" onsubmit="return window.StealthHubUI ? StealthHubUI.confirmClearTestData('<?php echo esc_js( $cur_site_label ); ?>', <?php echo (int) $t_unpurged; ?>) : true;">
								<input type="hidden" name="action" value="pchq_capi" />
								<input type="hidden" name="capi_sub_action" value="clear_test_data" />
								<input type="hidden" name="return_tab" value="slots" />
								<input type="hidden" name="return_site_view" value="<?php echo esc_attr( $site_view ); ?>" />
								<input type="hidden" name="site_key" value="<?php echo esc_attr( $site_view ); ?>" />
								<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
								<button type="submit" class="button <?php echo $t_unpurged > 0 ? 'button-primary' : ''; ?>" style="<?php echo $t_unpurged > 0 ? 'background:#d97706;border-color:#b45309;color:#fff;' : ''; ?>">
									🧹 清空测试数据<?php echo $t_unpurged > 0 ? '（' . esc_html( (string) $t_unpurged ) . ' 条）' : ''; ?>
								</button>
							</form>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<!-- 顶部操作栏 -->
			<div class="scapi-card" style="padding:14px 18px;margin-bottom:14px;">
				<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
					<div>
						<h2 style="margin:0;font-size:16px;">单域名多槽位并行分发面板（<?php echo esc_html( $cur_site_label ); ?> · 按域名分组）</h2>
						<p style="margin:4px 0 0;font-size:12px;color:#64748b;">
							隔离红线：① 同域名活跃槽上限 <strong><?php echo esc_html( (string) $max_active ); ?> 个</strong>（停用槽不占配额）；② 同域名活跃槽<strong>严禁共用代理 IP:Port</strong> 且代理断开绝不回退本机直连；③ <code>shadow</code> 旁路养模槽默认仅收 <code>Purchase</code>、强制剥离 <code>fbc</code>（保留 <code>fbp</code> 与 11 项高匹配参数）、独立 <code>sh_</code> 事件 ID、<code>15~60s</code> 持久化错峰发送。
						</p>
					</div>
					<div>
						<button type="button" class="button button-primary" data-slot-cfg="<?php echo esc_attr( wp_json_encode( $new_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>" onclick="window.StealthHubUI && StealthHubUI.openReplaceModalFromBtn(this);">+ 新增独立槽位</button>
						<a class="button" style="margin-left:6px;" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'roxy' ), $base_page ) ); ?>">从 Roxy 作战台下发 →</a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:6px;">
							<input type="hidden" name="action" value="pchq_capi" />
							<input type="hidden" name="capi_sub_action" value="reload_gateway" />
							<input type="hidden" name="return_tab" value="slots" />
							<input type="hidden" name="return_site_view" value="<?php echo esc_attr( $site_view ); ?>" />
							<input type="hidden" name="site_key" value="<?php echo esc_attr( $default_sk ); ?>" />
							<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
							<button type="submit" class="button">热重载网关</button>
						</form>
					</div>
				</div>
			</div>

			<?php if ( empty( $domain_groups ) ) : ?>
				<div class="scapi-card" style="color:#475569;padding:20px;">当前暂无已挂载的域名槽位，请点击右上角「+ 新增独立槽位」或从 Roxy 作战台一键下发。</div>
			<?php endif; ?>

			<?php foreach ( $domain_groups as $dom_key => $dgrp ) : ?>
				<?php
				$grp_dom      = (string) $dgrp['domain_display'];
				$grp_sk       = (string) $dgrp['site_key'];
				$grp_label    = (string) $dgrp['site_label'];
				$grp_gw_meta  = $domains_gw[ $dom_key ] ?? array();
				$act_cnt      = isset( $grp_gw_meta['active_slot_count'] ) ? (int) $grp_gw_meta['active_slot_count'] : (int) $dgrp['active_count'];
				$quota_cls    = $act_cnt >= $max_active ? 'warn' : ( $act_cnt > 0 ? 'ok' : 'neutral' );
				$grp_slots    = $dgrp['slots'];
				$grp_used_ids = array();
				foreach ( $grp_slots as $gsl ) {
					if ( ! empty( $gsl['slot_id'] ) ) {
						$grp_used_ids[ (string) $gsl['slot_id'] ] = true;
					}
				}
				$grp_next_sid = 'slot_01';
				for ( $gi = 1; $gi <= 10; $gi++ ) {
					$gcand = sprintf( 'slot_%02d', $gi );
					if ( ! isset( $grp_used_ids[ $gcand ] ) ) {
						$grp_next_sid = $gcand;
						break;
					}
				}
				$grp_def_role = (int) $dgrp['primary_count'] > 0 ? 'shadow' : 'primary';
				$grp_new_cfg  = array(
					'site_key'        => $grp_sk,
					'slot_id'         => $grp_next_sid,
					'slot_role'       => $grp_def_role,
					'bm_id'           => '',
					'bm_label'        => '',
					'dataset_id'      => '',
					'event_domain'    => ( '待绑定公开域名' !== $grp_dom ) ? $grp_dom : '',
					'test_event_code' => '',
					'has_proxy'       => false,
					'enabled_events'  => 'shadow' === $grp_def_role ? array( 'Purchase' ) : $all_evs,
					'strip_fbc'       => ( 'shadow' === $grp_def_role ),
					'delay_min_sec'   => 'shadow' === $grp_def_role ? 15 : 0,
					'delay_max_sec'   => 'shadow' === $grp_def_role ? 60 : 0,
					'is_active'       => ( $act_cnt < $max_active ),
					'is_edit'         => false,
				);
				?>
				<div class="scapi-domain-group">
					<div class="scapi-domain-head">
						<div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
							<strong style="font-size:14px;color:#0f172a;">🌐 域名：<code><?php echo esc_html( $grp_dom ); ?></code></strong>
							<span class="scapi-badge primary"><?php echo esc_html( $grp_label ); ?></span>
							<span class="scapi-badge <?php echo esc_attr( $quota_cls ); ?>">活跃槽位：<?php echo esc_html( (string) $act_cnt ); ?> / <?php echo esc_html( (string) $max_active ); ?> 上限</span>
							<span style="font-size:12px;color:#475569;">（主归属 <strong><?php echo esc_html( (string) $dgrp['primary_count'] ); ?></strong> · 旁路养模 <strong><?php echo esc_html( (string) $dgrp['shadow_count'] ); ?></strong> · 总计 <?php echo esc_html( (string) count( $grp_slots ) ); ?> 槽）</span>
						</div>
						<div>
							<button type="button" class="button button-small" data-slot-cfg="<?php echo esc_attr( wp_json_encode( $grp_new_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>" onclick="window.StealthHubUI && StealthHubUI.openReplaceModalFromBtn(this);">+ 在此域名新增槽位</button>
						</div>
					</div>

					<table class="scapi-table">
						<thead>
							<tr>
								<th>槽位名称与备注</th>
								<th>槽位角色与防关联策略</th>
								<th>Pixel / Dataset ID · 测试码</th>
								<th>转发事件白名单</th>
								<th>脱敏独享代理</th>
								<th>启用开关 · 通道状态</th>
								<th>独立计数器（正式成功/失败/测试）与最近报错</th>
								<th>快捷操作</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $grp_slots ) ) : ?>
								<tr><td colspan="8" style="color:#475569;padding:16px 14px;background:#f8fafc;">该域名下暂无已挂载槽位。点击右上角「+ 在此域名新增槽位」即可配置 <code>primary</code>（主归属消耗户）与 <code>shadow</code>（旁路主像素养模槽）。</td></tr>
							<?php else : ?>
								<?php foreach ( $grp_slots as $sl ) : ?>
									<?php
									$sl_sk      = (string) ( $sl['site_key'] ?? $grp_sk );
									$s_id       = (string) ( $sl['slot_id'] ?? '' );
									$raw_role   = strtolower( (string) ( $sl['slot_role'] ?? $sl['role'] ?? 'primary' ) );
									$is_shadow  = in_array( $raw_role, array( 'shadow', 'standby' ), true );
									$norm_role  = $is_shadow ? 'shadow' : 'primary';
									$active_v   = $sl['is_active'] ?? $sl['is_enabled'] ?? $sl['enabled'] ?? true;
									$is_active  = ! empty( $active_v );
									$circuit    = strtoupper( (string) ( $sl['circuit_state'] ?? 'CLOSED' ) );
									$dot_cls    = ! $is_active ? 'gray' : ( 'CLOSED' === $circuit ? 'green' : ( 'PAUSED_RATE_LIMIT' === $circuit ? 'yellow' : 'red' ) );
									$badge_cls  = ! $is_active ? 'neutral' : ( 'CLOSED' === $circuit ? 'ok' : ( 'PAUSED_RATE_LIMIT' === $circuit ? 'warn' : 'err' ) );
									$cur_bm_id  = trim( (string) ( $sl['bm_id'] ?? '' ) );
									$bm_label   = (string) ( $sl['bm_label'] ?? trim( $cur_bm_id . ' ' . ( $sl['profile_memo'] ?? '' ) ) );
									$ev_dom     = (string) ( $sl['event_domain'] ?? $grp_dom );
									$cur_ds_id  = (string) ( $sl['dataset_id'] ?? '' );
									$cur_tcode  = trim( (string) ( $sl['test_event_code'] ?? '' ) );
									$proxy_disp = (string) ( $sl['proxy_url_masked'] ?? $sl['egress_proxy_masked'] ?? '' );
									$has_ex_prx = ( '' !== $proxy_disp && '-' !== $proxy_disp );
									$en_evs     = isset( $sl['enabled_events'] ) && is_array( $sl['enabled_events'] ) && ! empty( $sl['enabled_events'] )
										? array_values( $sl['enabled_events'] )
										: ( $is_shadow ? array( 'Purchase' ) : $all_evs );
									$strip_fbc  = isset( $sl['strip_fbc'] ) ? ! empty( $sl['strip_fbc'] ) : $is_shadow;
									$d_min_s    = isset( $sl['delay_min_sec'] ) ? (int) $sl['delay_min_sec'] : ( $is_shadow ? 15 : 0 );
									$d_max_s    = isset( $sl['delay_max_sec'] ) ? (int) $sl['delay_max_sec'] : ( $is_shadow ? 60 : 0 );
									$p_cnt      = (int) ( $sl['delivered_purchase_count'] ?? 0 );
									$sh_cnt     = (int) ( $sl['delivered_shallow_count'] ?? 0 );
									$fail_cnt   = (int) ( $sl['failed_count'] ?? $sl['failed_deliveries_count'] ?? 0 );
									$retry_cnt  = (int) ( $sl['retrying_count'] ?? 0 );
									$tp_cnt     = (int) ( $sl['test_purchase_count'] ?? 0 );
									$tsh_cnt    = (int) ( $sl['test_shallow_count'] ?? 0 );
									$last_err   = trim( (string) ( $sl['last_error_summary'] ?? $sl['failure_reason'] ?? '' ) );
									$last_err_t = (string) ( $sl['last_error_at'] ?? '' );
									$slot_cn    = self::format_slot_name_chinese( $s_id );
									$row_cfg    = array(
										'site_key'        => $sl_sk,
										'slot_id'         => $s_id,
										'slot_role'       => $norm_role,
										'bm_id'           => $cur_bm_id,
										'bm_label'        => $bm_label,
										'dataset_id'      => $cur_ds_id,
										'event_domain'    => $ev_dom,
										'test_event_code' => $cur_tcode,
										'has_proxy'       => $has_ex_prx,
										'enabled_events'  => $en_evs,
										'strip_fbc'       => $strip_fbc,
										'delay_min_sec'   => $d_min_s,
										'delay_max_sec'   => $d_max_s,
										'is_active'       => $is_active,
										'is_edit'         => true,
									);
									?>
									<tr style="<?php echo ! $is_active ? 'opacity:0.62;background:#f8fafc;' : ''; ?>">
										<td>
											<div>
												<strong><?php echo esc_html( $slot_cn ); ?></strong>
												<code style="font-size:11px;margin-left:4px;"><?php echo esc_html( $s_id ); ?></code>
											</div>
											<div style="font-size:12px;color:#334155;margin-top:2px;"><?php echo esc_html( $bm_label ?: '未填写备注' ); ?></div>
											<?php if ( '' !== $cur_bm_id ) : ?>
												<div style="margin-top:3px;"><span class="scapi-badge neutral" style="font-size:11px;font-family:monospace;">🏢 BM: <?php echo esc_html( $cur_bm_id ); ?></span></div>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $is_shadow ) : ?>
												<span class="scapi-badge purple">🛡️ 旁路养模 (shadow)</span>
											<?php else : ?>
												<span class="scapi-badge primary">🟢 主归属 (primary)</span>
											<?php endif; ?>
											<div style="margin-top:4px;font-size:11.5px;color:#475569;">
												<?php if ( $strip_fbc ) : ?>
													<span style="color:#7e22ce;font-weight:600;">✂️ 剥离 fbc</span>
												<?php else : ?>
													<span style="color:#15803d;">保留 fbc</span>
												<?php endif; ?>
												·
												<?php if ( 0 === $d_min_s && 0 === $d_max_s ) : ?>
													<span>⚡ 0s 实时</span>
												<?php else : ?>
													<span>⏱️ <?php echo esc_html( $d_min_s . '~' . $d_max_s . 's' ); ?> 错峰</span>
												<?php endif; ?>
											</div>
										</td>
										<td>
											<code><?php echo esc_html( '' !== $cur_ds_id ? $cur_ds_id : '-' ); ?></code>
											<div style="margin-top:4px;">
												<?php if ( '' !== $cur_tcode ) : ?>
													<span class="scapi-badge warn" style="font-size:11px;">🧪 测试码：<?php echo esc_html( $cur_tcode ); ?></span>
												<?php else : ?>
													<span class="scapi-badge neutral" style="font-size:11px;">⚪ 正式模式</span>
												<?php endif; ?>
											</div>
										</td>
										<td>
											<?php
											$core_6_hit = count( array_intersect( $en_evs, array( 'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase' ) ) );
											?>
											<?php if ( $core_6_hit >= 6 ) : ?>
												<span class="scapi-badge ok" style="font-size:11.5px;">全漏斗 (6项)</span>
											<?php elseif ( count( $en_evs ) >= 5 ) : ?>
												<span class="scapi-badge ok" style="font-size:11.5px;">全漏斗 (<?php echo esc_html( (string) count( $en_evs ) ); ?>项)</span>
											<?php else : ?>
												<?php foreach ( $en_evs as $ev_item ) : ?>
													<span class="scapi-ev-pill <?php echo 'Purchase' === $ev_item ? 'purchase' : ''; ?>"><?php echo esc_html( (string) $ev_item ); ?></span>
												<?php endforeach; ?>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $has_ex_prx ) : ?>
												<code style="font-size:12px;"><?php echo esc_html( $proxy_disp ); ?></code>
											<?php else : ?>
												<span class="scapi-badge err">🚨 缺独享代理（已阻断直连）</span>
											<?php endif; ?>
										</td>
										<td>
											<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
												<?php if ( $is_active ) : ?>
													<span class="scapi-badge ok"><span class="scapi-dot green"></span>启用中</span>
												<?php else : ?>
													<span class="scapi-badge neutral"><span class="scapi-dot gray"></span>已停用 (不占上限)</span>
												<?php endif; ?>
											</div>
											<div style="margin-top:4px;">
												<span class="scapi-badge <?php echo esc_attr( $badge_cls ); ?>" style="font-size:11px;">
													<span class="scapi-dot <?php echo esc_attr( $dot_cls ); ?>"></span><?php echo esc_html( $is_active ? self::format_circuit_chinese( $circuit ) : '静态挂起' ); ?>
												</span>
											</div>
										</td>
										<td>
											<div>
												<span style="color:#15803d;font-weight:700;">正式成功：<?php echo esc_html( (string) $p_cnt ); ?> 单</span>
												<span style="color:#475569;font-size:12px;"> / <?php echo esc_html( (string) $sh_cnt ); ?> 浅层</span>
												<?php if ( $fail_cnt > 0 || $retry_cnt > 0 ) : ?>
													<span class="scapi-badge err" style="font-size:11px;margin-left:4px;">失败 <?php echo esc_html( (string) $fail_cnt ); ?><?php echo $retry_cnt > 0 ? ' · 重试中 ' . esc_html( (string) $retry_cnt ) : ''; ?></span>
												<?php else : ?>
													<span style="color:#94a3b8;font-size:11.5px;margin-left:4px;">· 失败 0</span>
												<?php endif; ?>
											</div>
											<div style="margin-top:3px;font-size:11.5px;color:#64748b;">
												🧪 测试计数：<?php echo esc_html( (string) $tp_cnt ); ?> 单 / <?php echo esc_html( (string) $tsh_cnt ); ?> 浅层
											</div>
											<?php if ( '' !== $last_err ) : ?>
												<div class="scapi-err-snippet" title="<?php echo esc_attr( $last_err . ( '' !== $last_err_t ? ' (' . $last_err_t . ')' : '' ) ); ?>">
													⚠️ 最近报错：<?php echo esc_html( $last_err ); ?>
												</div>
											<?php endif; ?>
										</td>
										<td style="white-space:nowrap;">
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:4px;">
												<input type="hidden" name="action" value="pchq_capi" />
												<input type="hidden" name="capi_sub_action" value="toggle_slot" />
												<input type="hidden" name="return_tab" value="slots" />
												<input type="hidden" name="return_site_view" value="<?php echo esc_attr( $site_view ); ?>" />
												<input type="hidden" name="site_key" value="<?php echo esc_attr( $sl_sk ); ?>" />
												<input type="hidden" name="slot_id" value="<?php echo esc_attr( $s_id ); ?>" />
												<input type="hidden" name="is_active" value="<?php echo $is_active ? '0' : '1'; ?>" />
												<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
												<button type="submit" class="button button-small <?php echo ! $is_active ? 'button-primary' : ''; ?>">
													<?php echo $is_active ? '⏸ 停用' : '▶ 启用'; ?>
												</button>
											</form>
											<button type="button" class="button button-small" data-slot-cfg="<?php echo esc_attr( wp_json_encode( $row_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>" onclick="window.StealthHubUI && StealthHubUI.openReplaceModalFromBtn(this);">修改 / 换号</button>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:4px;" onsubmit="return window.StealthHubUI ? StealthHubUI.confirmDeleteSlot('<?php echo esc_js( $grp_label . ' · ' . $slot_cn ); ?>') : true;">
												<input type="hidden" name="action" value="pchq_capi" />
												<input type="hidden" name="capi_sub_action" value="delete_slot" />
												<input type="hidden" name="return_tab" value="slots" />
												<input type="hidden" name="return_site_view" value="<?php echo esc_attr( $site_view ); ?>" />
												<input type="hidden" name="site_key" value="<?php echo esc_attr( $sl_sk ); ?>" />
												<input type="hidden" name="slot_id" value="<?php echo esc_attr( $s_id ); ?>" />
												<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
												<button type="submit" class="button button-small button-link-delete">删除</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>

			<!-- 新增 / 修改槽位参数 / 换号弹窗（支持主归属 primary 与旁路养模 shadow 并行配置） -->
			<div id="scapi-replace-modal" class="scapi-modal-backdrop">
				<div class="scapi-modal" style="max-width:720px;">
					<h2 id="scapi-replace-modal-title" style="margin-top:0;">配置独立像素槽位（主归属 primary + 旁路养模 shadow 物理隔离）</h2>
					<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#92400e;line-height:1.6;">
						<strong>防关联与强隔离铁律：</strong><br/>
						① <strong>单域名最多 3 个活跃槽 & 代理 IP:Port 强制互异</strong>：同一域名下任意两个活跃槽位严禁共用相同代理出口，代理异常时直接阻断记录报错，绝不回退本机直连 IP。<br/>
						② <strong>旁路养模槽（<code>shadow</code>）脱敏保护</strong>：默认仅转发 <code>Purchase</code>，强制剥离外部消耗户点击参数 <code>fbc</code>（保留 <code>fbp</code> 与全部 11 项高匹配用户参数），自动生成独立 <code>sh_</code> 事件 ID，并在 Outbox 持久化随机错峰 <code>15~60</code> 秒后投递。
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="replace_dead_slot" />
						<input type="hidden" name="return_tab" value="slots" />
						<input type="hidden" name="return_site_view" value="<?php echo esc_attr( $site_view ); ?>" />
						<input type="hidden" name="is_edit_existing" value="1" />
						<input type="hidden" name="strip_fbc_present" value="1" />
						<input type="hidden" name="is_active_present" value="1" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<p>
							<label><strong>从 Roxy 指纹浏览器窗口快捷带入备注与代理（可选）：</strong><br />
								<select id="scapi-modal-roxy-select" class="large-text"></select>
							</label>
						</p>
						<p style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
							<label>所属站点：
								<select name="site_key" style="width:155px;">
									<?php foreach ( $catalog as $sk_opt => $meta_opt ) : ?>
										<?php if ( 'repurchase_hub_02' === $sk_opt ) { continue; } ?>
										<option value="<?php echo esc_attr( $sk_opt ); ?>" <?php selected( $sk_opt, $default_sk ); ?>><?php echo esc_html( $meta_opt['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label>槽位编号：
								<select name="slot_id" style="width:140px;">
									<?php for ( $si = 1; $si <= 6; $si++ ) : ?>
										<?php $sid_opt = sprintf( 'slot_%02d', $si ); ?>
										<option value="<?php echo esc_attr( $sid_opt ); ?>" <?php selected( $sid_opt, $next_slot_id ); ?>><?php echo esc_html( $si . '号槽 (' . $sid_opt . ')' ); ?></option>
									<?php endfor; ?>
								</select>
							</label>
							<label>槽位角色：
								<select name="slot_role" onchange="window.StealthHubUI && StealthHubUI.syncSlotRoleDefaults(this.form, true);">
									<option value="primary" <?php selected( 'primary', $next_role ); ?>>🟢 主归属槽 (primary · 消耗户全漏斗)</option>
									<option value="shadow" <?php selected( 'shadow', $next_role ); ?>>🛡️ 旁路养模槽 (shadow · 主像素保险箱)</option>
								</select>
							</label>
						</p>
						<p style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
							<label style="flex:1 1 260px;"><strong>关联投流域名（按域名并行分发）：</strong><br />
								<input type="text" name="event_domain" class="large-text" value="<?php echo esc_attr( $primary_domain ); ?>" placeholder="例如：www.korgavex.com" required />
							</label>
							<label style="flex:1 1 240px;"><strong class="scapi-bmid-req-label" style="color:#334155;">BM ID 备注（选填 · 外部户可留空）：</strong><br />
								<input type="text" name="bm_id" class="large-text" placeholder="选填：6~24 位纯数字 BM 编号（外部邮箱户/二三不限可留空）" />
							</label>
						</p>
						<p><label><strong>槽位名称 / 账号备注（必填）：</strong><br /><input type="text" name="bm_label" class="large-text" placeholder="例如：消耗户A-邮箱户01 或 自有主像素BM保险箱" required /></label></p>
						<p><label><strong>Pixel / Dataset ID（必填，全局不可与其他槽位重复）：</strong><br /><input type="text" name="dataset_id" class="large-text" placeholder="请输入 15~16 位纯数字像素编号" required /></label></p>
						<p><label><strong>系统用户 / 像素访问令牌 Access Token：</strong><br /><input type="password" name="access_token" class="large-text" placeholder="请粘贴 EAA... 访问令牌" required /></label></p>
						<p><label><strong style="color:#b91c1c;">独享静态代理 URL（必填 · 同域名活跃槽严禁共用 IP:端口）：</strong><br /><input type="text" name="egress_proxy" class="large-text" placeholder="必填，格式：http://账号:密码@代理IP:端口 或 socks5://账号:密码@代理IP:端口" required /></label></p>
						<div>
							<strong>转发事件白名单（<code>enabled_events</code>）：</strong>
							<div class="scapi-ev-checks">
								<?php foreach ( $all_evs as $ev_opt ) : ?>
									<label>
										<input type="checkbox" name="enabled_events[]" value="<?php echo esc_attr( $ev_opt ); ?>" <?php checked( 'Purchase' === $ev_opt || 'primary' === $next_role ); ?> />
										<?php echo esc_html( $ev_opt ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
						<p style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-top:12px;">
							<label style="font-weight:600;color:#5b21b6;">
								<input type="checkbox" name="strip_fbc" value="1" <?php checked( 'shadow' === $next_role ); ?> />
								强制剥离 <code>fbc</code> 点击归因 ID（保留 <code>fbp</code> 与 11 项高匹配参数）
							</label>
							<span>
								<strong>异步错峰延迟区间（秒）：</strong>
								<input type="number" name="delay_min_sec" value="<?php echo 'shadow' === $next_role ? '15' : '0'; ?>" min="0" max="86400" style="width:68px;" /> 秒 ～
								<input type="number" name="delay_max_sec" value="<?php echo 'shadow' === $next_role ? '60' : '0'; ?>" min="0" max="86400" style="width:68px;" /> 秒
							</span>
						</p>
						<p style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
							<label><strong>独立测试事件码：</strong><input type="text" name="test_event_code" id="scapi-modal-test-code" style="width:165px;margin-left:6px;" placeholder="正式模式请留空" /></label>
							<button type="button" class="button button-small" onclick="var el=document.getElementById('scapi-modal-test-code');if(el){el.value='';el.focus();}">清空测试码</button>
							<label style="font-weight:700;color:#15803d;">
								<input type="checkbox" name="is_active" value="1" checked />
								保存后处于启用状态（<code>is_active = true</code>）
							</label>
						</p>
						<p><label><input type="checkbox" name="trigger_backfill" value="1" /> 立即将本站过去 6.5 天正式成交回填至该槽位（默认关闭）</label></p>
						<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
							<button type="button" class="button" onclick="window.StealthHubUI && StealthHubUI.closeReplaceModal();">取消</button>
							<button type="submit" class="button button-primary">保存槽位并实时生效</button>
						</div>
					</form>
				</div>
			</div>
			<?php
		}

		private static function render_tab_dictionary( string $dict_scope, array $catalog, string $base_page ): void {
			$is_global      = ( '__GLOBAL__' === $dict_scope );
			$dict_res       = \StealthCapiBridge::fetch_sanitizer_dictionary( $is_global ? '__GLOBAL__' : $dict_scope, true );
			$sku_map        = $dict_res['data']['sku_map'] ?? array();
			$global_res     = $is_global ? $dict_res : \StealthCapiBridge::fetch_sanitizer_dictionary( '__GLOBAL__', true );
			$global_map     = $global_res['data']['sku_map'] ?? array();
			$global_cnt     = (int) ( $dict_res['data']['global_fallback_count'] ?? count( $global_map ) );
			$scope_label    = $is_global ? '全局默认兜底池' : ( $catalog[ $dict_scope ]['label'] ?? $dict_scope );
			$store_products = ! $is_global ? self::fetch_store_products_for_buyer( $dict_scope ) : array();
			?>
			<div class="scapi-store-pills">
				<?php foreach ( $catalog as $sk => $meta ) : ?>
					<?php if ( 'repurchase_hub_02' === $sk ) { continue; } ?>
					<a class="scapi-pill <?php echo $sk === $dict_scope ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'dictionary', 'dict_scope' => $sk ), $base_page ) ); ?>">
						<?php echo esc_html( $meta['label'] ); ?> · 专属脱敏配对
					</a>
				<?php endforeach; ?>
				<a class="scapi-pill <?php echo $is_global ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'dictionary', 'dict_scope' => '__GLOBAL__' ), $base_page ) ); ?>">
					全局默认兜底池（<?php echo esc_html( (string) $global_cnt ); ?> 条）
				</a>
			</div>

			<?php if ( ! $is_global ) : ?>
				<script>
					window.SCAPI_STORE_PRODUCTS = <?php echo wp_json_encode( $store_products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
				</script>
				<div class="scapi-grid-2">
					<!-- 左栏：当前站点真实商品原始 SKU 数据 -->
					<div class="scapi-card">
						<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
							<div>
								<h2 style="margin:0;">【左栏】原始商城商品 SKU（<?php echo esc_html( $scope_label ); ?> · 实时 <?php echo esc_html( (string) count( $store_products ) ); ?> 个规格）</h2>
								<span style="font-size:12px;color:#64748b;">已生效伪装货号映射字典（专属已配 <strong><?php echo esc_html( (string) count( $sku_map ) ); ?></strong> 条）</span>
							</div>
							<div style="display:flex;gap:6px;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
									<input type="hidden" name="action" value="pchq_capi" />
									<input type="hidden" name="capi_sub_action" value="refresh_store_skus" />
									<input type="hidden" name="return_tab" value="dictionary" />
									<input type="hidden" name="dict_scope" value="<?php echo esc_attr( $dict_scope ); ?>" />
									<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
									<button type="submit" class="button button-small">🔄 重新抓取本店商品</button>
								</form>
								<?php if ( ! empty( $sku_map ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;" onsubmit="return window.confirm('确认清空【<?php echo esc_js( $scope_label ); ?>】全部专属映射吗？');">
										<input type="hidden" name="action" value="pchq_capi" />
										<input type="hidden" name="capi_sub_action" value="dict_clear_site" />
										<input type="hidden" name="return_tab" value="dictionary" />
										<input type="hidden" name="dict_scope" value="<?php echo esc_attr( $dict_scope ); ?>" />
										<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
										<button type="submit" class="button button-small button-link-delete">清空专属映射</button>
									</form>
								<?php endif; ?>
							</div>
						</div>
						<div class="scapi-dual-scroll">
							<table class="scapi-table">
								<thead>
									<tr>
										<th>商城原商品与规格</th>
										<th>原始 SKU</th>
										<th>售价</th>
										<th>当前生效脱敏 SKU</th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $store_products ) ) : ?>
										<tr><td colspan="4" style="color:#64748b;padding:16px;">暂未读取到线上店铺商品列表，请点击右上角「重新抓取本店商品」。</td></tr>
									<?php else : ?>
										<?php foreach ( $store_products as $sp ) : ?>
											<?php
											$r_sku    = (string) ( $sp['raw_sku'] ?? '' );
											$r_upper  = strtoupper( $r_sku );
											$excl_hit = $sku_map[ $r_sku ] ?? $sku_map[ $r_upper ] ?? null;
											$glob_hit = $global_map[ $r_sku ] ?? $global_map[ $r_upper ] ?? null;
											$excl_val = is_array( $excl_hit ) ? (string) ( $excl_hit['safe_catalog_id'] ?? '' ) : (string) $excl_hit;
											$glob_val = is_array( $glob_hit ) ? (string) ( $glob_hit['safe_catalog_id'] ?? '' ) : (string) $glob_hit;
											?>
											<tr>
												<td>
													<strong><?php echo esc_html( (string) $sp['spu_name'] ); ?></strong>
													<?php if ( ! empty( $sp['spec_label'] ) ) : ?>
														<br /><span style="font-size:11px;color:#64748b;"><?php echo esc_html( (string) $sp['spec_label'] ); ?></span>
													<?php endif; ?>
												</td>
												<td><code><?php echo esc_html( $r_sku ); ?></code></td>
												<td><strong>$<?php echo esc_html( number_format( (float) $sp['price'], 2 ) ); ?></strong></td>
												<td>
													<?php if ( '' !== $excl_val ) : ?>
														<span class="scapi-badge ok"><?php echo esc_html( $excl_val ); ?>（专属）</span>
													<?php elseif ( '' !== $glob_val ) : ?>
														<span class="scapi-badge warn"><?php echo esc_html( $glob_val ); ?>（全局兜底）</span>
													<?php else : ?>
														<span class="scapi-badge err">待匹配</span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>

					<!-- 右栏：对应商品脱敏 SKU 拖拽自动匹配与异常检测 -->
					<div class="scapi-card">
						<h2 style="margin-top:0;">【右栏】对应商品脱敏 SKU 拖拽自动匹配（零手输防错）</h2>
						<p style="font-size:13px;color:#475569;margin-top:0;">
							直接将为【<?php echo esc_html( $scope_label ); ?>】生成的 A 站脱敏 SKU 表格（如 <code>brand_1_skus.csv</code>）拖入下方区域。系统将自动与左栏商城商品按<strong>规格价格组 + 精确售价</strong>执行一对一双射匹配，并逐行提示冗余项或违规项！
						</p>
						<label id="scapi-sku-dropzone" class="scapi-dropzone">
							<input type="file" accept=".csv,.txt" />
							<div style="font-size:32px;margin-bottom:6px;">📂</div>
							<strong id="scapi-drop-filename" style="font-size:15px;color:#0f172a;">将脱敏 SKU 表格（.csv）拖入此处，或点击选择文件</strong>
							<div style="font-size:12px;color:#64748b;margin-top:6px;">自动识别 <code>brand_1_skus.csv</code> 标准格式（<code>sku_code</code> + <code>sale_price</code> + <code>product_title_en</code>），全程免手动复制粘贴</div>
						</label>

						<div id="scapi-sku-match-report"></div>

						<form id="scapi-sku-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;margin-top:14px;">
							<input type="hidden" name="action" value="pchq_capi" />
							<input type="hidden" name="capi_sub_action" value="dict_bulk_json" />
							<input type="hidden" name="return_tab" value="dictionary" />
							<input type="hidden" name="dict_scope" value="<?php echo esc_attr( $dict_scope ); ?>" />
							<input type="hidden" id="scapi-sku-bulk-json" name="entries_json" value="" />
							<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
							<button type="submit" id="scapi-sku-bulk-submit" class="button button-primary button-hero" style="width:100%;text-align:center;">确认匹配无误 · 一键同步写入网关</button>
						</form>
					</div>
				</div>

				<div class="scapi-card" id="scapi-sku-match-preview" style="min-height:20px;">
					<span style="color:#64748b;font-size:13px;">👆 拖入右侧脱敏 CSV 文件后，此处将自动展开「左：商城原始商品 ⟷ 右：脱敏 A 站商品」逐行核对大表与差异报告。</span>
				</div>
			<?php else : ?>
				<div class="scapi-card">
					<h2 style="margin-top:0;">全局默认兜底池 — 已生效伪装货号映射字典（<?php echo esc_html( (string) count( $sku_map ) ); ?> 条）</h2>
					<p style="color:#475569;font-size:13px;">当某位投手名下有未单独配对的商品时，网关自动回退使用此全局植物护肤合规编号兜底。</p>
					<div class="scapi-dual-scroll">
						<table class="scapi-table">
							<thead><tr><th>B 站商城真实货号</th><th>全局兜底伪装编号</th><th>操作</th></tr></thead>
							<tbody>
								<?php foreach ( $sku_map as $raw_sku => $info ) : ?>
									<?php $safe_id = is_array( $info ) ? (string) ( $info['safe_catalog_id'] ?? $info['clean_code'] ?? '' ) : (string) $info; ?>
									<tr>
										<td><code><?php echo esc_html( (string) $raw_sku ); ?></code></td>
										<td><span class="scapi-badge ok"><?php echo esc_html( $safe_id ); ?></span></td>
										<td>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<input type="hidden" name="action" value="pchq_capi" />
												<input type="hidden" name="capi_sub_action" value="dict_delete" />
												<input type="hidden" name="return_tab" value="dictionary" />
												<input type="hidden" name="dict_scope" value="__GLOBAL__" />
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
			<?php endif; ?>
			<?php
		}

		private static function get_or_create_roxy_agent_key(): string {
			$key = (string) get_option( 'stealth_capi_roxy_agent_key', '' );
			if ( strlen( $key ) < 32 ) {
				$key = wp_generate_password( 48, false, false );
				update_option( 'stealth_capi_roxy_agent_key', $key, false );
			}
			return $key;
		}

		public static function ajax_roxy_browser_relay(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => '权限不足' ), 403 );
			}
			check_ajax_referer( 'scapi_roxy_relay_nonce' );

			global $wpdb;
			$path   = isset( $_POST['roxy_path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['roxy_path'] ) ) ) : '';
			$method = isset( $_POST['roxy_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['roxy_method'] ) ) ) : 'GET';
			$token  = isset( $_POST['roxy_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['roxy_token'] ) ) ) : '';
			$body   = isset( $_POST['roxy_body'] ) ? (string) wp_unslash( $_POST['roxy_body'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( '' === $path || ( 0 !== strpos( $path, '/browser/' ) && 0 !== strpos( $path, '/local/' ) ) ) {
				wp_send_json_error( array( 'message' => '非法 Roxy 接口路径' ), 400 );
			}

			$req_id   = 'rx_' . wp_generate_password( 16, false, false );
			$task_key = '_scapi_rx_req_' . $req_id;
			$resp_key = '_scapi_rx_res_' . $req_id;
			$payload  = wp_json_encode(
				array(
					'id'     => $req_id,
					'path'   => $path,
					'method' => $method,
					'token'  => $token,
					'body'   => $body,
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
			for ( $i = 0; $i < 70; $i++ ) {
				usleep( 200000 ); // 200ms * 70 = 14s max
				$val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $resp_key ) );
				if ( null !== $val && '' !== $val ) {
					$found = json_decode( (string) $val, true );
					break;
				}
			}

			$wpdb->delete( $wpdb->options, array( 'option_name' => $task_key ) );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $resp_key ) );

			if ( ! is_array( $found ) ) {
				wp_send_json_error( array( 'message' => '本机 Roxy 桥接助手未在线，请确认本机脚本与 RoxyBrowser 正在运行' ), 504 );
			}
			wp_send_json_success( array( 'roxy' => $found ) );
		}

		public static function ajax_roxy_agent_tunnel(): void {
			$provided = isset( $_SERVER['HTTP_X_ROXY_AGENT_KEY'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ROXY_AGENT_KEY'] ) ) ) : '';
			$expected = self::get_or_create_roxy_agent_key();
			if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
				wp_send_json_error( array( 'message' => 'unauthorized' ), 401 );
			}

			global $wpdb;
			$op = isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : 'poll'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'sync_probes' === $op ) {
				$raw  = (string) file_get_contents( 'php://input' );
				$data = json_decode( $raw, true );
				if ( is_array( $data ) && isset( $data['probes'] ) && is_array( $data['probes'] ) ) {
					$existing = get_option( Daily_Inspector::OPT_ROXY_PROBES, array() );
					$merged   = is_array( $existing['probes'] ?? null ) ? $existing['probes'] : array();
					foreach ( $data['probes'] as $did => $pinfo ) {
						if ( is_array( $pinfo ) ) {
							$merged[ (string) $did ] = $pinfo;
						}
					}
					update_option(
						Daily_Inspector::OPT_ROXY_PROBES,
						array(
							'probes'    => $merged,
							'synced_at' => time(),
						),
						false
					);
				}
				wp_send_json_success( array( 'synced' => true ) );
			}
			if ( 'reply' === $op ) {
				$raw  = (string) file_get_contents( 'php://input' );
				$data = json_decode( $raw, true );
				$id   = is_array( $data ) && isset( $data['id'] ) ? preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $data['id'] ) : '';
				if ( '' !== $id && isset( $data['roxy'] ) ) {
					$wpdb->replace(
						$wpdb->options,
						array(
							'option_name'  => '_scapi_rx_res_' . $id,
							'option_value' => wp_json_encode( $data['roxy'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
							'autoload'     => 'no',
						)
					);
				}
				wp_send_json_success( array( 'ack' => true ) );
			}

			// Clean stale tasks > 30s & claim pending tasks
			$rows  = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_scapi_rx_req_%' ORDER BY option_id ASC LIMIT 10", ARRAY_A );
			$tasks = array();
			$now   = time();
			foreach ( (array) $rows as $r ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $r['option_name'] ) );
				$dec = json_decode( (string) $r['option_value'], true );
				if ( is_array( $dec ) && ( $now - (int) ( $dec['ts'] ?? 0 ) ) <= 25 ) {
					$tasks[] = $dec;
				}
			}
			wp_send_json_success( array( 'tasks' => $tasks ) );
		}

		private static function build_roxy_local_bridge_script(): string {
			$hq_ajax   = esc_url_raw( admin_url( 'admin-ajax.php' ) );
			$agent_key = self::get_or_create_roxy_agent_key();
			$tpl       = <<<'PY'
#!/usr/bin/env python3
"""
RoxyBrowser 本机双模桥接助手 v3.6
- 本地 CORS (127.0.0.1:50055) + 总后台 HTTPS 云端反向穿透双通道
- 内置 Graph 公开头像端点无痕验活 (/local/probe_fb_uids)：自动提取窗口 c_user/UID，
  严格走每个窗口各自绑定的独享美国静态住宅代理单点请求 graph.facebook.com/{UID}/picture?redirect=false，
  零触碰 Cookie、零启动浏览器、零跨窗口关联！
"""
import concurrent.futures
import json
import pathlib
import re
import shutil
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROXY_TARGET = "http://127.0.0.1:50000"
LISTEN_PORT = 50055
HQ_AJAX_URL = "__HQ_AJAX_URL__"
AGENT_KEY = "__AGENT_KEY__"

CACHE_FILE = pathlib.Path(__file__).resolve().parent / "roxy_uid_probe_cache.json"
CACHE_TTL_SEC = 6 * 3600
_CACHE_LOCK = threading.Lock()


def _load_probe_cache() -> dict:
    with _CACHE_LOCK:
        if CACHE_FILE.exists():
            try:
                data = json.loads(CACHE_FILE.read_text(encoding="utf-8"))
                if isinstance(data, dict):
                    return data
            except Exception:
                pass
    return {}


def _save_probe_cache(cache: dict) -> None:
    with _CACHE_LOCK:
        try:
            CACHE_FILE.write_text(json.dumps(cache, ensure_ascii=False, indent=2), encoding="utf-8")
        except Exception:
            pass


def call_local_roxy(path: str, method: str, token: str, body_bytes: bytes | None) -> tuple[int, bytes]:
    hdrs = {"User-Agent": "RoxyLocalSDK/1.0"}
    clean_tok = (token or "").split(",")[0].strip()
    if clean_tok:
        hdrs["token"] = clean_tok
    if body_bytes:
        hdrs["Content-Type"] = "application/json"
    req = urllib.request.Request(ROXY_TARGET + path, data=body_bytes, headers=hdrs, method=method)
    try:
        with urllib.request.urlopen(req, timeout=12) as resp:
            return resp.status, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read() or json.dumps({"code": e.code, "msg": str(e)}).encode("utf-8")
    except Exception as exc:
        return 502, json.dumps({"code": 502, "msg": f"本机 RoxyBrowser (127.0.0.1:50000) 未响应: {exc}"}).encode("utf-8")


def _extract_fb_uid(det: dict) -> str:
    cookies = det.get("cookie")
    if isinstance(cookies, list):
        for c in cookies:
            if isinstance(c, dict) and str(c.get("name") or "").strip() == "c_user":
                val = str(c.get("value") or "").strip()
                if re.fullmatch(r"\d{10,17}", val):
                    return val
    wpl = det.get("windowPlatformList")
    if isinstance(wpl, list):
        for item in wpl:
            if isinstance(item, dict):
                uname = str(item.get("platformUserName") or item.get("username") or "").strip()
                if re.fullmatch(r"\d{10,17}", uname):
                    return uname
    remark = str(det.get("windowRemark") or det.get("remark") or "")
    for pat in (
        r"c_user=(\d{10,17})",
        r"(?:账号\s*(?:ID)?|UID)\s*[：:=\s]\s*(\d{10,17})",
        r"账号\s*(\d{10,17})",
        r"(?:^|\n|\s)(\d{12,17})\|",
        r"(?<!\d)((?:1000\d{10,12}|615\d{11,12}))(?!\d)",
    ):
        m = re.search(pat, remark, re.IGNORECASE)
        if m:
            return m.group(1)
    return ""


def _extract_proxy_info(det: dict) -> tuple[str, str]:
    info = det.get("proxyInfo") or det.get("proxy") or {}
    if not isinstance(info, dict):
        return "", ""
    host = str(info.get("ip") or info.get("host") or info.get("proxyHost") or "").strip()
    port = str(info.get("port") or info.get("proxyPort") or "").strip()
    if not host or not port:
        return "", ""
    cat = str(info.get("proxyCategory") or "").lower()
    proto = str(info.get("protocol") or "").lower()
    if cat == "noproxy" and (not proto or proto == "noproxy"):
        return "", ""
    raw_scheme = proto if (proto and proto != "noproxy") else (cat if (cat and cat != "noproxy") else str(info.get("proxyType") or "http").lower())
    scheme = "socks5h" if "socks" in raw_scheme else "http"
    user = str(info.get("proxyUserName") or info.get("username") or info.get("user") or "").strip()
    pwd = str(info.get("password") or info.get("proxyPassword") or "").strip()
    auth = ""
    if user:
        auth = urllib.parse.quote(user, safe="") + (":" + urllib.parse.quote(pwd, safe="") if pwd else "") + "@"
    return f"{scheme}://{auth}{host}:{port}", host


def _probe_uid_via_proxy(uid: str, proxy_url: str) -> tuple[str, int, str]:
    target_url = f"https://graph.facebook.com/{uid}/picture?redirect=false"
    curl_bin = shutil.which("curl.exe") or shutil.which("curl") or r"C:\Windows\System32\curl.exe"
    cflags = 0x08000000 if sys.platform.startswith("win") else 0
    try:
        proc = subprocess.run(
            [curl_bin, "-s", "--max-time", "10", "--proxy", proxy_url, "-w", "\n%{http_code}", target_url],
            capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=13, creationflags=cflags,
        )
        raw_out = (proc.stdout or "").strip()
        lines = raw_out.rsplit("\n", 1)
        body_str = lines[0].strip() if len(lines) == 2 else ""
        code_str = lines[-1].strip()
        http_code = int(code_str) if code_str.isdigit() else 0
        if proc.returncode != 0 or http_code == 0:
            return "proxy_err", 0, "独享静态代理无法连通或超时"
        try:
            j = json.loads(body_str)
        except Exception:
            j = {}
        if http_code == 200 and isinstance(j.get("data"), dict) and j["data"].get("url"):
            return "alive", 200, "Graph公开头像主页正常存活"
        err_obj = j.get("error") if isinstance(j, dict) else None
        if isinstance(err_obj, dict):
            ec = int(err_obj.get("code") or 0)
            sub = int(err_obj.get("error_subcode") or 0)
            emsg = str(err_obj.get("message") or "")
            if (ec == 100 and sub == 33) or ("does not exist" in emsg.lower()):
                return "dead", http_code, "公开主页已消失（触发停用/封禁，可立即找号商换号）"
            return "unknown", http_code, f"Graph返回({ec}/{sub})"
        return "unknown", http_code, f"HTTP {http_code}"
    except Exception as exc:
        return "proxy_err", 0, f"代理探测异常: {type(exc).__name__}"


def _sync_probes_to_hq(results_map: dict) -> None:
    if not HQ_AJAX_URL or not AGENT_KEY or not results_map:
        return
    try:
        sync_url = HQ_AJAX_URL + "?action=scapi_roxy_agent_tunnel&op=sync_probes"
        payload = json.dumps({"probes": results_map, "synced_at": int(time.time())}, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(
            sync_url, data=payload,
            headers={"Content-Type": "application/json", "X-Roxy-Agent-Key": AGENT_KEY, "User-Agent": "RoxyBridgeTunnel/3.6"},
            method="POST",
        )
        urllib.request.urlopen(req, timeout=8).read()
    except Exception:
        pass


def handle_probe_fb_uids(token: str, body_bytes: bytes | None) -> tuple[int, bytes]:
    clean_tok = (token or "").split(",")[0].strip()
    try:
        req_json = json.loads(body_bytes.decode("utf-8", "replace")) if body_bytes else {}
    except Exception:
        req_json = {}
    wid = str(req_json.get("workspaceId") or "").strip()
    dir_ids = req_json.get("dirIds") if isinstance(req_json.get("dirIds"), list) else []
    force = bool(req_json.get("force"))

    if not wid or not dir_ids:
        st_ws, raw_ws = call_local_roxy("/browser/workspace?page_index=1&page_size=20", "GET", clean_tok, None)
        if st_ws != 200:
            return st_ws, raw_ws
        ws_rows = ((json.loads(raw_ws.decode("utf-8", "replace")).get("data") or {}).get("rows")) or []
        if not ws_rows:
            return 200, json.dumps({"code": 0, "data": {"results": {}}}).encode("utf-8")
        wid = str(ws_rows[0].get("id") or ws_rows[0].get("workspaceId") or "")
        st_l, raw_l = call_local_roxy(f"/browser/list_v3?workspaceId={wid}&page_index=1&page_size=200", "GET", clean_tok, None)
        if st_l == 200:
            l_rows = ((json.loads(raw_l.decode("utf-8", "replace")).get("data") or {}).get("rows")) or []
            dir_ids = [
                str(r.get("dirId") or "") for r in l_rows
                if r.get("dirId")
                and str(r.get("projectName") or "").strip() not in ("", "默认项目", "默认分组", "Default", "default", "窗口转移")
                and "缓冲池" not in str(r.get("projectName") or "")
            ]

    cache = _load_probe_cache()
    now_ts = int(time.time())
    results: dict[str, dict] = {}
    to_probe: list[str] = []

    for did in dir_ids:
        did_s = str(did).strip()
        if not did_s:
            continue
        cached_item = cache.get(did_s)
        if not force and isinstance(cached_item, dict) and cached_item.get("status") in ("alive", "dead") and (now_ts - int(cached_item.get("checked_at") or 0)) < CACHE_TTL_SEC:
            results[did_s] = cached_item
        else:
            to_probe.append(did_s)

    def _work(did_s: str) -> tuple[str, dict]:
        st_d, raw_d = call_local_roxy(f"/browser/detail?workspaceId={wid}&dirId={did_s}", "GET", clean_tok, None)
        if st_d != 200:
            return did_s, {"status": "unknown", "uid_tail": "", "detail": "读取窗口详情失败", "checked_at": int(time.time())}
        d_rows = ((json.loads(raw_d.decode("utf-8", "replace")).get("data") or {}).get("rows")) or []
        det = d_rows[0] if d_rows else {}
        win_name = str(det.get("windowName") or "")
        grp_name = str(det.get("projectName") or "")
        uid = _extract_fb_uid(det)
        proxy_url, proxy_host = _extract_proxy_info(det)
        if not proxy_url:
            return did_s, {"status": "no_proxy", "uid_tail": uid[-4:] if len(uid) >= 4 else "", "window_name": win_name, "group_name": grp_name, "proxy_host": "", "detail": "未绑定静态代理", "checked_at": int(time.time())}
        if not uid:
            return did_s, {"status": "no_uid", "uid_tail": "", "window_name": win_name, "group_name": grp_name, "proxy_host": proxy_host, "detail": "待注册无UID", "checked_at": int(time.time())}
        st_str, http_c, msg = _probe_uid_via_proxy(uid, proxy_url)
        return did_s, {"status": st_str, "http_code": http_c, "uid_tail": uid[-4:], "window_name": win_name, "group_name": grp_name, "proxy_host": proxy_host, "detail": msg, "checked_at": int(time.time())}

    if to_probe:
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            for did_s, info in pool.map(_work, to_probe):
                results[did_s] = info
                cache[did_s] = info
        _save_probe_cache(cache)

    threading.Thread(target=_sync_probes_to_hq, args=(results,), daemon=True).start()
    return 200, json.dumps({"code": 0, "data": {"results": results, "checked_at": int(time.time())}}, ensure_ascii=False).encode("utf-8")


def dispatch_bridge_call(path: str, method: str, token: str, body_bytes: bytes | None) -> tuple[int, bytes]:
    if path.startswith("/local/probe_fb_uids"):
        return handle_probe_fb_uids(token, body_bytes)
    return call_local_roxy(path, method, token, body_bytes)


def cloud_tunnel_loop():
    if not HQ_AJAX_URL or not AGENT_KEY:
        return
    poll_url = HQ_AJAX_URL + "?action=scapi_roxy_agent_tunnel&op=poll"
    reply_url = HQ_AJAX_URL + "?action=scapi_roxy_agent_tunnel&op=reply"
    while True:
        try:
            req = urllib.request.Request(poll_url, headers={"X-Roxy-Agent-Key": AGENT_KEY, "User-Agent": "RoxyBridgeTunnel/3.6"})
            with urllib.request.urlopen(req, timeout=10) as resp:
                wrap = json.loads(resp.read().decode("utf-8"))
            tasks = (wrap.get("data") or {}).get("tasks") or []
            for t in tasks:
                tid = t.get("id", "")
                path = t.get("path", "")
                method = t.get("method", "GET")
                token = t.get("token", "")
                raw_b = t.get("body", "")
                b_bytes = raw_b.encode("utf-8") if raw_b else None
                _, r_bytes = dispatch_bridge_call(path, method, token, b_bytes)
                try:
                    roxy_json = json.loads(r_bytes.decode("utf-8", "replace"))
                except Exception:
                    roxy_json = {"code": 500, "msg": "Invalid JSON from Roxy"}
                rep_body = json.dumps({"id": tid, "roxy": roxy_json}).encode("utf-8")
                rep_req = urllib.request.Request(
                    reply_url, data=rep_body,
                    headers={"Content-Type": "application/json", "X-Roxy-Agent-Key": AGENT_KEY, "User-Agent": "RoxyBridgeTunnel/3.6"},
                    method="POST",
                )
                urllib.request.urlopen(rep_req, timeout=10).read()
        except Exception:
            pass
        time.sleep(0.8)


class BridgeHandler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def _cors(self):
        origin = self.headers.get("Origin") or "*"
        self.send_header("Access-Control-Allow-Origin", origin)
        self.send_header("Access-Control-Allow-Credentials", "true")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, OPTIONS")
        req_hdrs = self.headers.get("Access-Control-Request-Headers")
        self.send_header("Access-Control-Allow-Headers", req_hdrs if req_hdrs else "Content-Type, token, Token, Authorization")
        self.send_header("Access-Control-Allow-Private-Network", "true")

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def _forward(self):
        length = int(self.headers.get("Content-Length", "0") or "0")
        body = self.rfile.read(length) if length > 0 else None
        tok = self.headers.get("token") or self.headers.get("Token") or ""
        st, data = dispatch_bridge_call(self.path, self.command, tok, body)
        self.send_response(st)
        self._cors()
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.end_headers()
        self.wfile.write(data)

    do_GET = _forward
    do_POST = _forward


if __name__ == "__main__":
    threading.Thread(target=cloud_tunnel_loop, daemon=True).start()
    print(f"[Roxy 双模桥接助手 v3.6] 已启动：本地 127.0.0.1:{LISTEN_PORT} + Graph无痕验活 + HTTPS云穿透就绪！", flush=True)
    ThreadingHTTPServer(("127.0.0.1", LISTEN_PORT), BridgeHandler).serve_forever()
PY;
			return str_replace(
				array( '__HQ_AJAX_URL__', '__AGENT_KEY__' ),
				array( $hq_ajax, $agent_key ),
				$tpl
			);
		}

		public static function ajax_roxy_save_cfg(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => '权限不足' ), 403 );
			}
			check_ajax_referer( 'scapi_roxy_relay_nonce' );
			$url = isset( $_POST['roxy_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['roxy_url'] ) ) ) : 'http://127.0.0.1:50055';
			$key = isset( $_POST['roxy_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['roxy_key'] ) ) ) : '';
			if ( '' !== $key ) {
				update_option(
					'stealth_capi_roxy_saved_cfg',
					array(
						'url' => '' !== $url ? $url : 'http://127.0.0.1:50055',
						'key' => $key,
					),
					false
				);
			}
			wp_send_json_success( array( 'saved' => true ) );
		}

		public static function ajax_roxy_save_sop(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => '权限不足' ), 403 );
			}
			check_ajax_referer( 'scapi_roxy_relay_nonce' );

			$state = get_option( 'stealth_capi_roxy_sop_state', array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			$changed = false;

			// 1. 自动锚定窗口首次上号基准时间 & 已确认BM窗口的 72h 静养锁死起点（防止中途开窗点检冲掉 72h 倒计时）
			if ( ! empty( $_POST['auto_first_opens_json'] ) ) {
				$raw_auto = (string) wp_unslash( $_POST['auto_first_opens_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$auto_map = json_decode( $raw_auto, true );
				if ( is_array( $auto_map ) ) {
					foreach ( $auto_map as $did => $fts ) {
						$clean_did = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $did );
						$clean_fts = sanitize_text_field( (string) $fts );
						if ( '' !== $clean_did && '' !== $clean_fts ) {
							if ( ! isset( $state[ $clean_did ] ) || ! is_array( $state[ $clean_did ] ) ) {
								$state[ $clean_did ] = array();
							}
							if ( empty( $state[ $clean_did ]['first_open_time'] ) ) {
								$state[ $clean_did ]['first_open_time'] = $clean_fts;
								$changed                                = true;
							}
						}
					}
				}
			}
			if ( ! empty( $_POST['auto_bm_anchors_json'] ) ) {
				$raw_bm  = (string) wp_unslash( $_POST['auto_bm_anchors_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$bm_map  = json_decode( $raw_bm, true );
				if ( is_array( $bm_map ) ) {
					foreach ( $bm_map as $did => $bts ) {
						$clean_did = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $did );
						$clean_bts = sanitize_text_field( (string) $bts );
						if ( '' !== $clean_did && '' !== $clean_bts ) {
							if ( ! isset( $state[ $clean_did ] ) || ! is_array( $state[ $clean_did ] ) ) {
								$state[ $clean_did ] = array();
							}
							if ( empty( $state[ $clean_did ]['bm_bound_time'] ) ) {
								$state[ $clean_did ]['bm_bound_time'] = $clean_bts;
								$changed                              = true;
							}
						}
					}
				}
			}

			// 2. 手动点击校准单个窗口的个号存活、有无BM标记、72h接BM锁死起点、SOP 工序进度与自定义下次上号时间
			$dir_id = isset( $_POST['dir_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', sanitize_text_field( wp_unslash( $_POST['dir_id'] ) ) ) : '';
			if ( '' !== $dir_id ) {
				if ( ! isset( $state[ $dir_id ] ) || ! is_array( $state[ $dir_id ] ) ) {
					$state[ $dir_id ] = array();
				}
				$is_active_main = ! empty( $_POST['is_active_main'] ) && '1' === (string) $_POST['is_active_main'];
				if ( isset( $_POST['bm_id'] ) ) {
					$clean_bmid = preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['bm_id'] ) ) );
					if ( '' !== $clean_bmid && ! preg_match( '/^\d{6,24}$/', $clean_bmid ) ) {
						wp_send_json_error( array( 'message' => '绿盾 BMID 格式错误：请填写 6~24 位纯数字编号！' ), 400 );
					}
					if ( $is_active_main && '' === $clean_bmid ) {
						wp_send_json_error( array( 'message' => '提交拦截：在投主户必须填写【绿盾 BMID】！' ), 400 );
					}
					$state[ $dir_id ]['bm_id'] = $clean_bmid;
				}
				if ( isset( $_POST['alive_override'] ) ) {
					$state[ $dir_id ]['alive_override'] = sanitize_key( wp_unslash( $_POST['alive_override'] ) );
				}
				$bm_st = isset( $_POST['bm_status_override'] ) ? sanitize_key( wp_unslash( $_POST['bm_status_override'] ) ) : '';
				if ( in_array( $bm_st, array( 'auto', 'unknown', 'no_bm', 'has_bm_ok', 'bm_restricted' ), true ) ) {
					$state[ $dir_id ]['bm_status_override'] = $bm_st;
				}
				$stg = '';
				if ( isset( $_POST['stage_override'] ) ) {
					$stg                                = sanitize_text_field( wp_unslash( $_POST['stage_override'] ) );
					$state[ $dir_id ]['stage_override'] = $stg;
				}
				if ( isset( $_POST['bm_bound_time'] ) ) {
					$raw_bmt = trim( sanitize_text_field( wp_unslash( $_POST['bm_bound_time'] ) ) );
					if ( preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $raw_bmt ) ) {
						$norm_bmt = str_replace( 'T', ' ', $raw_bmt );
						if ( 16 === strlen( $norm_bmt ) ) {
							$norm_bmt .= ':00';
						}
						$state[ $dir_id ]['bm_bound_time'] = $norm_bmt;
					} elseif ( '' === $raw_bmt && ( 'no_bm' === $bm_st || ! empty( $_POST['clear_bm_time'] ) ) ) {
						$state[ $dir_id ]['bm_bound_time'] = '';
					}
				}
				if ( 'no_bm' === $bm_st && ! in_array( $stg, array( '4_warmup_bm', '5_ready_fire', '6_golden', '7_active' ), true ) ) {
					$state[ $dir_id ]['bm_bound_time'] = '';
				} elseif ( ( 'has_bm_ok' === $bm_st || '4_warmup_bm' === $stg ) && empty( $state[ $dir_id ]['bm_bound_time'] ) ) {
					$state[ $dir_id ]['bm_bound_time'] = gmdate( 'Y-m-d H:i:s', time() + 8 * 3600 );
				}
				if ( isset( $_POST['next_time_override'] ) ) {
					$state[ $dir_id ]['next_time_override'] = trim( sanitize_text_field( wp_unslash( $_POST['next_time_override'] ) ) );
				}
				$state[ $dir_id ]['updated_at'] = time();
				$changed                        = true;
			}

			if ( $changed ) {
				update_option( 'stealth_capi_roxy_sop_state', $state, false );
			}
			if ( ! empty( $_POST['probe_results_json'] ) ) {
				$raw_pr = (string) wp_unslash( $_POST['probe_results_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$pr_map = json_decode( $raw_pr, true );
				if ( is_array( $pr_map ) ) {
					$existing = get_option( Daily_Inspector::OPT_ROXY_PROBES, array() );
					$merged   = is_array( $existing['probes'] ?? null ) ? $existing['probes'] : array();
					foreach ( $pr_map as $did => $pinfo ) {
						if ( is_array( $pinfo ) ) {
							$merged[ (string) $did ] = $pinfo;
						}
					}
					update_option(
						Daily_Inspector::OPT_ROXY_PROBES,
						array(
							'probes'    => $merged,
							'synced_at' => time(),
						),
						false
					);
				}
			}
			wp_send_json_success( array( 'sop_state' => $state ) );
		}

		private static function render_tab_roxy( array $catalog ): void {
			$all_ov    = \StealthCapiBridge::fetch_network_slots_status();
			$raw_slots = $all_ov['gateway_slots']['slots'] ?? array();
			$gw_sites  = $all_ov['gateway_slots']['sites'] ?? array();
			$js_slots  = array();
			if ( is_array( $raw_slots ) ) {
				foreach ( $raw_slots as $s ) {
					$sk              = (string) ( $s['site_key'] ?? '' );
					$s['site_label'] = $catalog[ $sk ]['label'] ?? $sk;
					$js_slots[]      = $s;
				}
			}
			$zones_meta = array();
			foreach ( $catalog as $sk => $meta ) {
				if ( 'repurchase_hub_02' === $sk ) {
					continue;
				}
				$auto_dom     = self::auto_sync_buyer_domain( $sk );
				$gw_dom       = (string) ( $gw_sites[ $sk ]['primary_domain'] ?? '' );
				$zones_meta[] = array(
					'site_key'  => $sk,
					'zone_code' => $meta['zone_code'] ?? '',
					'buyer'     => $meta['buyer'] ?? $meta['label'],
					'label'     => $meta['label'],
					'domain'    => '' !== $auto_dom ? $auto_dom : $gw_dom,
				);
			}
			self::get_or_create_roxy_agent_key();
			$relay_cfg = array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'scapi_roxy_relay_nonce' ),
			);
			$saved_roxy = get_option( 'stealth_capi_roxy_saved_cfg', array() );
			if ( ! is_array( $saved_roxy ) ) {
				$saved_roxy = array();
			}
			$sop_state = get_option( 'stealth_capi_roxy_sop_state', array() );
			if ( ! is_array( $sop_state ) ) {
				$sop_state = array();
			}
			$probe_cache = get_option( Daily_Inspector::OPT_ROXY_PROBES, array() );
			if ( ! is_array( $probe_cache ) ) {
				$probe_cache = array();
			}
			?>
			<script>
				window.SCAPI_ZONES_CATALOG = <?php echo wp_json_encode( $zones_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
				window.SCAPI_ALL_SLOTS = <?php echo wp_json_encode( $js_slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
				window.SCAPI_ROXY_RELAY = <?php echo wp_json_encode( $relay_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
				window.SCAPI_ROXY_SAVED = <?php echo wp_json_encode( $saved_roxy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
				window.SCAPI_ROXY_SOP_STATE = <?php echo wp_json_encode( $sop_state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
				window.SCAPI_ROXY_PROBE_CACHE = <?php echo wp_json_encode( $probe_cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
			</script>
			<div class="scapi-card" id="scapi-roxy-root">
				<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
					<div>
						<h2 style="margin:0;">Roxy 战区编队作战台（主号 · 保号 · ⚡辅跑 · 热备｜自动识别「辅跑」项目一键下发独立主归属槽）</h2>
						<p style="margin:4px 0 0;color:#475569;font-size:13px;">自动读取 Roxy 的 <code>FBT编号</code>、<code>所属项目(在投/辅跑/保号/热备)</code> 与 <code>彩色标签(ss1~ss5)</code> 按战区聚合；<strong>「辅跑」项目窗口下发时自动避开 1 号主槽、默认绑定独立 <code>primary</code> 实时全漏斗槽位</strong>。</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="download_roxy_bridge" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<button type="submit" class="button">⬇ 下载本机 Roxy 跨域桥接小助手（备用）</button>
					</form>
				</div>

				<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
					<label><strong>本机 Roxy 接口：</strong> <input type="text" id="scapi-roxy-url" value="http://127.0.0.1:50055" style="width:185px;" /></label>
					<label><strong>API Key：</strong> <input type="password" id="scapi-roxy-key" placeholder="粘贴 RoxyBrowser API Key" style="width:190px;" /></label>
					<button type="button" id="scapi-roxy-connect" class="button button-primary">🔄 同步窗口与标签</button>
					<button type="button" id="scapi-roxy-probe-btn" class="button">⚡ 一键静默验活 (Graph无痕)</button>
					<div style="margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<input type="search" id="scapi-roxy-buyer-filter" placeholder="搜 FBT编号 / 投手 / 号商 / BMID / IP..." style="width:225px;" />
						<div class="scapi-view-toggle">
							<button type="button" id="scapi-view-cards-btn" class="active">战区编队卡</button>
							<button type="button" id="scapi-view-table-btn">紧凑全表</button>
						</div>
					</div>
				</div>

				<!-- 四大角色关系说明条 -->
				<div class="scapi-legend-grid">
					<div class="scapi-legend-item">
						<span class="scapi-role-tag main">🟢 主号</span>
						<div><strong>战区核心主帅（默认绑 <code>slot_01</code>）</strong><br/><span style="color:#64748b;">来自 <code>🚀在投项目</code> 或 <code>投放中</code> 标签；可跑主力广告，也可切为 <code>shadow</code> 当主像素保险箱</span></div>
					</div>
					<div class="scapi-legend-item">
						<span class="scapi-role-tag guard">🛡️ 保号</span>
						<div><strong>贴身守护同战区【主号】的同一个绿盾 BM</strong><br/><span style="color:#64748b;">来自 <code>🔞保号库</code> + <code>ss1~ss5</code>，共管主 BM 防孤儿资产，平时躺尸免占像素槽</span></div>
					</div>
					<div class="scapi-legend-item">
						<span class="scapi-role-tag aux">⚡ 辅跑</span>
						<div><strong>辅助主号冲量/测品的在投副户（自动绑独立 <code>slot_02/03</code>）</strong><br/><span style="color:#64748b;">来自 Roxy <code>辅跑</code> 项目（+ <code>ss1~ss5</code> 标签归属投手），一键下发为独立 <code>primary</code> 槽实时收全漏斗，绝不顶掉主号</span></div>
					</div>
					<div class="scapi-legend-item">
						<span class="scapi-role-tag hot">🔥 热备</span>
						<div><strong>静置养号替补池（平时静养免绑槽）</strong><br/><span style="color:#64748b;">来自 <code>🔥热备库</code> + <code>72h静置BM</code>，主号或辅跑阵亡时随时下发接替</span></div>
					</div>
				</div>

				<div id="scapi-roxy-status" style="display:none;"></div>
				<div id="scapi-roxy-workspaces" style="margin:8px 0;"></div>

				<!-- 五大战区齐装率总览筛选条 -->
				<div id="scapi-zone-summary-strip" class="scapi-zone-strip"></div>

				<!-- 默认视图：按战区分组作战卡 -->
				<div id="scapi-roxy-zones-board">
					<div style="color:#64748b;padding:18px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">正在自动连接本机 RoxyBrowser 同步战区编队、FBT 编号与彩色标签...</div>
				</div>

				<!-- 可切换视图：紧凑全表 -->
				<div id="scapi-roxy-table-wrap" class="scapi-dual-scroll" style="margin-top:10px;display:none;">
					<table class="scapi-table">
						<thead>
							<tr>
								<th>战区</th>
								<th>席位角色</th>
								<th>FBT编号</th>
								<th>完整窗口名称</th>
								<th>Roxy 原生标签</th>
								<th>谁保谁 / 谁备谁 / BMID</th>
								<th>号商与独享代理</th>
								<th>个号存活</th>
								<th>快捷操作</th>
							</tr>
						</thead>
						<tbody id="scapi-roxy-tbody"></tbody>
					</table>
				</div>

				<!-- 底部默认折叠：已挂 / 窗口转移淘汰收纳区 -->
				<div id="scapi-roxy-dead-drawer" class="scapi-dead-drawer" style="display:none;"></div>
			</div>

			<!-- 窗口 BMID 登记、存活状态与 SOP 工序节点校准弹窗 -->
			<div id="scapi-roxy-sop-modal" class="scapi-modal-backdrop">
				<div class="scapi-modal" style="max-width:560px;">
					<h2 style="margin-top:0;">登记绿盾 BMID · 校准窗口存活与静置进度</h2>
					<p id="scapi-sop-modal-title" style="color:#475569;font-size:13px;margin-top:-6px;"></p>
					<input type="hidden" id="scapi-sop-dir-id" value="" />
					<input type="hidden" id="scapi-sop-is-main" value="0" />
					<input type="hidden" id="scapi-sop-clear-bm-flag" value="0" />
					<p style="background:#eff6ff;padding:10px 12px;border-radius:8px;border:1px solid #bfdbfe;">
						<label><strong id="scapi-sop-bmid-label" style="color:#1e40af;">0. 绿盾 BM ID（在投主户必填 · 纯数字编号）：</strong><br />
							<input type="text" id="scapi-sop-bmid-input" class="large-text" style="margin-top:6px;font-family:monospace;" placeholder="请输入 15~16 位纯数字绿盾 BMID（下发至槽位时将自动带出）" />
						</label>
					</p>
					<p>
						<label><strong>1. FB 个人号存活状态：</strong><br />
							<select id="scapi-sop-alive-sel" class="large-text">
								<option value="auto">🤖 自动判定（按 Graph 无痕验活结果）</option>
								<option value="alive">🟢 正常存活（人工点检确认正常）</option>
								<option value="warn">🟡 验证风控 / 待解限（如遇 Checkpoint 待接码）</option>
								<option value="dead">🔴 个号已封禁阵亡（待移回收站停 IP）</option>
							</select>
						</label>
					</p>
					<p>
						<label><strong>2. 窗口内 BM 状态（有无 BM / 死活标记）：</strong><br />
							<select id="scapi-sop-bm-sel" class="large-text">
								<option value="auto">🤖 自动识别（按 Roxy 标签与槽位绑定自动识别）</option>
								<option value="unknown">❓ 未知待查（尚未确认里面有没有 BM）</option>
								<option value="no_bm">⚪ 纯个号·无BM</option>
								<option value="has_bm_ok">🟢 有BM·正常存活（锁死 72h 静养计时）</option>
								<option value="bm_restricted">🔴 BM已挂 / 受限红标（禁止下发投流）</option>
							</select>
						</label>
					</p>
					<p style="background:#f8fafc;padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;">
						<label><strong>3. 接管 BM 起算时间（🔒 锁死后中途开窗看一眼绝不清零 72h 倒计时）：</strong><br />
							<input type="datetime-local" id="scapi-sop-bm-time-input" style="width:215px;margin-top:6px;" />
							<button type="button" class="button button-small" id="scapi-sop-bm-use-open" style="margin-top:6px;margin-left:4px;">用上次开窗时间</button>
							<button type="button" class="button button-small" id="scapi-sop-bm-use-now" style="margin-top:6px;margin-left:4px;">设为刚接BM</button>
							<button type="button" class="button button-small" id="scapi-sop-bm-clear-btn" style="margin-top:6px;margin-left:4px;">清空</button>
						</label>
					</p>
					<p>
						<label><strong>4. 当前账号 SOP 进度：</strong><br />
							<select id="scapi-sop-stage-sel" class="large-text">
								<option value="auto">🤖 自动推算</option>
								<option value="1_wait_first">① 待人工首登验 IP</option>
								<option value="2_warmup_acct">② 养个号稳号期（48h）</option>
								<option value="3_wait_bm">③ 待接管绿盾 BM</option>
								<option value="4_warmup_bm">④ 养绿盾 BM 静置期（72h）</option>
								<option value="5_ready_fire">⑤ 满 72h 可点火</option>
								<option value="6_golden">⑥ 🟢 黄金热备就绪</option>
								<option value="7_active">⑦ 🔥 前线正式在投中</option>
							</select>
						</label>
					</p>
					<p>
						<label><strong>5. 指定下次应上号时间（可选，留空则自动计算）：</strong><br />
							<input type="datetime-local" id="scapi-sop-next-input" style="width:240px;" />
							<button type="button" class="button button-small" style="margin-left:6px;" onclick="document.getElementById('scapi-sop-next-input').value='';">恢复自动计算</button>
						</label>
					</p>
					<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
						<button type="button" class="button" onclick="document.getElementById('scapi-roxy-sop-modal').classList.remove('open');">取消</button>
						<button type="button" id="scapi-sop-save-btn" class="button button-primary">保存并立即刷新</button>
					</div>
				</div>
			</div>

			<!-- 从 Roxy 窗口一键下发分配至网关槽位弹窗 -->
			<div id="scapi-roxy-assign-modal" class="scapi-modal-backdrop">
				<div class="scapi-modal" style="max-width:700px;">
					<h2 style="margin-top:0;">将 Roxy 窗口一键下发至域名独立槽位（主归属 primary / 旁路养模 shadow）</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="replace_dead_slot" />
						<input type="hidden" name="return_tab" value="slots" />
						<input type="hidden" name="is_edit_existing" value="0" />
						<input type="hidden" name="strip_fbc_present" value="1" />
						<input type="hidden" name="is_active_present" value="1" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
							所属战区：<select name="site_key">
								<?php foreach ( $catalog as $sk => $meta ) : ?>
									<?php if ( 'repurchase_hub_02' === $sk ) { continue; } ?>
									<option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							目标槽位：<select name="slot_id">
								<option value="slot_01">1号槽 (slot_01)</option>
								<option value="slot_02">2号槽 (slot_02)</option>
								<option value="slot_03">3号槽 (slot_03)</option>
								<option value="slot_04">4号槽 (slot_04)</option>
							</select>
							角色：<select name="slot_role" onchange="window.StealthHubUI && StealthHubUI.syncSlotRoleDefaults(this.form, true);"><option value="primary">🟢 主归属槽 (primary)</option><option value="shadow">🛡️ 旁路养模槽 (shadow)</option></select>
						</p>
						<p>
							<label><strong class="scapi-bmid-req-label" style="color:#334155;">BM ID 备注（选填 · 外部邮箱户/二三不限可留空）：</strong><br />
								<input type="text" name="bm_id" class="large-text" placeholder="选填：6~24 位纯数字 BM 编号" />
							</label>
						</p>
						<p><label>Roxy 编号与窗口备注（已自动带出）：<br /><input type="text" name="bm_label" class="large-text" required /></label></p>
						<p><label>该窗口绑定的独享静态代理（已自动从 Roxy 提取锁死防连坐）：<br /><input type="text" name="egress_proxy" class="large-text" readonly required /></label></p>
						<p><label>Pixel / Dataset ID（必填）：<br /><input type="text" name="dataset_id" class="large-text" placeholder="15~16 位纯数字像素编号" required /></label></p>
						<p><label>访问令牌（Access Token）：<br /><input type="password" name="access_token" class="large-text" placeholder="EAA... 访问令牌" required /></label></p>
						<div>
							<strong>转发事件白名单：</strong>
							<div class="scapi-ev-checks">
								<?php foreach ( array( 'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase' ) as $ev_r ) : ?>
									<label><input type="checkbox" name="enabled_events[]" value="<?php echo esc_attr( $ev_r ); ?>" checked /> <?php echo esc_html( $ev_r ); ?></label>
								<?php endforeach; ?>
							</div>
						</div>
						<p style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:10px;">
							<label style="font-weight:600;color:#5b21b6;"><input type="checkbox" name="strip_fbc" value="1" /> 强制剥离 <code>fbc</code></label>
							<span>错峰延迟：<input type="number" name="delay_min_sec" value="0" min="0" style="width:62px;" /> ～ <input type="number" name="delay_max_sec" value="0" min="0" style="width:62px;" /> 秒</span>
							<label><input type="checkbox" name="is_active" value="1" checked /> 立即启用</label>
						</p>
						<p>
							<label>可选独立测试事件码：<input type="text" name="test_event_code" style="width:170px;margin-left:6px;" placeholder="正式模式请留空" /></label>
							&nbsp; <label><input type="checkbox" name="trigger_backfill" value="1" /> 回填过去 6.5 天成交（默认关闭）</label>
						</p>
						<div style="display:flex;justify-content:flex-end;gap:10px;">
							<button type="button" class="button" onclick="document.getElementById('scapi-roxy-assign-modal').classList.remove('open');">取消</button>
							<button type="submit" class="button button-primary">确认下发至网关槽位</button>
						</div>
					</form>
				</div>
			</div>
			<?php
		}

		private static function render_tab_audience( array $catalog ): void {
			?>
			<div class="scapi-card">
				<h2 style="margin-top:0;">高价值批发经销商（累计消费满 600 美元）随机抽样洗牌名单导出</h2>
				<p style="color:#475569;font-size:13px;">由网关实时执行 <strong>90% 至 95% 随机分层抽样与行序洗牌</strong>，确保每次下载的表格文件特征值与行数均不相同，彻底斩断不同企业号上传同源种子人群包的关联风控。</p>
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
									<option value="ALL">全网汇总客户池</option>
									<?php foreach ( $catalog as $sk => $meta ) : ?>
										<?php if ( 'repurchase_hub_02' === $sk ) { continue; } ?>
										<option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th>累计消费门槛与筛选</th>
							<td><input type="number" name="min_ltv_usd" value="600" style="width:110px;" /> 美元 &nbsp; <label><input type="checkbox" name="vip_only" value="1" checked /> 仅导出累计满 600 美元高价值批发客户</label></td>
						</tr>
						<tr>
							<th>随机抽样比例（%）</th>
							<td><input type="number" name="sample_ratio_pct" value="92" min="80" max="95" style="width:90px;" /> % <span class="description">（防关联推荐范围：90% 至 95%）</span></td>
						</tr>
					</table>
					<?php submit_button( '一键随机抽样洗牌并下载经销商名单表格' ); ?>
				</form>
			</div>
			<?php
		}

		private static function format_ts_chinese( int $ts ): string {
			if ( $ts <= 0 ) {
				return '暂无记录';
			}
			return gmdate( 'Y年m月d日 H:i:s', $ts + 8 * 3600 ) . '（北京时间）';
		}

		private static function render_tab_settings(): void {
			$cfg        = Config_Manager::get_config();
			$dt         = $cfg['default_tenant'] ?? array();
			$health     = Reconcile_Cron::get_local_health();
			$mode       = ! empty( $dt['is_repurchase_station'] ) ? Config_Manager::MODE_ORGANIC_RETENTION_HUB : (string) ( $dt['tenant_mode'] ?? Config_Manager::MODE_AD_ACQUISITION );
			$queue_cnt  = (int) ( $health['retry_queue_depth'] ?? 0 );
			$last_cash  = (int) ( $health['last_cashier_purchase_at'] ?? 0 );
			$next_sched = (int) ( $health['next_scheduled_at'] ?? 0 );
			$last_rec   = is_array( $health['last_reconciler'] ?? null ) ? $health['last_reconciler'] : array();
			$last_rec_t = (int) ( $last_rec['last_run_at'] ?? $last_rec['ran_at'] ?? $last_rec['timestamp'] ?? 0 );
			?>
			<div class="scapi-grid-2">
				<div class="scapi-card">
					<h2 style="margin-top:0;">站点连接器与运行模式配置</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="save_plugin_config" />
						<input type="hidden" name="return_tab" value="settings" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> /> <strong>启用本节点隐身回传连接器</strong></label></p>
						<p><label>网关内网服务地址：<br /><input type="url" class="large-text" name="gateway_base_url" value="<?php echo esc_attr( (string) $cfg['gateway_base_url'] ); ?>" /></label></p>
						<p><label>网关总控管理密钥：<br /><input type="password" class="large-text" name="admin_master_key" value="<?php echo esc_attr( (string) $cfg['admin_master_key'] ); ?>" /></label></p>
						<p>
							<label>站点运行模式：<br />
								<select name="tenant_mode">
									<option value="<?php echo esc_attr( Config_Manager::MODE_AD_ACQUISITION ); ?>" <?php selected( Config_Manager::MODE_AD_ACQUISITION, $mode ); ?>>广告投流拉新站（前台自动采集全漏斗事件并实时回传像素）</option>
									<option value="<?php echo esc_attr( Config_Manager::MODE_ORGANIC_RETENTION_HUB ); ?>" <?php selected( Config_Manager::MODE_ORGANIC_RETENTION_HUB, $mode ); ?>>私域老客纯复购站（前台零像素零脚本 · 仅后台将复购单反哺原获客店）</option>
								</select>
							</label>
						</p>
						<p>站点序号：<input type="number" name="tenant_blog_id" value="<?php echo esc_attr( (string) ( $dt['blog_id'] ?? 1 ) ); ?>" style="width:75px;" /> 店铺代号：<input type="text" name="tenant_store_code" value="<?php echo esc_attr( (string) ( $dt['store_code'] ?? '' ) ); ?>" style="width:95px;" /> 站点标识：<input type="text" name="tenant_site_key" value="<?php echo esc_attr( (string) ( $dt['site_key'] ?? '' ) ); ?>" style="width:110px;" /></p>
						<p><label>站点通信签名密钥：<br /><input type="password" class="large-text" name="tenant_api_secret" value="<?php echo esc_attr( (string) ( $dt['api_secret'] ?? '' ) ); ?>" /></label></p>
						<p><label><input type="checkbox" name="suppress_legacy_meta" value="1" <?php checked( ! empty( $cfg['suppress_legacy_meta'] ) ); ?> /> 自动屏蔽旧版明文广告像素防重复上报（完整保留统计与短视频像素正常运作）</label></p>
						<p><label><input type="checkbox" name="auto_enqueue_sdk" value="1" <?php checked( ! empty( $cfg['auto_enqueue_sdk'] ) ); ?> /> 自动在前台注入轻量采集脚本（体积小于三千字节）</label></p>
						<p><label><input type="checkbox" name="enable_server_shallow" value="1" <?php checked( ! empty( $cfg['enable_server_shallow'] ) ); ?> /> 启用服务端账号注册与加购行为兜底采集</label></p>
						<p><label>可信反向代理网段（多个网段用英文逗号分隔）：<br /><input type="text" class="large-text" name="trusted_proxies" value="<?php echo esc_attr( implode( ',', (array) ( $cfg['trusted_proxies'] ?? array() ) ) ); ?>" /></label></p>
						<p><label><input type="checkbox" name="trust_private_proxies" value="1" <?php checked( ! empty( $cfg['trust_private_proxies'] ) ); ?> /> 信任本地及内网私有代理网段</label></p>
						<p><label>多站点子站映射配置（单站点部署无需填写）：<br /><textarea name="tenants_by_blog_id_json" rows="3" class="large-text code"><?php echo esc_textarea( wp_json_encode( $cfg['tenants_by_blog_id'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea></label></p>
						<?php submit_button( '保存节点配置' ); ?>
					</form>
				</div>

				<div class="scapi-card">
					<?php if ( class_exists( '\PeptideHq\Sites' ) ) : ?>
						<?php
						$hq_sites       = \PeptideHq\Sites::all();
						$bound_map      = get_option( 'stealth_capi_hq_bound_stores', array() );
						$stored_secrets = get_option( 'stealth_capi_hq_site_secrets', array() );
						$cat_list       = Config_Manager::get_stores_catalog();
						?>
						<h2 style="margin-top:0;">六大线上店铺一键启用 / 待命控制中心（免进单店后台）</h2>
						<p style="color:#475569;font-size:13px;margin-top:0;">所有单店通信密钥已在总后台安全预置。处于「待命未启用」时绝不影响店铺当前在跑旧像素；待新域名与绿盾企业号就绪后，点「一键启用新回传」即可秒级切流。</p>
						<?php if ( ! empty( $hq_sites ) && is_array( $stored_secrets ) && ! empty( $stored_secrets ) ) : ?>
							<table class="scapi-table" style="margin-bottom:18px;">
								<thead>
									<tr>
										<th>线上店铺</th>
										<th>绑定网关席位</th>
										<th>当前状态</th>
										<th>快捷操作</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $hq_sites as $sid => $srow ) : ?>
										<?php
										$b_sk = (string) ( $bound_map[ $sid ]['site_key'] ?? '' );
										if ( '' === $b_sk ) {
											$m_url = (string) ( $srow['management_url'] ?? '' );
											foreach ( $cat_list as $ck => $cm ) {
												if ( ! empty( $cm['store_code'] ) && false !== strpos( $m_url, $cm['store_code'] ) ) {
													$b_sk = $ck;
													break;
												}
											}
										}
										if ( '' === $b_sk ) {
											$b_sk = 'site_01';
										}
										$is_on = ! empty( $bound_map[ $sid ]['enabled'] );
										?>
										<tr>
											<td><strong><?php echo esc_html( $srow['name'] ?: $srow['public_url'] ); ?></strong></td>
											<td><?php echo esc_html( $cat_list[ $b_sk ]['label'] ?? $b_sk ); ?></td>
											<td>
												<?php if ( $is_on ) : ?>
													<span class="scapi-badge ok"><span class="scapi-dot green"></span>已启用新回传</span>
												<?php else : ?>
													<span class="scapi-badge warn"><span class="scapi-dot yellow"></span>待命未启用（旧像素照常运行）</span>
												<?php endif; ?>
											</td>
											<td>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
													<input type="hidden" name="action" value="pchq_capi" />
													<input type="hidden" name="capi_sub_action" value="hq_push_to_store" />
													<input type="hidden" name="return_tab" value="settings" />
													<input type="hidden" name="hq_target_site_id" value="<?php echo esc_attr( (string) $sid ); ?>" />
													<input type="hidden" name="bind_site_key" value="<?php echo esc_attr( $b_sk ); ?>" />
													<input type="hidden" name="store_enable_flag" value="<?php echo $is_on ? '0' : '1'; ?>" />
													<input type="hidden" name="trigger_local_backfill" value="0" />
													<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
													<?php if ( $is_on ) : ?>
														<button type="submit" class="button button-small">切回待命</button>
													<?php else : ?>
														<button type="submit" class="button button-small button-primary">一键启用新回传（仅实时新单）</button>
													<?php endif; ?>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
						<details style="margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #e2e8f0;">
							<summary style="cursor:pointer;font-weight:600;color:#334155;">展开新开商城首次接入网关配对（第 7 店及后续扩店专用 · 各店数据独立隔离）</summary>
							<?php if ( ! empty( $hq_sites ) ) : ?>
								<p style="color:#64748b;font-size:12px;margin:8px 0 10px;">仅用于将新开设的独立商城站点与网关席位建立加密通信连接；各店铺订单与客户严格物理隔离，绝不会把老店客户数据串入新店。</p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
									<input type="hidden" name="action" value="pchq_capi" />
									<input type="hidden" name="capi_sub_action" value="hq_push_to_store" />
									<input type="hidden" name="return_tab" value="settings" />
									<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
									<p>
										<label>选择待接入的商城站点：<br />
											<select name="hq_target_site_id" class="regular-text" required>
												<?php foreach ( $hq_sites as $sid => $srow ) : ?>
													<option value="<?php echo esc_attr( (string) $sid ); ?>"><?php echo esc_html( $srow['name'] ?: $srow['public_url'] ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									</p>
									<p>
										<label>分配网关专属席位：<br />
											<select name="bind_site_key" class="regular-text">
												<?php foreach ( $cat_list as $sk => $meta ) : ?>
													<option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									</p>
									<p><label>网关通信签名密钥（留空自动使用系统预置密钥）：<br /><input type="password" name="bind_api_secret" class="large-text" placeholder="留空自动使用预置密钥" /></label></p>
									<p><label><input type="checkbox" name="trigger_local_backfill" value="1" /> 配对后顺带补报「本商城自身」历史漏报订单（默认关闭，仅在显式开启历史补发策略时生效）</label></p>
									<?php submit_button( '完成配对并启用本店回传', 'secondary', 'submit', false ); ?>
								</form>
							<?php endif; ?>
						</details>
					<?php endif; ?>

					<h2 style="margin-top:0;">十分钟双向差额核账与历史订单补推</h2>
					<table class="scapi-table" style="margin-bottom:16px;">
						<tbody>
							<tr>
								<th style="width:42%;">本地待重试补偿队列</th>
								<td><strong><?php echo esc_html( (string) $queue_cnt ); ?></strong> 笔</td>
							</tr>
							<tr>
								<th>最近一笔外部收银台成交回传</th>
								<td><?php echo esc_html( self::format_ts_chinese( $last_cash ) ); ?></td>
							</tr>
							<tr>
								<th>最近一次自动双向核账执行</th>
								<td><?php echo esc_html( self::format_ts_chinese( $last_rec_t ) ); ?></td>
							</tr>
							<tr>
								<th>下次计划自动核账时间</th>
								<td><?php echo esc_html( self::format_ts_chinese( $next_sched ) ); ?></td>
							</tr>
						</tbody>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="reconcile_now" />
						<input type="hidden" name="return_tab" value="settings" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<?php submit_button( '立即向网关发起双向差额核账与漏单补推', 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="backfill_local_wc" />
						<input type="hidden" name="return_tab" value="settings" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						回溯天数：<input type="number" name="backfill_days" value="7" min="1" max="7" style="width:65px;" /> 天 &nbsp;
						<?php submit_button( '补推本店本地商城历史已付订单', 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>
			<?php
		}

		private static function render_tab_daily_check( string $base_page ): void {
			Daily_Inspector::ensure_daily_cron_scheduled();
			$history = Daily_Inspector::get_reports_history();
			if ( empty( $history ) ) {
				$latest  = Daily_Inspector::run_full_inspection( 'first_open' );
				$history = array( $latest );
			} else {
				$latest = $history[0];
			}

			$next_auto  = (int) wp_next_scheduled( Daily_Inspector::CRON_HOOK );
			$wh_cfg     = Daily_Inspector::get_webhook_config();
			$overall    = (string) ( $latest['overall'] ?? 'pass' );
			$ran_at     = (int) ( $latest['ran_at'] ?? 0 );
			$fail_cnt   = (int) ( $latest['fail_count'] ?? 0 );
			$warn_cnt   = (int) ( $latest['warn_count'] ?? 0 );
			$stores_rep = is_array( $latest['stores'] ?? null ) ? $latest['stores'] : array();
			$standby_st = is_array( $latest['standby_stores'] ?? null ) ? $latest['standby_stores'] : array();
			$roxy_sum   = is_array( $latest['roxy_summary'] ?? null ) ? $latest['roxy_summary'] : array();

			$banner_cls = 'fail' === $overall ? 'err' : ( 'warn' === $overall ? 'warn' : 'ok' );
			$banner_txt = 'fail' === $overall
				? '🔴 发现 ' . $fail_cnt . ' 项高危阻断隐患（' . $warn_cnt . ' 项提醒）—— 请排雷修复后再发布或修改广告！'
				: ( 'warn' === $overall
					? '🟡 核心防泄密与斗篷全部通过，另有 ' . $warn_cnt . ' 项待办提醒（详见下方明细）'
					: '🟢 今日全网 6+1 大安全体检全部绿灯达标 —— 准许投手安心上机开投！' );
			?>
			<div class="scapi-card">
				<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
					<div>
						<h2 style="margin:0;">全网每日开投前安全与健康巡检中心（自动日检时间：每日北京时间 15:30）</h2>
						<p style="margin:4px 0 0;color:#475569;font-size:13px;">
							最近巡检：<strong><?php echo esc_html( self::format_ts_chinese( $ran_at ) ); ?></strong>
							&nbsp;｜&nbsp; 下次自动巡检：<strong><?php echo esc_html( self::format_ts_chinese( $next_auto ) ); ?></strong>（卡在投手 16:00 开班前 30 分钟自动执行）
						</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="run_daily_inspection" />
						<input type="hidden" name="return_tab" value="daily_check" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<button type="submit" class="button button-primary button-hero" style="min-height:38px;line-height:36px;padding:0 18px;">🔍 立即一键执行全网真机日检</button>
					</form>
				</div>

				<div class="scapi-diag-box <?php echo esc_attr( $banner_cls ); ?>" style="margin-top:14px;font-size:14px;">
					<strong><?php echo esc_html( $banner_txt ); ?></strong>
					<span style="margin-left:14px;font-size:12px;">
						［独立网关健康：<?php echo ! empty( $latest['gateway_ready'] ) ? '✅ 200 就绪' : '❌ 未响应'; ?> ｜ 总控机 FB 物理断网黑洞：<?php echo ! empty( $latest['hq_blackhole'] ) ? '✅ 已锁死' : '❌ 缺失'; ?>］
					</span>
				</div>
			</div>

			<!-- 全局第 7 项：Roxy 指纹窗口 Graph 无痕存活与防撞 IP 汇总 -->
			<div class="scapi-card">
				<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
					<div>
						<h3 style="margin:0;">Roxy 养号池与在投窗 Graph 公开端点无痕验活汇总</h3>
						<p style="margin:4px 0 0;color:#475569;font-size:13px;"><?php echo esc_html( (string) ( $roxy_sum['detail'] ?? '暂无记录' ) ); ?></p>
					</div>
					<div>
						<?php
						$rx_st  = (string) ( $roxy_sum['status'] ?? 'warn' );
						$rx_cls = 'fail' === $rx_st ? 'err' : ( 'warn' === $rx_st ? 'warn' : 'ok' );
						$rx_lbl = 'fail' === $rx_st ? '🔴 存在封号需换号' : ( 'warn' === $rx_st ? '🟡 存在待处理提醒' : '🟢 全池存活正常' );
						?>
						<span class="scapi-badge <?php echo esc_attr( $rx_cls ); ?>" style="font-size:13px;padding:5px 12px;"><?php echo esc_html( $rx_lbl ); ?></span>
						<a class="button" style="margin-left:8px;" href="<?php echo esc_url( add_query_arg( 'tab', 'roxy', $base_page ) ); ?>">查看第 5 页 Roxy 明细 →</a>
					</div>
				</div>
			</div>

			<!-- 各已启用在投店铺 6 大真机检查矩阵 -->
			<?php foreach ( $stores_rep as $st_rep ) : ?>
				<?php
				$st_ov  = (string) ( $st_rep['overall'] ?? 'pass' );
				$st_cls = 'fail' === $st_ov ? 'err' : ( 'warn' === $st_ov ? 'warn' : 'ok' );
				$st_txt = 'fail' === $st_ov ? '❌ 存在阻断项' : ( 'warn' === $st_ov ? '⚠️ 待办提醒' : '✅ 6 项全绿通过' );
				?>
				<div class="scapi-card">
					<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
						<h3 style="margin:0;">
							<?php echo esc_html( (string) $st_rep['label'] ); ?>
							<code style="margin-left:8px;font-size:13px;"><?php echo esc_html( (string) $st_rep['domain'] ); ?></code>
						</h3>
						<span class="scapi-badge <?php echo esc_attr( $st_cls ); ?>"><?php echo esc_html( $st_txt ); ?></span>
					</div>
					<table class="scapi-table">
						<thead>
							<tr>
								<th style="width:28%;">安全与健康巡检模块</th>
								<th style="width:12%;">状态结论</th>
								<th>真机实测详情与处置指引</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( (array) ( $st_rep['checks'] ?? array() ) as $chk ) : ?>
								<?php
								$c_st  = (string) ( $chk['status'] ?? 'pass' );
								$c_cls = 'fail' === $c_st ? 'err' : ( 'warn' === $c_st ? 'warn' : 'ok' );
								$c_lbl = 'fail' === $c_st ? '❌ 阻断' : ( 'warn' === $c_st ? '⚠️ 预警' : '✅ 通过' );
								?>
								<tr style="<?php echo 'fail' === $c_st ? 'background:#fef2f2;' : ''; ?>">
									<td><strong><?php echo esc_html( (string) $chk['title'] ); ?></strong></td>
									<td><span class="scapi-badge <?php echo esc_attr( $c_cls ); ?>"><?php echo esc_html( $c_lbl ); ?></span></td>
									<td><?php echo esc_html( (string) $chk['detail'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>

			<?php if ( ! empty( $standby_st ) ) : ?>
				<div class="scapi-card" style="background:#f8fafc;">
					<strong>当前处于「待命停投」暂未纳入日检的站点：</strong>
					<?php
					$sb_items = array();
					foreach ( $standby_st as $sbs ) {
						$sb_items[] = $sbs['label'] . ' (' . $sbs['domain'] . ')';
					}
					echo esc_html( implode( '　｜　', $sb_items ) );
					?>
				</div>
			<?php endif; ?>

			<div class="scapi-grid-2">
				<!-- 机器人告警推送设置 -->
				<div class="scapi-card">
					<h3 style="margin-top:0;">每日 15:30 自动巡检机器人告警推送（可选）</h3>
					<p style="color:#475569;font-size:12px;margin-top:0;">支持直接粘贴飞书群机器人、企业微信群机器人或通用 Webhook 地址，每天 15:30 巡检完毕自动推送结果。</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_capi" />
						<input type="hidden" name="capi_sub_action" value="save_daily_webhook" />
						<input type="hidden" name="return_tab" value="daily_check" />
						<?php wp_nonce_field( 'pchq_capi_action_nonce' ); ?>
						<p>
							<label><strong>机器人 Webhook 地址：</strong><br />
								<input type="url" name="webhook_url" class="large-text" value="<?php echo esc_attr( (string) ( $wh_cfg['webhook_url'] ?? '' ) ); ?>" placeholder="https://open.feishu.cn/open-apis/bot/v2/hook/..." />
							</label>
						</p>
						<p>
							<label><input type="checkbox" name="only_on_alert" value="1" <?php checked( ! empty( $wh_cfg['only_on_alert'] ) ); ?> /> 仅在检出「阻断或预警」时推送消息（不勾选则每天 15:30 全绿也发平安简报）</label>
						</p>
						<div style="display:flex;gap:10px;">
							<button type="submit" class="button button-primary">保存推送设置</button>
							<button type="submit" name="send_test_push" value="1" class="button">保存并立即发一条测试消息</button>
						</div>
					</form>
				</div>

				<!-- 最近 30 次巡检历史留档 -->
				<div class="scapi-card">
					<h3 style="margin-top:0;">最近巡检历史留痕（保留最近 30 次）</h3>
					<div class="scapi-dual-scroll" style="max-height:220px;">
						<table class="scapi-table">
							<thead>
								<tr>
									<th>巡检时间</th>
									<th>触发方式</th>
									<th>体检结论</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $history as $h_row ) : ?>
									<?php
									$h_ov  = (string) ( $h_row['overall'] ?? 'pass' );
									$h_cls = 'fail' === $h_ov ? 'err' : ( 'warn' === $h_ov ? 'warn' : 'ok' );
									$h_lbl = 'fail' === $h_ov
										? '🔴 阻断 ' . (int) ( $h_row['fail_count'] ?? 0 ) . ' 项'
										: ( 'warn' === $h_ov ? '🟡 预警 ' . (int) ( $h_row['warn_count'] ?? 0 ) . ' 项' : '🟢 全绿通过' );
									$trig  = 'cron_1530' === ( $h_row['trigger'] ?? '' ) ? '15:30 自动日检' : '人工一键体检';
									?>
									<tr>
										<td><?php echo esc_html( self::format_ts_chinese( (int) ( $h_row['ran_at'] ?? 0 ) ) ); ?></td>
										<td><?php echo esc_html( $trig ); ?></td>
										<td><span class="scapi-badge <?php echo esc_attr( $h_cls ); ?>"><?php echo esc_html( $h_lbl ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<?php
		}
	}
}

// 全局门面 `\StealthCapiBridge` 与总控菜单挂载类 `\PeptideHq\Admin\Capi_Bridge_Page`
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

			public static function promote_slot( string $site_key, string $slot_id, bool $force_override = false, string $bm_id = '' ): array {
				$clean_bm = preg_replace( '/\s+/', '', $bm_id );
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/promote-slot',
					array(
						'site_key'       => trim( $site_key ),
						'slot_id'        => trim( $slot_id ),
						'force_override' => $force_override,
						'bm_id'          => '' !== $clean_bm ? $clean_bm : null,
					),
					5.0
				);
			}

			public static function replace_dead_slot( array $slot_data ): array {
				$proxy     = trim( (string) ( $slot_data['egress_proxy'] ?? $slot_data['proxy_url'] ?? '' ) );
				$ev_domain = strtolower( trim( (string) ( $slot_data['event_domain'] ?? '' ) ) );
				$ev_domain = preg_replace( '#^https?://#i', '', $ev_domain );
				$ev_domain = trim( (string) $ev_domain, '/' );
				$test_code = trim( (string) ( $slot_data['test_event_code'] ?? '' ) );
				$role_raw  = strtolower( trim( (string) ( $slot_data['slot_role'] ?? '' ) ) );
				$norm_role = null;
				if ( in_array( $role_raw, array( 'primary' ), true ) ) {
					$norm_role = 'primary';
				} elseif ( in_array( $role_raw, array( 'shadow', 'standby' ), true ) ) {
					$norm_role = 'shadow';
				}
				$d_min_sec = isset( $slot_data['delay_min_sec'] )
					? max( 0, (int) $slot_data['delay_min_sec'] )
					: ( isset( $slot_data['min_jitter_sec'] ) ? max( 0, (int) $slot_data['min_jitter_sec'] ) : ( 'shadow' === $norm_role ? 15 : 0 ) );
				$d_max_sec = isset( $slot_data['delay_max_sec'] )
					? max( $d_min_sec, (int) $slot_data['delay_max_sec'] )
					: ( isset( $slot_data['max_jitter_sec'] ) ? max( $d_min_sec, (int) $slot_data['max_jitter_sec'] ) : ( 'shadow' === $norm_role ? 60 : 0 ) );
				$is_edit   = ! empty( $slot_data['is_edit_existing'] );
				$ds_clean  = preg_replace( '/\s+/', '', (string) ( $slot_data['dataset_id'] ?? '' ) );
				$bm_clean  = preg_replace( '/\s+/', '', (string) ( $slot_data['bm_id'] ?? '' ) );
				$tok_clean = trim( (string) ( $slot_data['access_token'] ?? '' ) );
				if ( '' !== $bm_clean && ! preg_match( '/^\d{6,24}$/', $bm_clean ) ) {
					return array( 'ok' => false, 'status' => 422, 'error' => 'invalid_bm_id_format', 'data' => array() );
				}
				$en_evs = null;
				if ( isset( $slot_data['enabled_events'] ) && is_array( $slot_data['enabled_events'] ) && ! empty( $slot_data['enabled_events'] ) ) {
					$en_evs = array_values( array_filter( array_map( 'trim', $slot_data['enabled_events'] ) ) );
				}
				$payload = array(
					'site_key'              => trim( (string) ( $slot_data['site_key'] ?? '' ) ),
					'slot_id'               => trim( (string) ( $slot_data['slot_id'] ?? 'slot_01' ) ),
					'slot_role'             => $norm_role,
					'bm_id'                 => '' !== $bm_clean ? $bm_clean : null,
					'bm_label'              => sanitize_text_field( (string) ( $slot_data['bm_label'] ?? '' ) ),
					'dataset_id'            => '' !== $ds_clean ? $ds_clean : null,
					'access_token'          => '' !== $tok_clean ? $tok_clean : null,
					'egress_proxy'          => '' !== $proxy ? $proxy : null,
					'proxy_url'             => '' !== $proxy ? $proxy : null,
					'event_domain'          => '' !== $ev_domain ? $ev_domain : null,
					'test_event_code'       => '' !== $test_code ? $test_code : null,
					'clear_test_event_code' => ! empty( $slot_data['clear_test_event_code'] ),
					'enabled_events'        => $en_evs,
					'strip_fbc'             => isset( $slot_data['strip_fbc'] ) ? (bool) $slot_data['strip_fbc'] : null,
					'delay_min_sec'         => $d_min_sec,
					'delay_max_sec'         => $d_max_sec,
					'is_active'             => isset( $slot_data['is_active'] ) ? (bool) $slot_data['is_active'] : null,
					'trigger_backfill'      => ! empty( $slot_data['trigger_backfill'] ),
				);
				if ( '' === $payload['site_key'] || null === $payload['dataset_id'] || ( ! $is_edit && null === $payload['access_token'] ) ) {
					return array( 'ok' => false, 'status' => 400, 'error' => 'missing_required_bm_slot_fields', 'data' => array() );
				}
				return \StealthCapiBridge\Config_Manager::gateway_admin_request( 'POST', '/v3/admin/replace-dead-slot', $payload, 8.0 );
			}

			public static function toggle_slot( string $site_key, string $slot_id, bool $is_active ): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/toggle-slot',
					array(
						'site_key'  => trim( $site_key ),
						'slot_id'   => trim( $slot_id ),
						'is_active' => $is_active,
					),
					5.0
				);
			}

			public static function delete_slot( string $site_key, string $slot_id ): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/delete-slot',
					array(
						'site_key' => trim( $site_key ),
						'slot_id'  => trim( $slot_id ),
					),
					5.0
				);
			}

			public static function clear_test_data( string $site_key = '', bool $also_clear_test_code = false ): array {
				$clean_sk = trim( $site_key );
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/clear-test-data',
					array(
						'site_key'             => '' !== $clean_sk ? $clean_sk : null,
						'also_clear_test_code' => $also_clear_test_code,
					),
					8.0
				);
			}

			public static function update_tenant_domains( string $site_key, string $primary_domain, array $extra_domains = array() ): array {
				$clean_primary = strtolower( trim( $primary_domain ) );
				$clean_primary = preg_replace( '#^https?://#i', '', $clean_primary );
				$clean_primary = trim( (string) $clean_primary, '/' );
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/tenant-domains',
					array(
						'site_key'       => trim( $site_key ),
						'primary_domain' => $clean_primary,
						'extra_domains'  => array_values( array_filter( array_map( 'trim', $extra_domains ) ) ),
					),
					5.0
				);
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

			public static function fetch_sanitizer_dictionary( ?string $site_key = null, bool $only_site = false ): array {
				$path = '/v3/admin/dictionary';
				$args = array();
				if ( null !== $site_key && '' !== trim( $site_key ) ) {
					$args['site_key'] = trim( $site_key );
				}
				if ( $only_site ) {
					$args['only_site'] = 'true';
				}
				if ( ! empty( $args ) ) {
					$path .= '?' . http_build_query( $args );
				}
				return \StealthCapiBridge\Config_Manager::gateway_admin_request( 'GET', $path, null, 4.0 );
			}

			public static function upsert_dictionary_entry( array $entry ): array {
				if ( isset( $entry['clean_code'] ) && empty( $entry['safe_catalog_id'] ) ) {
					$entry['safe_catalog_id'] = $entry['clean_code'];
				}
				if ( empty( $entry['site_key'] ) ) {
					$entry['site_key'] = '__GLOBAL__';
				}
				return \StealthCapiBridge\Config_Manager::gateway_admin_request( 'POST', '/v3/admin/dictionary/upsert', $entry, 4.0 );
			}

			public static function bulk_upsert_dictionary_entries( string $site_key, array $entries, bool $replace_site = true ): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/dictionary/bulk-upsert',
					array(
						'site_key'     => '' !== trim( $site_key ) ? trim( $site_key ) : '__GLOBAL__',
						'replace_site' => $replace_site,
						'entries'      => array_values( $entries ),
					),
					12.0
				);
			}

			public static function delete_dictionary_entry( string $raw_sku, string $site_key = '__GLOBAL__' ): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/dictionary/delete',
					array(
						'raw_sku'  => trim( $raw_sku ),
						'site_key' => '' !== trim( $site_key ) ? trim( $site_key ) : '__GLOBAL__',
					),
					4.0
				);
			}

			public static function clear_dictionary_entries( string $site_key ): array {
				return \StealthCapiBridge\Config_Manager::gateway_admin_request(
					'POST',
					'/v3/admin/dictionary/clear',
					array( 'site_key' => trim( $site_key ) ),
					4.0
				);
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
