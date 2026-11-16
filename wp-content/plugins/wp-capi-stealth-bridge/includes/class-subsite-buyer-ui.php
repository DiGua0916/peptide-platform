<?php
/**
 * Single-Site / Subsite Frontline Media Buyer Read-Only Heatmap Dashboard (`class-subsite-buyer-ui.php`).
 *
 * Security & Role Isolation Guarantees (`b_wp 终极版.md` Section 3.5 & 4.2):
 * - Strictly READ-ONLY: zero configuration mutations allowed.
 * - 100% ZERO SECRET EXPOSURE: physically masks/omits all Access Tokens, Egress Proxy IPs,
 *   HMAC Secrets, Admin Master Keys, BM internal memos, and all other 5 stores' metrics!
 * - Shows ONLY current store's `1 Primary + 9 Standby` slot health lights, warmup progress,
 *   today/cumulative funnel heat bars (`Contact`, `CompleteRegistration`, `InitiateCheckout`, `Purchase`),
 *   and the latest external cashier callback timestamp.
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Subsite_Buyer_UI {

	public const SLUG = 'stealth-capi-buyer-heatmap';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_buyer_menu' ), 25 );
	}

	public static function register_buyer_menu(): void {
		$cap = current_user_can( 'edit_shop_orders' ) ? 'edit_shop_orders' : 'manage_options';
		add_menu_page(
			'本站像素热量看板（只读）',
			'像素热量看板',
			$cap,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-chart-bar',
			56
		);
	}

	/**
	 * Build strictly sanitized read-only view model for the current store ONLY.
	 */
	public static function get_sanitized_subsite_model(): array {
		$tenant = Config_Manager::resolve_current_tenant();
		$health = Reconcile_Cron::get_local_health();

		if ( ! $tenant ) {
			return array(
				'configured'               => false,
				'store_code'               => '',
				'tenant_mode'              => '',
				'last_cashier_purchase_at' => (int) ( $health['last_cashier_purchase_at'] ?? 0 ),
				'today_funnel'             => array( 'Contact' => 0, 'CompleteRegistration' => 0, 'InitiateCheckout' => 0, 'Purchase' => 0 ),
				'slots'                    => array(),
			);
		}

		$site_key  = (string) $tenant['site_key'];
		$gw        = Config_Manager::gateway_admin_request( 'GET', '/v3/admin/slots-status?site_key=' . rawurlencode( $site_key ), null, 3.5 );
		$site_data = $gw['data']['sites'][ $site_key ] ?? array();
		$raw_slots = isset( $site_data['slots'] ) && is_array( $site_data['slots'] ) ? $site_data['slots'] : array();
		if ( empty( $raw_slots ) && ! empty( $gw['data']['slots'] ) && is_array( $gw['data']['slots'] ) ) {
			foreach ( $gw['data']['slots'] as $item ) {
				if ( is_array( $item ) && ( ! isset( $item['site_key'] ) || $site_key === (string) $item['site_key'] ) ) {
					$raw_slots[] = $item;
				}
			}
		}

		// Physically strip every sensitive field (NO dataset_id full exposure if sensitive, NO proxy, NO token, NO BM memo!).
		$safe_slots = array();
		foreach ( $raw_slots as $idx => $sl ) {
			$ds_raw    = (string) ( $sl['dataset_id'] ?? '' );
			$ds_tail   = strlen( $ds_raw ) > 4 ? ( '****' . substr( $ds_raw, -4 ) ) : '****';
			$role_val  = strtoupper( (string) ( $sl['slot_role'] ?? $sl['role'] ?? 'STANDBY' ) );
			$enabled_v = $sl['is_enabled'] ?? $sl['enabled'] ?? true;
			$safe_slots[] = array(
				'slot_label'      => ( 'PRIMARY' === $role_val ) ? '主力投流槽 (Slot 1)' : ( '静默热备槽 #' . ( $idx + 1 ) ),
				'slot_role'       => $role_val,
				'dataset_masked'  => $ds_tail,
				'circuit_state'   => strtoupper( (string) ( $sl['circuit_state'] ?? 'CLOSED' ) ),
				'warmup_days'     => round( (float) ( $sl['warmup_days'] ?? 0 ), 1 ),
				'min_warmup_days' => (int) ( $sl['min_warmup_days'] ?? $sl['min_warmup_days_before_promote'] ?? 7 ),
				'purchase_count'  => (int) ( $sl['delivered_purchase_count'] ?? $sl['total_delivered'] ?? 0 ),
				'shallow_count'   => (int) ( $sl['delivered_shallow_count'] ?? 0 ),
				'is_enabled'      => ! empty( $enabled_v ),
			);
		}

		$tf = isset( $site_data['today_funnel'] ) && is_array( $site_data['today_funnel'] ) ? $site_data['today_funnel'] : array();

		return array(
			'configured'               => true,
			'store_code'               => (string) ( $tenant['store_code'] ?: $site_key ),
			'tenant_mode'              => (string) $tenant['tenant_mode'],
			'gateway_connected'        => ! empty( $gw['ok'] ),
			'last_cashier_purchase_at' => max( (int) ( $health['last_cashier_purchase_at'] ?? 0 ), (int) ( $site_data['last_ingest_at'] ?? 0 ) ),
			'today_funnel'             => array(
				'Contact'              => (int) ( $tf['Contact'] ?? 0 ),
				'CompleteRegistration' => (int) ( $tf['CompleteRegistration'] ?? 0 ),
				'InitiateCheckout'     => (int) ( $tf['InitiateCheckout'] ?? 0 ),
				'Purchase'             => (int) ( $tf['Purchase'] ?? 0 ),
			),
			'slots'                    => $safe_slots,
		);
	}

	public static function render(): void {
		$vm = self::get_sanitized_subsite_model();
		$fn = $vm['today_funnel'];
		$last_str = $vm['last_cashier_purchase_at'] > 0
			? gmdate( 'Y-m-d H:i:s', (int) $vm['last_cashier_purchase_at'] ) . ' UTC'
			: '今日尚无收银台回传记录';
		?>
		<div class="wrap scapi-console">
			<h1>本站像素漏斗热量看板（投手只读视图） <span class="scapi-badge ok">安全隔离已开启 · 零敏感凭证暴露</span></h1>
			<p style="color:#64748b;font-size:13px;">
				当前店铺：<strong><?php echo esc_html( $vm['store_code'] ?: '未配置' ); ?></strong> &nbsp;|&nbsp;
				最近一笔外部收银台 Purchase 回传时间：<code><?php echo esc_html( $last_str ); ?></code>
			</p>

			<div class="scapi-grid-4">
				<?php
				$cards = array(
					array( 'WhatsApp 咨询 (Contact)', $fn['Contact'], 50 ),
					array( '账号注册 (CompleteRegistration)', $fn['CompleteRegistration'], 30 ),
					array( '发起结账 (InitiateCheckout)', $fn['InitiateCheckout'], 25 ),
					array( '成交回传 (Purchase)', $fn['Purchase'], 15 ),
				);
				foreach ( $cards as $c ) :
					$pct = min( 100, (int) round( ( $c[1] / max( 1, $c[2] ) ) * 100 ) );
					?>
					<div class="scapi-kpi">
						<div class="label"><?php echo esc_html( $c[0] ); ?></div>
						<div class="val"><?php echo esc_html( (string) $c[1] ); ?></div>
						<div class="scapi-heatbar-bg"><div class="scapi-heatbar-fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%;"></div></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="scapi-card">
				<h2 style="margin-top:0;">本站 1 主 + 9 备像素池养成热量进度（只读）</h2>
				<table class="scapi-table">
					<thead>
						<tr>
							<th>槽位角色</th>
							<th>数据集尾号</th>
							<th>通道健康度</th>
							<th>静默养号天数</th>
							<th>累计 Purchase 热量 (目标 500+)</th>
							<th>累计浅层信号</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $vm['slots'] ) ) : ?>
							<tr><td colspan="6" style="color:#64748b;">暂未读取到本站像素槽位状态。</td></tr>
						<?php else : ?>
							<?php foreach ( $vm['slots'] as $sl ) : ?>
								<?php
								$c_state  = $sl['circuit_state'];
								$dot_cls  = 'CLOSED' === $c_state ? 'green' : ( 'PAUSED_RATE_LIMIT' === $c_state ? 'yellow' : 'red' );
								$b_cls    = 'CLOSED' === $c_state ? 'ok' : ( 'PAUSED_RATE_LIMIT' === $c_state ? 'warn' : 'err' );
								$heat_pct = min( 100, (int) round( ( $sl['purchase_count'] / 500 ) * 100 ) );
								?>
								<tr>
									<td><strong><?php echo esc_html( $sl['slot_label'] ); ?></strong></td>
									<td><code><?php echo esc_html( $sl['dataset_masked'] ); ?></code></td>
									<td><span class="scapi-badge <?php echo esc_attr( $b_cls ); ?>"><span class="scapi-dot <?php echo esc_attr( $dot_cls ); ?>"></span><?php echo 'CLOSED' === $c_state ? '健康运行' : esc_html( $c_state ); ?></span></td>
									<td><?php echo 'PRIMARY' === $sl['slot_role'] ? '主力在投' : esc_html( $sl['warmup_days'] . ' / ' . $sl['min_warmup_days'] . ' 天' ); ?></td>
									<td style="min-width:200px;">
										<strong><?php echo esc_html( (string) $sl['purchase_count'] ); ?></strong> / 500
										<div class="scapi-heatbar-bg"><div class="scapi-heatbar-fill" style="width:<?php echo esc_attr( (string) $heat_pct ); ?>%;"></div></div>
									</td>
									<td><?php echo esc_html( (string) $sl['shallow_count'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
