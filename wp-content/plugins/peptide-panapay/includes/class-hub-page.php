<?php
/** Management-only payment configuration and tenant-bound accounting view. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Hub_Page {

	public const SLUG = 'pchq-payments';
	private const CAP = 'manage_options';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_pc_panapay_hub_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_panapay_hub_retry', array( self::class, 'retry' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'pchq', '支付中心', '支付中心', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) { return; }
		$file = PEPTIDE_PANAPAY_DIR . '/assets/hub.css';
		wp_enqueue_style( 'pc-panapay-hub', plugins_url( 'assets/hub.css', PEPTIDE_PANAPAY_FILE ), array(), PEPTIDE_PANAPAY_VERSION . '.' . (int) filemtime( $file ) );
		$script = PEPTIDE_PANAPAY_DIR . '/assets/hub.js';
		wp_enqueue_script( 'pc-panapay-hub', plugins_url( 'assets/hub.js', PEPTIDE_PANAPAY_FILE ), array(), PEPTIDE_PANAPAY_VERSION . '.' . (int) filemtime( $script ), true );
	}

	public static function save(): void {
		self::authorize( 'pc_panapay_hub_save' );
		$account = isset( $_POST['save_scope'] ) && 'account' === $_POST['save_scope'];
		$failed = false;
		try {
			$input = wp_unslash( $_POST );
			$saved = Hub_Settings::raw();
			if ( $account ) {
				// Binding an account never silently enables collection or edits store access.
				$input['enabled'] = self::is_on( $saved['enabled'] ?? 'no' ) ? 'yes' : '';
				$input['sites'] = $saved['sites'] ?? array();
			} elseif ( isset( $input['save_scope'] ) && 'access' === $input['save_scope'] ) {
				// The separate activation action cannot save an unfinished account dialog.
				$input = array( 'enabled' => $input['enabled'] ?? '', 'sites' => $input['sites'] ?? array(),
					'test_mode' => self::is_on( $saved['test_mode'] ?? 'yes' ) ? 'yes' : '' );
			}
			Hub_Settings::save( $input );
			self::flash( 'success', $account ? '账号配置已保存。收款开关与店铺授权保持原状。' : '收款开关与店铺授权已保存。在途付款仍会继续接收和回写结果。' );
		} catch ( \InvalidArgumentException $error ) {
			$failed = true;
			self::flash( 'error', self::validation_message( $error->getMessage() ) );
		} catch ( \Throwable $error ) {
			$failed = true;
			self::flash( 'error', '支付设置未保存。请核对网关地址、收银台域名和服务器配置后重试。' );
		}
		wp_safe_redirect( self::url( array( 'settings' => '1', 'account_error' => $account && $failed ? '1' : '0' ) ) );
		exit;
	}

	public static function retry(): void {
		self::authorize( 'pc_panapay_hub_retry' );
		try {
			$count = Hub::retry_pending( 10 );
			self::flash( 'success', sprintf( '已尝试回写 %d 条待处理结果，请查看下方店铺回写状态。', max( 0, (int) $count ) ) );
		} catch ( \Throwable $error ) {
			self::flash( 'error', '本次回写未完成。原付款记录仍保留，可稍后重试。' );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function authorize( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( self::CAP ) ) {
			wp_die( '你没有操作支付中心的权限。', 403 );
		}
		check_admin_referer( $action );
	}

	private static function validation_message( string $message ): string {
		$messages = array(
			'Enter the payment method name.' => '请填写买家看到的付款方式名称。',
			'Complete the gateway, merchant and signing settings before enabling payment.' => '开启收款前，请补齐网关地址、商户号和签名密钥。',
			'Select at least one store before enabling payment.' => '开启收款前，请至少授权一个店铺。',
			'Test payments cannot be enabled in production.' => '正式环境不能启用测试收款，请核对支付方账户和测试模式。',
			'Use HTTPS on the management site before enabling live payment.' => '开启正式收款前，请为管理后台配置 HTTPS。',
			'Store authorization changed. Reload and select the stores again.' => '店铺列表已变化，请重新选择并保存。',
			'Payment display text is invalid.' => '账号名称、付款名称或说明过长，请缩短后重新保存。',
			'Payment icons are invalid.' => '支付图标无效，请从页面提供的六种图标中选择。',
			'Payment iframe height is invalid.' => '请填写 320 至 1600 之间的整数作为支付窗口高度。',
			'Payment display icons are invalid.' => '支付图标无效，请从页面提供的六种图标中选择。',
			'Payment frame height must be an integer from 320 to 1600.' => '请填写 320 至 1600 之间的整数作为支付窗口高度。',
		);
		return $messages[ $message ] ?? '设置内容有误，未保存。请核对字段长度、店铺选择和支付方配置。';
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pc_panapay_hub_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
	}

	private static function notices(): ?array {
		$key = 'pc_panapay_hub_notice_' . get_current_user_id();
		$value = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $value ) || ! is_string( $value['message'] ?? null ) ) { return null; }
		$type = 'success' === ( $value['type'] ?? '' ) ? 'success' : 'error';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $value['message'] ) . '</p></div>';
		return array( 'type' => $type, 'message' => $value['message'] );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$sites = \PeptideHq\Sites::all();
		$site_id = isset( $_GET['site_id'] ) && is_scalar( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0;
		$state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! isset( self::states()[ $state ] ) ) { $state = ''; }
		$page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, min( 100000, absint( $_GET['paged'] ) ) ) : 1;
		$raw = array(); $settings_error = false; $ledger = null; $summary = null;
		try { $raw = Hub_Settings::raw(); } catch ( \Throwable $error ) { $settings_error = true; }
		try {
			if ( is_callable( array( Hub_Store::class, 'attempts' ) ) && Hub_Store::installed() ) {
				$ledger = Hub_Store::attempts( self::filters( $site_id, $state ), $page );
				$summary = Hub_Store::summary( $site_id );
			}
		} catch ( \Throwable $error ) { $ledger = null; $summary = null; }
		$enabled = 'yes' === ( $raw['enabled'] ?? 'no' ) || true === ( $raw['enabled'] ?? false );
		$testing = ! in_array( $raw['test_mode'] ?? 'yes', array( false, 'no', '0', 0, '' ), true );
		?>
		<div class="wrap pchq pc-pay-hub">
			<div class="pc-pay-heading"><div><h1>支付中心</h1><p class="pc-pay-muted">统一收款，按店铺记录订单和金额。</p></div><span class="pc-pay-state"><?php echo esc_html( ! $enabled ? '收款未启用' : ( $testing ? '测试模式' : '正式模式' ) ); ?></span></div>
			<?php $notice = self::notices(); ?>
			<nav class="pc-pay-jump" aria-label="支付中心分区"><a href="#pc-pay-settings">收款账号</a><a href="#pc-pay-ledger">交易记录</a></nav>
			<section id="pc-pay-settings" class="pc-pay-section pc-pay-onboarding" aria-labelledby="pc-pay-settings-title">
				<h2 id="pc-pay-settings-title">收款账号</h2>
				<?php if ( $settings_error ) : ?><p role="alert">无法读取已保存的支付设置。请检查服务器配置，不要重复填写密钥。</p><?php else : ?><?php self::render_settings( $raw, $sites, $notice ); ?><?php endif; ?>
			</section>
			<section id="pc-pay-ledger" class="pc-pay-section" aria-labelledby="pc-pay-ledger-title">
				<div class="pc-pay-section-head"><h2 id="pc-pay-ledger-title">交易记录</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="pc_panapay_hub_retry"><?php wp_nonce_field( 'pc_panapay_hub_retry' ); ?><button type="submit" class="button" <?php disabled( null === $ledger ); ?>>重试待回写结果</button></form></div>
				<p class="pc-pay-muted">支付方确认与店铺订单更新分别记录。回写失败会保留记录重试，不会重新扣款。</p>
				<?php if ( is_array( $summary ) ) { self::render_summary( $summary ); } ?>
				<form method="get" class="pc-pay-filters"><input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"><label>店铺<select name="site_id"><option value="0">全部店铺</option><?php foreach ( $sites as $site ) : ?><option value="<?php echo esc_attr( (string) $site['id'] ); ?>" <?php selected( $site_id, $site['id'] ); ?>><?php echo esc_html( $site['name'] ); ?></option><?php endforeach; ?><?php if ( $site_id && ! isset( $sites[ $site_id ] ) ) : ?><option value="<?php echo esc_attr( (string) $site_id ); ?>" selected>已移除店铺 #<?php echo esc_html( (string) $site_id ); ?></option><?php endif; ?></select></label><label>支付状态<select name="state"><option value="">全部状态</option><?php foreach ( self::states() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><button type="submit" class="button">筛选</button></form>
				<?php if ( null === $ledger ) : ?><div class="pc-pay-empty" role="status"><h3>暂时无法读取交易记录</h3><p>请刷新页面；若仍不可用，请检查支付插件是否已完成数据库升级。</p><a class="button" href="<?php echo esc_url( self::url() ); ?>">刷新记录</a></div><?php elseif ( empty( $ledger['rows'] ) ) : ?><div class="pc-pay-empty"><h3><?php echo esc_html( $site_id || $state ? '没有符合条件的交易' : '还没有付款记录' ); ?></h3><p><?php echo esc_html( $site_id || $state ? '调整店铺或支付状态后重新筛选。' : '在支付设置中配置收款并授权店铺后，新支付会记录在这里。' ); ?></p></div><?php else : ?>
					<div class="pc-pay-table-scroll" role="region" aria-label="按店铺划分的支付记录" tabindex="0"><table class="widefat pc-pay-table"><thead><tr><th scope="col">店铺 / 订单</th><th scope="col">支付引用</th><th scope="col" class="pc-pay-number">金额</th><th scope="col">支付方确认</th><th scope="col">店铺回写</th><th scope="col">更新时间</th></tr></thead><tbody><?php foreach ( $ledger['rows'] as $row ) { self::render_row( $row, $sites ); } ?></tbody></table></div>
					<?php self::pagination( $ledger, $page, $site_id, $state ); ?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	private static function render_summary( array $summary ): void {
		$currencies = is_array( $summary['currencies'] ?? null ) ? $summary['currencies'] : array();
		echo '<div class="pc-pay-summary"><div><strong>支付方已确认</strong>';
		if ( ! $currencies ) { echo '<span class="pc-pay-muted">暂无已确认金额</span>'; }
		foreach ( $currencies as $currency ) {
			if ( ! is_array( $currency ) ) { continue; }
			echo '<span class="pc-pay-amount">' . esc_html( (string) ( $currency['currency'] ?? '' ) ) . ' ' . esc_html( (string) ( $currency['confirmed_amount'] ?? '0' ) ) . '<small>' . esc_html( (string) (int) ( $currency['confirmed_count'] ?? 0 ) ) . ' 笔</small></span>';
		}
		echo '</div><p>待回写 <strong>' . esc_html( (string) (int) ( $summary['pending_count'] ?? 0 ) ) . '</strong><span>需核对 <strong>' . esc_html( (string) (int) ( $summary['review_count'] ?? 0 ) ) . '</strong></span></p></div><p class="pc-pay-muted">金额按创建支付时的订单汇总，实际结算以支付方账单为准。</p>';
	}

	private static function render_row( array $row, array $sites ): void {
		$id = (int) ( $row['site_id'] ?? 0 );
		$name = isset( $sites[ $id ] ) ? $sites[ $id ]['name'] : '已移除店铺 #' . $id;
		$provider = self::states()[ $row['state'] ?? '' ] ?? '状态待核对';
		if ( 'SUCCEEDED' === ( $row['event_status'] ?? '' ) ) { $provider = '已确认付款'; }
		if ( 'FAILED' === ( $row['event_status'] ?? '' ) ) { $provider = '已确认失败'; }
		$delivery = array( 'pending' => '等待回写', 'delivered' => '已回写', 'review' => '需要核对', 'blocked' => '连接待核对', '' => '尚无付款结果' )[ $row['delivery_state'] ?? '' ] ?? '状态待核对';
		$disposition = array( 'paid' => '订单已付款', 'failed' => '订单未付款', 'review' => '订单需核对' )[ $row['disposition'] ?? '' ] ?? '';
		echo '<tr><td><strong>' . esc_html( $name ) . '</strong><span>订单 #' . esc_html( (string) (int) ( $row['order_id'] ?? 0 ) ) . '</span></td><td class="pc-pay-reference">' . esc_html( (string) ( $row['ref'] ?? '' ) ) . '</td><td class="pc-pay-number">' . esc_html( (string) ( $row['currency'] ?? '' ) ) . ' ' . esc_html( (string) ( $row['amount'] ?? '' ) ) . '</td><td>' . esc_html( $provider ) . '</td><td>' . esc_html( $delivery );
		if ( '' !== $disposition ) { echo '<span>' . esc_html( $disposition ) . '</span>'; }
		echo '</td><td>' . esc_html( ! empty( $row['updated_at'] ) ? wp_date( 'Y-m-d H:i', (int) $row['updated_at'] ) : '—' ) . '</td></tr>';
	}

	private static function pagination( array $ledger, int $page, int $site_id, string $state ): void {
		$total = max( 0, (int) ( $ledger['total'] ?? 0 ) ); $size = max( 1, (int) ( $ledger['per_page'] ?? 20 ) ); $last = max( 1, (int) ceil( $total / $size ) );
		echo '<nav class="pc-pay-pagination" aria-label="交易记录分页"><span>共 ' . esc_html( (string) $total ) . ' 条 · 第 ' . esc_html( (string) $page ) . ' / ' . esc_html( (string) $last ) . ' 页</span><div>';
		foreach ( array( -1 => '上一页', 1 => '下一页' ) as $step => $label ) {
			$next = $page + $step;
			if ( $next < 1 || $next > $last ) { echo '<span class="button disabled" aria-disabled="true">' . esc_html( $label ) . '</span>'; }
			else { echo '<a class="button" href="' . esc_url( self::url( array( 'site_id' => $site_id, 'state' => $state, 'paged' => $next ) ) ) . '">' . esc_html( $label ) . '</a>'; }
		}
		echo '</div></nav>';
	}

	private static function render_settings( array $raw, array $sites, ?array $notice = null ): void {
		$authorized = is_array( $raw['sites'] ?? null ) ? array_map( 'intval', $raw['sites'] ) : array();
		$host_values = $raw['payment_hosts'] ?? array();
		$hosts = is_array( $host_values ) ? implode( "\n", array_filter( $host_values, 'is_string' ) ) : ( is_string( $host_values ) ? $host_values : '' );
		$catalog = self::icon_catalog();
		$selected_icons = is_array( $raw['payment_icons'] ?? null ) ? array_values( array_intersect( array_keys( $catalog ), $raw['payment_icons'] ) ) : array_keys( $catalog );
		$account_name = is_string( $raw['account_name'] ?? null ) ? trim( $raw['account_name'] ) : '';
		$has_secret = Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) || ( is_string( $raw['secret'] ?? null ) && '' !== $raw['secret'] );
		$merchant = is_string( $raw['mchid'] ?? null ) ? $raw['mchid'] : '';
		$has_merchant = Hub_Settings::server_override( 'PC_PANAPAY_MCHID' ) || '' !== $merchant;
		$configured = $has_secret && $has_merchant;
		$height = is_numeric( $raw['iframe_height'] ?? null ) ? (int) $raw['iframe_height'] : 530;
		$height = $height >= 320 && $height <= 1600 ? $height : 530;
		$account_error = isset( $_GET['account_error'] ) && '1' === $_GET['account_error'] && is_array( $notice ) && 'error' === $notice['type'];
		?>
		<form id="pc-pay-config" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-pay-config">
			<input type="hidden" name="action" value="pc_panapay_hub_save"><?php wp_nonce_field( 'pc_panapay_hub_save' ); ?>
			<div class="pc-pay-account-row">
				<div class="pc-pay-account-mark" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 28 28" fill="none"><rect x="3" y="6" width="22" height="16" rx="3" stroke="currentColor" stroke-width="1.6"/><path d="M3 11h22M7 17h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div>
				<div class="pc-pay-account-copy"><div class="pc-pay-account-title"><h3><?php echo esc_html( '' !== $account_name ? $account_name : 'Panapay 收款账号' ); ?></h3><span class="pc-pay-state"><?php echo esc_html( $configured ? '配置已保存' : '待绑定' ); ?></span></div>
					<p class="pc-pay-muted"><?php echo esc_html( $configured ? ( '' !== $merchant ? '商户号 **** ' . substr( $merchant, -4 ) . ' · 账号保存在总后台' : '商户信息由服务器提供' ) : '绑定支付方提供的账号后，再选择店铺并开启收款。' ); ?></p>
					<?php self::render_icon_strip( $selected_icons, $catalog ); ?>
				</div>
				<a href="#pc-pay-account-dialog" class="button <?php echo $configured ? '' : 'button-primary'; ?>" data-pc-pay-open><?php echo esc_html( $configured ? '编辑账号' : '绑定收款账号' ); ?></a>
			</div>
			<div class="pc-pay-access-layout">
				<fieldset class="pc-pay-switches"><legend>收款控制</legend>
					<label><input type="checkbox" name="enabled" value="yes" <?php checked( self::is_on( $raw['enabled'] ?? 'no' ) ); ?>>开启统一收款</label>
					<p class="pc-pay-muted">账号保存后仍保持当前开关。关闭只停止新支付，在途结果继续回写。</p>
				</fieldset>
				<fieldset class="pc-pay-store-access"><legend>允许收款的店铺</legend><p class="pc-pay-muted">只向勾选的店铺开放新支付，新店默认关闭。</p>
					<?php if ( ! $sites ) : ?><p>暂无已接入店铺。接入后可在这里授权收款。</p><?php else : ?><div class="pc-pay-store-options"><?php foreach ( $sites as $site ) : ?>
						<label class="pc-pay-store-option"><input type="checkbox" name="sites[]" value="<?php echo esc_attr( (string) $site['id'] ); ?>" <?php checked( in_array( (int) $site['id'], $authorized, true ) ); ?>><span><strong><?php echo esc_html( $site['name'] ); ?></strong><small><?php echo esc_html( $site['url'] ); ?></small></span></label>
					<?php endforeach; ?></div><?php endif; ?>
				</fieldset>
			</div>
			<dialog id="pc-pay-account-dialog" class="pc-pay-account-dialog" open aria-labelledby="pc-pay-account-title" aria-describedby="pc-pay-account-help" data-pc-pay-account-dialog data-auto-open="<?php echo $account_error ? '1' : '0'; ?>">
				<header class="pc-pay-dialog-header"><div><h2 id="pc-pay-account-title"><?php echo esc_html( $configured ? '编辑收款账号' : '绑定收款账号' ); ?></h2><p id="pc-pay-account-help">填写支付方提供的账号资料。保存不会自动开启收款。</p></div><button class="pc-pay-dialog-close" type="button" data-pc-pay-cancel aria-label="取消并关闭账号编辑"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="m4 4 10 10M14 4 4 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button></header>
				<div class="pc-pay-dialog-scroll">
					<?php if ( $account_error ) : ?><p class="pc-pay-dialog-error" role="alert"><?php echo esc_html( $notice['message'] ); ?> 未保存的内容需重新填写。</p><?php endif; ?>
					<fieldset class="pc-pay-account-fields"><legend>账号信息</legend><div class="pc-pay-fields">
						<?php self::field( 'account_name', '账号名称', $raw, '例如 北美主收款账号', '仅作内部备注，不代替支付方商户号。', 120 ); ?>
						<?php self::field( 'gateway', '网关地址', $raw, 'https://payments.example.com', '填写 HTTPS 源地址，不含接口路径。' ); ?>
						<?php self::field( 'mchid', '商户号', $raw, '', '', 128 ); ?>
						<div class="pc-pay-field"><label for="pc-pay-secret">签名密钥</label><input type="password" id="pc-pay-secret" name="secret" value="" autocomplete="new-password" maxlength="1024" <?php disabled( Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) ); ?> aria-describedby="pc-pay-secret-help"><p id="pc-pay-secret-help" class="pc-pay-muted"><?php echo esc_html( Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) ? '由服务器提供，后台不回显。' : ( $has_secret ? '已保存。留空沿用，旧密钥不会显示。' : '填写支付方签名密钥，仅保存在总后台。' ) ); ?></p></div>
						<?php self::field( 'tag', '业务标签（可选）', $raw, '', '', 255 ); ?>
						<div class="pc-pay-field"><label for="pc-pay-mode">请求模式</label><select id="pc-pay-mode" name="mode"><option value="2" <?php selected( (string) ( $raw['mode'] ?? '2' ), '2' ); ?>>异步请求（2）</option><option value="1" <?php selected( (string) ( $raw['mode'] ?? '2' ), '1' ); ?>>同步请求（1）</option></select><p class="pc-pay-muted">按支付方协议选择，付款结果均以有效服务端通知为准。</p></div>
					</div></fieldset>
					<fieldset class="pc-pay-account-fields"><legend>结账展示</legend><div class="pc-pay-fields">
						<?php self::field( 'title', '付款方式名称', $raw, 'Credit card', '显示给买家。', 120 ); ?>
						<div class="pc-pay-field"><label for="pc-pay-height">支付窗口高度</label><div class="pc-pay-number-unit"><input type="number" id="pc-pay-height" name="iframe_height" value="<?php echo esc_attr( (string) $height ); ?>" min="320" max="1600" step="1" inputmode="numeric" required><span>px</span></div><p class="pc-pay-muted">仅用于支付方返回 iframe 时的嵌入窗口。</p></div>
						<div class="pc-pay-field pc-pay-field-wide"><label for="pc-pay-description">付款说明</label><textarea id="pc-pay-description" name="description" rows="2" maxlength="1000"><?php echo esc_textarea( (string) ( $raw['description'] ?? '' ) ); ?></textarea></div>
						<fieldset class="pc-pay-field pc-pay-field-wide pc-pay-icon-field"><legend>结账显示图标</legend><input type="hidden" name="payment_icons_present" value="1"><div class="pc-pay-icon-options">
							<?php foreach ( $catalog as $id => $label ) : ?><label class="pc-pay-icon-option"><input type="checkbox" name="payment_icons[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $selected_icons, true ) ); ?>><?php $icon_url = self::icon_url( $id ); if ( '' !== $icon_url ) : ?><img src="<?php echo esc_url( $icon_url ); ?>" width="54" height="32" alt=""><?php endif; ?><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?>
						</div><p class="pc-pay-muted">只设置图标展示，实际可用方式由支付方收银台决定。</p></fieldset>
					</div></fieldset>
					<details class="pc-pay-advanced"><summary>高级设置</summary><div class="pc-pay-fields">
						<div class="pc-pay-field pc-pay-field-wide"><label class="pc-pay-inline-check"><input type="checkbox" name="test_mode" value="yes" <?php checked( self::is_on( $raw['test_mode'] ?? 'yes' ) ); ?>>测试模式</label><p class="pc-pay-muted">需使用支付方的测试账户；此开关不会自动切换支付方环境。</p></div>
						<div class="pc-pay-field pc-pay-field-wide"><label for="pc-pay-hosts">可信收银台域名</label><textarea id="pc-pay-hosts" name="payment_hosts" rows="3" maxlength="8192" placeholder="checkout.example.com"><?php echo esc_textarea( $hosts ); ?></textarea><p class="pc-pay-muted">每行一个已确认的精确域名，网关域名自动纳入，不支持通配符。</p></div>
					</div></details>
				</div>
				<footer class="pc-pay-dialog-footer"><p>收银台的跳转或嵌入方式由支付方实际返回。</p><div><a href="#pc-pay-settings" class="button" data-pc-pay-cancel>取消</a><button type="submit" name="save_scope" value="account" class="button button-primary" data-pc-pay-account-save>保存账号</button></div></footer>
			</dialog>
			<div class="pc-pay-form-footer"><button type="submit" name="save_scope" value="access" class="button button-primary" formnovalidate>保存收款与店铺授权</button><span class="pc-pay-muted">账号配置单独保存，订单与库存仍归各店管理。</span></div>
		</form>
		<?php
	}

	private static function icon_catalog(): array {
		$allowed = array( 'american_express' => 'American Express', 'mastercard' => 'Mastercard', 'visa' => 'Visa', 'discover' => 'Discover', 'apple_pay' => 'Apple Pay', 'paypal' => 'PayPal' );
		if ( ! class_exists( Payment_Icons::class ) ) { return $allowed; }
		$catalog = Payment_Icons::catalog();
		foreach ( $allowed as $id => $label ) { if ( isset( $catalog[ $id ] ) && is_string( $catalog[ $id ] ) ) { $allowed[ $id ] = $catalog[ $id ]; } }
		return $allowed;
	}

	private static function icon_url( string $id ): string {
		return class_exists( Payment_Icons::class ) ? Payment_Icons::url( $id ) : '';
	}

	private static function render_icon_strip( array $selected, array $catalog ): void {
		if ( ! $selected ) { echo '<span class="pc-pay-muted">未选择结账展示图标</span>'; return; }
		echo '<ul class="pc-pay-methods" aria-label="已选择的结账展示图标">';
		foreach ( $selected as $id ) {
			if ( ! isset( $catalog[ $id ] ) ) { continue; }
			$url = self::icon_url( $id );
			echo '<li>' . ( '' !== $url ? '<img src="' . esc_url( $url ) . '" width="54" height="32" alt="' . esc_attr( $catalog[ $id ] ) . '">' : esc_html( $catalog[ $id ] ) ) . '</li>';
		}
		echo '</ul>';
	}

	private static function is_on( $value ): bool { return in_array( $value, array( 'yes', true, '1', 1 ), true ); }

	private static function field( string $name, string $label, array $raw, string $placeholder = '', string $help = '', int $maxlength = 255 ): void {
		$server = in_array( $name, array( 'gateway', 'mchid' ), true ) && Hub_Settings::server_override( 'PC_PANAPAY_' . strtoupper( $name ) );
		echo '<div class="pc-pay-field"><label for="pc-pay-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input type="text" id="pc-pay-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $raw[ $name ] ?? '' ) ) . '" maxlength="' . esc_attr( (string) $maxlength ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . disabled( $server, true, false ) . '>';
		if ( $server || '' !== $help ) { echo '<p class="pc-pay-muted">' . esc_html( $server ? '由服务器配置提供。' : $help ) . '</p>'; }
		echo '</div>';
	}

	private static function states(): array {
		return array( 'creating' => '正在创建', 'created' => '支付会话已创建', 'unknown' => '创建结果待核对', 'failed' => '创建失败',
			'succeeded' => '支付方确认付款', 'confirmed_failed' => '支付方确认失败', 'pending' => '等待回写', 'delivered' => '已回写', 'review' => '需要核对' );
	}

	private static function filters( int $site_id, string $state ): array {
		$filters = array( 'site_id' => $site_id );
		if ( in_array( $state, array( 'creating', 'created', 'unknown', 'failed' ), true ) ) { $filters['state'] = $state; }
		$status = array( 'succeeded' => 'SUCCEEDED', 'confirmed_failed' => 'FAILED', 'pending' => 'pending', 'delivered' => 'delivered', 'review' => 'review' )[ $state ] ?? '';
		if ( '' !== $status ) { $filters['status'] = $status; }
		return $filters;
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}
}
