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
	}

	public static function save(): void {
		self::authorize( 'pc_panapay_hub_save' );
		try {
			Hub_Settings::save( wp_unslash( $_POST ) );
			self::flash( 'success', '支付设置已保存。已创建的付款仍会继续接收和回写结果。' );
		} catch ( \InvalidArgumentException $error ) {
			self::flash( 'error', self::validation_message( $error->getMessage() ) );
		} catch ( \Throwable $error ) {
			self::flash( 'error', '支付设置未保存。请核对网关地址、收银台域名和服务器配置后重试。' );
		}
		wp_safe_redirect( self::url( array( 'settings' => '1' ) ) );
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
		);
		return $messages[ $message ] ?? '设置内容有误，未保存。请核对字段长度、店铺选择和支付方配置。';
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pc_panapay_hub_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
	}

	private static function notices(): void {
		$key = 'pc_panapay_hub_notice_' . get_current_user_id();
		$value = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $value ) || ! is_string( $value['message'] ?? null ) ) { return; }
		$type = 'success' === ( $value['type'] ?? '' ) ? 'success' : 'error';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $value['message'] ) . '</p></div>';
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
			<?php self::notices(); ?>
			<nav class="pc-pay-jump" aria-label="支付中心分区"><a href="#pc-pay-ledger">交易记录</a><a href="#pc-pay-settings">支付设置</a></nav>
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
			<details id="pc-pay-settings" class="pc-pay-section pc-pay-settings" <?php echo ! $enabled || isset( $_GET['settings'] ) ? 'open' : ''; ?>><summary>支付设置</summary>
				<?php if ( $settings_error ) : ?><p role="alert">无法读取已保存的支付设置。请检查服务器配置，不要重复填写密钥。</p><?php else : ?><?php self::render_settings( $raw, $sites ); ?><?php endif; ?>
			</details>
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

	private static function render_settings( array $raw, array $sites ): void {
		$authorized = is_array( $raw['sites'] ?? null ) ? array_map( 'intval', $raw['sites'] ) : array();
		$hosts = is_array( $raw['payment_hosts'] ?? null ) ? implode( "\n", $raw['payment_hosts'] ) : (string) ( $raw['payment_hosts'] ?? '' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-pay-config"><input type="hidden" name="action" value="pc_panapay_hub_save"><?php wp_nonce_field( 'pc_panapay_hub_save' ); ?>
			<div class="pc-pay-setting-grid"><div>
				<fieldset class="pc-pay-switches"><legend class="screen-reader-text">收款状态</legend><label><input type="checkbox" name="enabled" value="yes" <?php checked( in_array( $raw['enabled'], array( 'yes', true, '1', 1 ), true ) ); ?>>开启统一收款</label><p class="pc-pay-muted">关闭后停止新支付，已创建的付款仍会接收并回写结果。</p><label><input type="checkbox" name="test_mode" value="yes" <?php checked( in_array( $raw['test_mode'], array( 'yes', true, '1', 1 ), true ) ); ?>>测试模式</label><p class="pc-pay-muted">需使用支付方提供的测试账户；此开关不会自动切换支付方环境。</p></fieldset>
				<div class="pc-pay-fields"><?php self::field( 'gateway', '网关地址', $raw, 'https://payments.example.com', '填写支付方提供的 HTTPS 地址，不含接口路径。' ); ?><?php self::field( 'mchid', '商户号', $raw ); ?>
					<div class="pc-pay-field"><label for="pc-pay-secret">签名密钥</label><input type="password" id="pc-pay-secret" name="secret" value="" autocomplete="new-password" maxlength="1024" <?php disabled( Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) ); ?> aria-describedby="pc-pay-secret-help"><p id="pc-pay-secret-help" class="pc-pay-muted"><?php echo esc_html( Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) ? '由服务器配置提供，后台不回显。' : ( '' !== (string) $raw['secret'] ? '已保存。留空沿用现有密钥，旧值不回显。' : '尚未保存，仅在总后台配置。' ) ); ?></p></div>
					<?php self::field( 'title', '买家看到的付款名称', $raw, '', '', 120 ); ?>
					<div class="pc-pay-field pc-pay-field-wide"><label for="pc-pay-description">付款说明</label><textarea id="pc-pay-description" name="description" rows="2" maxlength="1000"><?php echo esc_textarea( (string) $raw['description'] ); ?></textarea></div>
					<div class="pc-pay-field"><label for="pc-pay-mode">请求模式</label><select id="pc-pay-mode" name="mode"><option value="2" <?php selected( (string) $raw['mode'], '2' ); ?>>异步请求</option><option value="1" <?php selected( (string) $raw['mode'], '1' ); ?>>同步请求</option></select><p class="pc-pay-muted">按支付方协议选择，付款均以有效服务端通知为准。</p></div>
					<?php self::field( 'tag', '业务标签（可选）', $raw, '', '', 255 ); ?>
					<div class="pc-pay-field pc-pay-field-wide"><label for="pc-pay-hosts">可信收银台域名</label><textarea id="pc-pay-hosts" name="payment_hosts" rows="3" maxlength="8192" placeholder="checkout.example.com"><?php echo esc_textarea( $hosts ); ?></textarea><p class="pc-pay-muted">每行一个支付方确认的精确域名。网关域名自动纳入；不支持通配符。</p></div>
				</div>
			</div><fieldset class="pc-pay-store-access"><legend>允许收款的店铺</legend><p class="pc-pay-muted">只向勾选的店铺开放新支付，新接入店铺默认关闭。</p>
				<?php if ( ! $sites ) : ?><p>暂无已接入店铺。店铺接入管理后台后会出现在这里。</p><?php else : ?><?php foreach ( $sites as $site ) : ?><label class="pc-pay-store-option"><input type="checkbox" name="sites[]" value="<?php echo esc_attr( (string) $site['id'] ); ?>" <?php checked( in_array( (int) $site['id'], $authorized, true ) ); ?>><span><strong><?php echo esc_html( $site['name'] ); ?></strong><small><?php echo esc_html( $site['url'] ); ?></small></span></label><?php endforeach; ?><?php endif; ?>
			</fieldset></div>
			<div class="pc-pay-form-footer"><button type="submit" class="button button-primary">保存支付设置</button><span class="pc-pay-muted">店铺不保存支付方密钥。订单和库存仍由各店管理。</span></div>
		</form>
		<?php
	}

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
