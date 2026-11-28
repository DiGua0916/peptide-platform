<?php
/**
 * 单店一线投手极简只读封装视图 (`class-subsite-buyer-ui.php`)。
 *
 * 严格遵循总控与投手隔离原则：
 * - 单店后台不展示任何备用槽位、不提及“一主九备/热备池/养号天数/伪装字典”等任何底层架构细节；
 * - 单店本地无需保存总控密钥（仅使用本店自身通信签名向网关 `/v3/reconcile/primary-status` 拉取唯一主像素状态）；
 * - 投手仅能看到自己店铺【当前在投主像素】的健康灯态、掩码尾号与今日转化回传计数，异常时直接截图反馈总控切换。
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Subsite_Buyer_UI {

	public const SLUG = 'stealth-capi-buyer-heatmap';

	public static function register_hooks(): void {
		// 总后台不显示投手单店视图菜单
		if ( class_exists( '\PeptideHq\Admin\Dashboard_Page' ) ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'register_buyer_menu' ), 25 );
		add_action( 'admin_footer', array( self::class, 'maybe_embed_in_buyer_ads_page' ), 5 );
	}

	public static function register_buyer_menu(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$cap = current_user_can( 'edit_shop_orders' ) ? 'edit_shop_orders' : 'manage_options';
		add_menu_page(
			'当前主像素状态',
			'主像素状态',
			$cap,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-yes-alt',
			56
		);
	}

	/**
	 * 当一线投手（pc_media_buyer）进入其权限内的「广告数据追踪 → 广告设置」页面时，
	 * 通过 `<template>` 将封装好的「当前主像素状态」只读卡片挂载进 `#wpbody-content > .wrap` 主工作区内，
	 * 避免直接在 `admin_footer` 裸输出导致卡片钻入左侧 220px 固定菜单栏下方或与 `#wpfooter` 重叠。
	 */
	public static function maybe_embed_in_buyer_ads_page(): void {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'pc-buyer-ads' !== $page && 'pc-pixels' !== $page ) {
			return;
		}
		echo '<template id="scapi-buyer-embed-tpl">';
		self::render_panel( true );
		echo '</template>';
		?>
		<script>
		(function() {
			var tpl = document.getElementById('scapi-buyer-embed-tpl');
			if (!tpl || !tpl.content) { return; }
			var wrap = document.querySelector('#wpbody-content > .wrap') || document.getElementById('wpbody-content');
			if (!wrap) { return; }
			var pxCard = document.getElementById('pc-by-pixel');
			if (pxCard && pxCard.parentNode === wrap) {
				wrap.insertBefore(tpl.content.cloneNode(true), pxCard.nextSibling);
			} else {
				wrap.appendChild(tpl.content.cloneNode(true));
			}
		})();
		</script>
		<?php
	}

	private static function format_primary_status_banner( bool $configured, bool $gw_ok, string $circuit_state ): array {
		if ( ! $configured ) {
			return array(
				'badge_cls' => 'warn',
				'dot_cls'   => 'yellow',
				'title'     => '待总控下发绑定',
				'advice'    => '当前店铺尚未开启回传通道绑定，请联系管理员在总后台一键下发绑定。',
			);
		}
		if ( ! $gw_ok ) {
			return array(
				'badge_cls' => 'warn',
				'dot_cls'   => 'yellow',
				'title'     => '状态同步缓冲中',
				'advice'    => '本地订单采集正常，状态查询正在重连，请稍后刷新查看；若持续出现请反馈管理员。',
			);
		}
		switch ( strtoupper( $circuit_state ) ) {
			case 'CLOSED':
				return array(
					'badge_cls' => 'ok',
					'dot_cls'   => 'green',
					'title'     => '正常回传中',
					'advice'    => '当前主像素回传通道健康畅通，转化事件正实时上报。',
				);
			case 'PAUSED_RATE_LIMIT':
				return array(
					'badge_cls' => 'warn',
					'dot_cls'   => 'yellow',
					'title'     => '接口繁忙自动缓冲中',
					'advice'    => '广告平台接口触发短时频控保护，系统正在后台自动排队重发，无需暂停广告。',
				);
			case 'UNCONFIGURED':
			case 'NO_PRIMARY':
				return array(
					'badge_cls' => 'neutral',
					'dot_cls'   => 'gray',
					'title'     => '待分配在投主像素',
					'advice'    => '当前店铺隐身回传通道已就绪，尚未绑定在投主像素；广告户就绪后联系管理员在总后台配置即可。',
				);
			case 'DISABLED':
				return array(
					'badge_cls' => 'warn',
					'dot_cls'   => 'yellow',
					'title'     => '当前主像素已暂停',
					'advice'    => '当前主像素已在总后台设为暂停状态，如需恢复回传请联系管理员开启。',
				);
			default:
				return array(
					'badge_cls' => 'err',
					'dot_cls'   => 'red',
					'title'     => '主像素异常中断（请立即反馈管理员）',
					'advice'    => '检测到当前主像素受限或授权失效！请立即将本页截图发给管理员切换新主像素。',
				);
		}
	}

	/**
	 * 拉取严格封装的单店【唯一主像素】只读状态模型（物理不含任何备用槽位或密钥信息）。
	 */
	public static function get_sanitized_subsite_model(): array {
		$tenant = Config_Manager::resolve_current_tenant();
		$health = Reconcile_Cron::get_local_health();

		if ( ! $tenant ) {
			return array(
				'configured'               => false,
				'gateway_connected'        => false,
				'dataset_masked'           => '****',
				'circuit_state'            => 'UNCONFIGURED',
				'last_cashier_purchase_at' => (int) ( $health['last_cashier_purchase_at'] ?? 0 ),
				'today_funnel'             => array(
					'PageView'         => 0,
					'ViewContent'      => 0,
					'AddToCart'        => 0,
					'InitiateCheckout' => 0,
					'AddPaymentInfo'   => 0,
					'Purchase'         => 0,
				),
			);
		}

		$site_key = (string) $tenant['site_key'];
		$res      = Config_Manager::dispatch_to_gateway(
			array( 'site_key' => $site_key ),
			$tenant,
			'/v3/reconcile/primary-status',
			3.0
		);

		// 兼容回退读取（若网关未返回主槽或本地模拟内存池未命中 SQL，仅提取唯一 PRIMARY 槽位，绝不暴露任何热备槽！）
		if ( ! $res['ok'] || empty( $res['data']['has_primary'] ) ) {
			$gw = Config_Manager::gateway_admin_request( 'GET', '/v3/admin/slots-status?site_key=' . rawurlencode( $site_key ), null, 3.0 );
			if ( ! empty( $gw['ok'] ) ) {
				$site_data = $gw['data']['sites'][ $site_key ] ?? array();
				$raw_slots = isset( $site_data['slots'] ) && is_array( $site_data['slots'] ) ? $site_data['slots'] : array();
				$prim      = null;
				foreach ( $raw_slots as $sl ) {
					if ( 'PRIMARY' === strtoupper( (string) ( $sl['slot_role'] ?? $sl['role'] ?? '' ) ) ) {
						$prim = $sl;
						break;
					}
				}
				if ( $prim ) {
					$ds_raw = (string) ( $prim['dataset_id'] ?? '' );
					$tf     = isset( $site_data['today_funnel'] ) && is_array( $site_data['today_funnel'] ) ? $site_data['today_funnel'] : array();
					return array(
						'configured'               => true,
						'gateway_connected'        => true,
						'dataset_masked'           => strlen( $ds_raw ) >= 4 ? ( '****' . substr( $ds_raw, -4 ) ) : '****',
						'circuit_state'            => strtoupper( (string) ( $prim['circuit_state'] ?? 'CLOSED' ) ),
						'last_cashier_purchase_at' => (int) ( $site_data['last_ingest_at'] ?? 0 ),
						'today_funnel'             => array(
							'PageView'         => (int) ( $tf['PageView'] ?? 0 ),
							'ViewContent'      => (int) ( $tf['ViewContent'] ?? 0 ),
							'AddToCart'        => (int) ( $tf['AddToCart'] ?? 0 ),
							'InitiateCheckout' => (int) ( $tf['InitiateCheckout'] ?? 0 ),
							'AddPaymentInfo'   => (int) ( $tf['AddPaymentInfo'] ?? 0 ),
							'Purchase'         => (int) ( $tf['Purchase'] ?? 0 ),
						),
					);
				}
			}
		}

		$d  = is_array( $res['data'] ) ? $res['data'] : array();
		$tf = isset( $d['today_funnel'] ) && is_array( $d['today_funnel'] ) ? $d['today_funnel'] : array();

		return array(
			'configured'               => true,
			'gateway_connected'        => ! empty( $res['ok'] ),
			'dataset_masked'           => (string) ( $d['dataset_masked'] ?? '****' ),
			'circuit_state'            => strtoupper( (string) ( $d['circuit_state'] ?? 'UNCONFIGURED' ) ),
			'last_cashier_purchase_at' => ! empty( $res['ok'] )
				? (int) ( $d['last_ingest_at'] ?? 0 )
				: (int) ( $health['last_cashier_purchase_at'] ?? 0 ),
			'today_funnel'             => array(
				'PageView'         => (int) ( $tf['PageView'] ?? 0 ),
				'ViewContent'      => (int) ( $tf['ViewContent'] ?? 0 ),
				'AddToCart'        => (int) ( $tf['AddToCart'] ?? 0 ),
				'InitiateCheckout' => (int) ( $tf['InitiateCheckout'] ?? 0 ),
				'AddPaymentInfo'   => (int) ( $tf['AddPaymentInfo'] ?? 0 ),
				'Purchase'         => (int) ( $tf['Purchase'] ?? 0 ),
			),
		);
	}

	public static function render(): void {
		self::render_panel( false );
	}

	public static function render_panel( bool $embedded = false ): void {
		$vm       = self::get_sanitized_subsite_model();
		$fn       = $vm['today_funnel'];
		$banner   = self::format_primary_status_banner( $vm['configured'], $vm['gateway_connected'], $vm['circuit_state'] );
		$last_str = $vm['last_cashier_purchase_at'] > 0
			? gmdate( 'Y年m月d日 H:i:s', (int) $vm['last_cashier_purchase_at'] + 8 * 3600 ) . '（北京时间）'
			: '今日尚无成交回传记录';

		$wrap_cls = $embedded ? 'scapi-console scapi-embedded-panel' : 'wrap scapi-console';
		$wrap_sty = $embedded ? 'margin:16px 0 20px;max-width:100%;box-sizing:border-box;' : '';
		?>
		<div class="<?php echo esc_attr( $wrap_cls ); ?>"<?php echo '' !== $wrap_sty ? ' style="' . esc_attr( $wrap_sty ) . '"' : ''; ?>>
			<?php if ( $embedded ) : ?>
				<h2 style="margin:0 0 10px;font-size:16px;font-weight:600;line-height:24px;color:#0f172a;">当前主像素状态</h2>
			<?php else : ?>
				<h1>当前主像素状态</h1>
			<?php endif; ?>

			<div class="scapi-card" style="margin-top:<?php echo $embedded ? '0' : '14px'; ?>;box-sizing:border-box;">
				<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
					<div style="min-width:0;flex:1 1 320px;">
						<div style="font-size:13px;color:#64748b;margin-bottom:6px;">当前在投主像素通道状态</div>
						<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
							<span class="scapi-badge <?php echo esc_attr( $banner['badge_cls'] ); ?>" style="font-size:14px;padding:6px 14px;">
								<span class="scapi-dot <?php echo esc_attr( $banner['dot_cls'] ); ?>"></span>
								<?php echo esc_html( $banner['title'] ); ?>
							</span>
							<span style="font-size:14px;color:#334155;">当前主像素尾号：<code><?php echo esc_html( $vm['dataset_masked'] ); ?></code></span>
						</div>
						<p style="margin:10px 0 0;color:#475569;font-size:13px;line-height:1.6;"><?php echo esc_html( $banner['advice'] ); ?></p>
					</div>
					<div style="text-align:right;font-size:13px;color:#64748b;flex:0 0 auto;">
						最近一笔成交回传时间<br />
						<strong style="color:#0f172a;"><?php echo esc_html( $last_str ); ?></strong>
					</div>
				</div>
			</div>

			<div class="scapi-grid-4" style="box-sizing:border-box;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
				<?php
				$cards = array(
					array( '今日页面浏览 (PageView)', $fn['PageView'] ),
					array( '今日查看商品 (ViewContent)', $fn['ViewContent'] ),
					array( '今日加入购物车 (AddToCart)', $fn['AddToCart'] ),
					array( '今日进入结账 (InitiateCheckout)', $fn['InitiateCheckout'] ),
					array( '今日填写地址/支付 (AddPaymentInfo)', $fn['AddPaymentInfo'] ),
					array( '今日完成成交 (Purchase)', $fn['Purchase'] ),
				);
				foreach ( $cards as $c ) :
					?>
					<div class="scapi-kpi" style="box-sizing:border-box;">
						<div class="label"><?php echo esc_html( $c[0] ); ?></div>
						<div class="val"><?php echo esc_html( (string) $c[1] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<p style="color:#64748b;font-size:12px;margin:8px 0 0;line-height:1.6;">
				提示：本区域仅展示当前正在投放的主像素通道实时状态。如状态灯变红或投放期间回传异常，请截图反馈管理员在总后台处理。
			</p>
		</div>
		<?php
	}
}
