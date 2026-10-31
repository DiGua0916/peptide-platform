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
		add_action( 'admin_post_pc_panapay_hub_account_action', array( self::class, 'account_action' ) );
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
		$failed = false;
		$id = isset( $_POST['account_id'] ) && is_string( $_POST['account_id'] ) ? wp_unslash( $_POST['account_id'] ) : '';
		try {
			$input = wp_unslash( $_POST );
			$input['sites'] = $input['sites'] ?? array();
			$id = Hub_Accounts::save( $input );
			self::flash( 'success', '付款方式已保存。授权店铺会自动获取最新配置，商户资料仅保存在总后台。' );
		} catch ( \InvalidArgumentException $error ) {
			$failed = true;
			self::flash( 'error', self::validation_message( $error->getMessage() ) );
		} catch ( \RuntimeException $error ) {
			$failed = true;
			self::flash( 'error', self::validation_message( $error->getMessage() ) );
		} catch ( \Throwable $error ) {
			$failed = true;
			self::flash( 'error', '支付设置未保存。请核对网关地址、收银台域名和服务器配置后重试。' );
		}
		wp_safe_redirect( self::url( $failed ? array( 'edit_account' => $id, 'account_error' => '1' ) : array() ) );
		exit;
	}

	public static function account_action(): void {
		self::authorize( 'pc_panapay_hub_account_action' );
		try {
			$id = isset( $_POST['account_id'] ) && is_string( $_POST['account_id'] ) ? wp_unslash( $_POST['account_id'] ) : '';
			$revision = $_POST['revision'] ?? null;
			if ( ! is_string( $revision ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,14})\z/', $revision ) ) { throw new \InvalidArgumentException( 'Payment settings changed. Reload before saving again.' ); }
			$operation = $_POST['operation'] ?? '';
			if ( 'archive' === $operation ) { Hub_Accounts::archive( $id, (int) $revision ); }
			elseif ( 'enable' === $operation || 'disable' === $operation ) { Hub_Accounts::toggle( $id, 'enable' === $operation, (int) $revision ); }
			else { throw new \InvalidArgumentException( 'Payment settings are invalid.' ); }
			self::flash( 'success', 'archive' === $operation ? '付款方式已归档并停止新支付。在途记录和结果回写继续保留。' : '收款状态已更新，授权店铺会自动获取。' );
		} catch ( \Throwable $error ) {
			self::flash( 'error', self::validation_message( $error->getMessage() ) );
		}
		wp_safe_redirect( self::url() );
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
			'Payment settings changed. Reload before saving again.' => '其他窗口已更新付款方式。本次没有覆盖修改，请刷新后重新编辑。',
			'Payment account was not found.' => '找不到这个付款方式，请刷新列表后重新选择。',
			'Archived payment accounts cannot be edited.' => '这个付款方式已归档，请添加新的付款方式。',
			'The payment account limit has been reached.' => '最多保留 20 个付款方式，包括已归档记录。请联系管理员处理容量。',
			'Payment protocol is invalid.' => '当前仅支持已提供文档的网关协议，请核对接入资料。',
			'Panapay gateway must be a public HTTPS origin.' => '网关须填写可公开访问的 HTTPS 地址，不包含路径或查询参数。',
			'Payment host trust must contain no more than 32 unique hosts including the gateway.' => '可信收银台域名与网关域名合计最多 32 个，请减少额外域名后重试。',
			'Payment settings were not saved.' => '付款方式未保存，请稍后重试。',
			'payment_database_unavailable' => '支付配置数据库暂时不可用，请稍后重试。',
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
		$accounts = array(); $settings_error = false; $ledger = null; $summary = null;
		try { $accounts = Hub_Accounts::all(); } catch ( \Throwable $error ) { $settings_error = true; }
		try {
			if ( is_callable( array( Hub_Store::class, 'attempts' ) ) && Hub_Store::installed() ) {
				$ledger = Hub_Store::attempts( self::filters( $site_id, $state ), $page );
				$summary = Hub_Store::summary( $site_id );
			}
		} catch ( \Throwable $error ) { $ledger = null; $summary = null; }
		$active_count = count( array_filter( $accounts, static function ( array $row ): bool { return self::is_on( $row['enabled'] ?? 'no' ) && ! self::is_on( $row['archived'] ?? 'no' ); } ) );
		?>
		<div class="wrap pchq pc-pay-hub">
			<div class="pc-pay-heading"><div><h1>支付中心</h1><p class="pc-pay-muted">统一收款，按店铺记录订单和金额。</p></div><span class="pc-pay-state"><?php echo esc_html( '已开启 ' . $active_count . ' 个付款方式' ); ?></span></div>
			<?php $notice = self::notices(); ?>
			<nav class="pc-pay-jump" aria-label="支付中心分区"><a href="#pc-pay-settings">付款方式</a><a href="#pc-pay-ledger">交易记录</a></nav>
			<section id="pc-pay-settings" class="pc-pay-section pc-pay-onboarding" aria-labelledby="pc-pay-settings-title">
				<h2 id="pc-pay-settings-title">付款方式</h2>
				<?php if ( $settings_error ) : ?><p role="alert">无法读取已保存的支付设置。请检查服务器配置，不要重复填写密钥。</p><?php else : ?><?php self::render_settings( $accounts, $sites, $notice ); ?><?php endif; ?>
			</section>
			<section id="pc-pay-ledger" class="pc-pay-section" aria-labelledby="pc-pay-ledger-title">
				<div class="pc-pay-section-head"><h2 id="pc-pay-ledger-title">交易记录</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="pc_panapay_hub_retry"><?php wp_nonce_field( 'pc_panapay_hub_retry' ); ?><button type="submit" class="button" <?php disabled( null === $ledger ); ?>>重试待回写结果</button></form></div>
				<p class="pc-pay-muted">支付方确认与店铺订单更新分别记录。回写失败会保留记录重试，不会重新扣款。</p>
				<?php if ( is_array( $summary ) ) { self::render_summary( $summary ); } ?>
				<form method="get" class="pc-pay-filters"><input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"><label>店铺<select name="site_id"><option value="0">全部店铺</option><?php foreach ( $sites as $site ) : ?><option value="<?php echo esc_attr( (string) $site['id'] ); ?>" <?php selected( $site_id, $site['id'] ); ?>><?php echo esc_html( $site['name'] ); ?></option><?php endforeach; ?><?php if ( $site_id && ! isset( $sites[ $site_id ] ) ) : ?><option value="<?php echo esc_attr( (string) $site_id ); ?>" selected>已移除店铺 #<?php echo esc_html( (string) $site_id ); ?></option><?php endif; ?></select></label><label>支付状态<select name="state"><option value="">全部状态</option><?php foreach ( self::states() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><button type="submit" class="button">筛选</button></form>
				<?php if ( null === $ledger ) : ?><div class="pc-pay-empty" role="status"><h3>暂时无法读取交易记录</h3><p>请刷新页面；若仍不可用，请检查支付插件是否已完成数据库升级。</p><a class="button" href="<?php echo esc_url( self::url() ); ?>">刷新记录</a></div><?php elseif ( empty( $ledger['rows'] ) ) : ?><div class="pc-pay-empty"><h3><?php echo esc_html( $site_id || $state ? '没有符合条件的交易' : '还没有付款记录' ); ?></h3><p><?php echo esc_html( $site_id || $state ? '调整店铺或支付状态后重新筛选。' : '在支付设置中配置收款并授权店铺后，新支付会记录在这里。' ); ?></p></div><?php else : ?>
					<div class="pc-pay-table-scroll" role="region" aria-label="按店铺划分的支付记录" tabindex="0"><table class="widefat pc-pay-table"><thead><tr><th scope="col">店铺 / 订单</th><th scope="col">付款方式</th><th scope="col">支付引用</th><th scope="col" class="pc-pay-number">金额</th><th scope="col">支付方确认</th><th scope="col">店铺回写</th><th scope="col">更新时间</th></tr></thead><tbody><?php foreach ( $ledger['rows'] as $row ) { self::render_row( $row, $sites, $accounts ); } ?></tbody></table></div>
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

	private static function render_row( array $row, array $sites, array $accounts ): void {
		$id = (int) ( $row['site_id'] ?? 0 );
		$name = isset( $sites[ $id ] ) ? $sites[ $id ]['name'] : '已移除店铺 #' . $id;
		$account_id = (string) ( $row['account_id'] ?? 'default' );
		$account_label = (string) ( $accounts[ $account_id ]['account_name'] ?? '' );
		if ( '' === $account_label ) { $account_label = (string) ( $accounts[ $account_id ]['title'] ?? ( 'default' === $account_id ? '原收款账号' : $account_id ) ); }
		$provider = self::states()[ $row['state'] ?? '' ] ?? '状态待核对';
		if ( 'SUCCEEDED' === ( $row['event_status'] ?? '' ) ) { $provider = '已确认付款'; }
		if ( 'FAILED' === ( $row['event_status'] ?? '' ) ) { $provider = '已确认失败'; }
		$delivery = array( 'pending' => '等待回写', 'delivered' => '已回写', 'review' => '需要核对', 'blocked' => '连接待核对', '' => '尚无付款结果' )[ $row['delivery_state'] ?? '' ] ?? '状态待核对';
		$disposition = array( 'paid' => '订单已付款', 'failed' => '订单未付款', 'review' => '订单需核对' )[ $row['disposition'] ?? '' ] ?? '';
		echo '<tr><td><strong>' . esc_html( $name ) . '</strong><span>订单 #' . esc_html( (string) (int) ( $row['order_id'] ?? 0 ) ) . '</span></td><td>' . esc_html( $account_label ) . '</td><td class="pc-pay-reference">' . esc_html( (string) ( $row['ref'] ?? '' ) ) . '</td><td class="pc-pay-number">' . esc_html( (string) ( $row['currency'] ?? '' ) ) . ' ' . esc_html( (string) ( $row['amount'] ?? '' ) ) . '</td><td>' . esc_html( $provider ) . '</td><td>' . esc_html( $delivery );
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

	private static function render_settings( array $accounts, array $sites, ?array $notice = null ): void {
		$revision = $accounts ? (int) reset( $accounts )['revision'] : 0;
		$editing = isset( $_GET['edit_account'] ) && is_string( $_GET['edit_account'] ) ? wp_unslash( $_GET['edit_account'] ) : '';
		$edit_valid = '' !== $editing && isset( $accounts[ $editing ] ) && ! self::is_on( $accounts[ $editing ]['archived'] ?? 'no' );
		$raw = $edit_valid ? $accounts[ $editing ] : Hub_Accounts::defaults();
		$id = $edit_valid ? $editing : '';
		$auto_open = isset( $_GET['new_account'] ) || isset( $_GET['edit_account'] );
		$account_error = isset( $_GET['account_error'] ) && '1' === $_GET['account_error'] && is_array( $notice ) && 'error' === $notice['type'];
		$authorized = is_array( $raw['sites'] ?? null ) ? array_map( 'intval', $raw['sites'] ) : array();
		$hosts = is_array( $raw['payment_hosts'] ) ? implode( "\n", $raw['payment_hosts'] ) : (string) $raw['payment_hosts'];
		$catalog = self::icon_catalog();
		$selected_icons = $raw['payment_icons'];
		$has_secret = ( 'default' === $id && Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) ) || '' !== $raw['secret'];
		?>
		<div class="pc-pay-method-heading"><p class="pc-pay-muted">添加支付方提供的收款参数，保存后自动同步至授权店铺。每种付款方式单独记录。</p>
			<?php if ( count( $accounts ) < 20 ) : ?><a class="button button-primary" href="<?php echo esc_url( self::url( array( 'new_account' => '1' ) ) ); ?>" <?php echo '' === $id ? 'data-pc-pay-open' : ''; ?>>添加付款方式</a><?php else : ?><span class="pc-pay-muted">已达 20 个付款方式上限（含归档）</span><?php endif; ?>
		</div>
		<div class="pc-pay-accounts" aria-label="已添加的付款方式">
		<?php foreach ( $accounts as $account_id => $account ) :
			$archived = self::is_on( $account['archived'] );
			$enabled = self::is_on( $account['enabled'] ) && ! $archived;
			$label = '' !== $account['account_name'] ? $account['account_name'] : $account['title'];
			?>
			<div class="pc-pay-method-row<?php echo $archived ? ' is-archived' : ''; ?>">
				<div class="pc-pay-account-copy"><div class="pc-pay-account-title"><h3><?php echo esc_html( $label ); ?></h3><span class="pc-pay-state"><?php echo esc_html( $archived ? '已归档' : ( $enabled ? '已开启' : '已关闭' ) ); ?></span><?php if ( self::is_on( $account['test_mode'] ) ) : ?><span class="pc-pay-state">测试模式</span><?php endif; ?></div>
					<p class="pc-pay-muted"><?php echo esc_html( $account['title'] ); ?> · <?php echo esc_html( self::is_on( $account['all_sites'] ) ? '全部已接入店铺' : '指定 ' . count( $account['sites'] ) . ' 个店铺' ); ?></p>
				</div>
				<?php if ( ! $archived ) : ?><div class="pc-pay-method-actions"><a class="button" href="<?php echo esc_url( self::url( array( 'edit_account' => $account_id ) ) ); ?>" <?php echo $id === $account_id ? 'data-pc-pay-open' : ''; ?>>编辑</a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php self::action_fields( $account_id, $revision ); ?><button class="button" type="submit" name="operation" value="<?php echo $enabled ? 'disable' : 'enable'; ?>"><?php echo $enabled ? '关闭' : '开启'; ?></button></form>
					<details class="pc-pay-archive"><summary>更多</summary><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php self::action_fields( $account_id, $revision ); ?><p>归档后停止新支付，保留历史记录与在途结果。</p><button class="button" type="submit" name="operation" value="archive">归档付款方式</button></form></details>
				</div><?php endif; ?>
			</div>
		<?php endforeach; ?>
		</div>
		<?php if ( '' !== $editing && ! $edit_valid ) : ?><p role="alert">这个付款方式不存在或已归档，请从列表重新选择。</p><?php endif; ?>
		<dialog id="pc-pay-account-dialog" class="pc-pay-account-dialog" open aria-labelledby="pc-pay-account-title" aria-describedby="pc-pay-account-help" data-pc-pay-account-dialog data-auto-open="<?php echo $auto_open && ( '' === $editing || $edit_valid ) ? '1' : '0'; ?>">
		<form id="pc-pay-config" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-pay-config">
			<input type="hidden" name="action" value="pc_panapay_hub_save"><input type="hidden" name="account_id" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="revision" value="<?php echo esc_attr( (string) $revision ); ?>"><input type="hidden" name="protocol" value="panapay"><?php wp_nonce_field( 'pc_panapay_hub_save' ); ?>
			<header class="pc-pay-dialog-header"><div><h2 id="pc-pay-account-title"><?php echo '' === $id ? '添加付款方式' : '编辑付款方式'; ?></h2><p id="pc-pay-account-help">接入协议：网关协议（当前文档）</p></div><button type="button" class="pc-pay-dialog-close" aria-label="取消编辑并关闭" data-pc-pay-cancel><svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button></header>
			<div class="pc-pay-dialog-scroll">
				<?php if ( $account_error ) : ?><p role="alert" class="pc-pay-dialog-error"><?php echo esc_html( $notice['message'] ); ?> 输入没有保存，请核对后重新填写。</p><?php endif; ?>
				<fieldset class="pc-pay-account-fields"><legend>付款资料</legend><div class="pc-pay-fields">
					<?php self::field( 'account_name', '内部名称', $raw, '例如：主收款账号', '仅总后台可见，用于区分不同账号。', 120 ); ?>
					<?php self::field( 'title', '买家看到的付款名称', $raw, '例如：Credit card', '', 120 ); ?>
					<?php self::field( 'gateway', '网关地址', $raw, 'https://gateway.example.com', '填写支付方提供的 HTTPS 地址。', 2048 ); ?>
					<?php self::field( 'mchid', '商户号', $raw, '', '', 128 ); ?>
					<div class="pc-pay-field"><label for="pc-pay-secret">签名密钥</label><input type="password" id="pc-pay-secret" name="secret" value="" autocomplete="new-password" maxlength="1024" <?php disabled( 'default' === $id && Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) ); ?>><p class="pc-pay-muted"><?php echo $has_secret ? '已有密钥，留空保留。密钥不会回显。' : '由支付方提供，仅加密保存在总后台。'; ?></p></div>
					<?php self::field( 'tag', '渠道标识（tag）', $raw, '', '支付方要求时填写。', 255 ); ?>
					<div class="pc-pay-field"><label for="pc-pay-mode">请求模式</label><select id="pc-pay-mode" name="mode"><option value="2" <?php selected( $raw['mode'], '2' ); ?>>异步（2）</option><option value="1" <?php selected( $raw['mode'], '1' ); ?>>同步（1）</option></select><p class="pc-pay-muted">按支付方要求选择；浏览器跳转不会标记订单已付款。</p></div>
					<div class="pc-pay-field"><label for="pc-pay-height">嵌入收银台高度</label><div class="pc-pay-number-unit"><input id="pc-pay-height" type="number" name="iframe_height" min="320" max="1600" step="1" required value="<?php echo esc_attr( (string) $raw['iframe_height'] ); ?>"><span>px</span></div><p class="pc-pay-muted">支付方返回嵌入方式时生效，默认 530。</p></div>
					<div class="pc-pay-field pc-pay-field-wide"><label for="pc-pay-description">付款说明</label><textarea id="pc-pay-description" name="description" maxlength="1000" rows="2"><?php echo esc_textarea( $raw['description'] ); ?></textarea></div>
				</div></fieldset>
				<fieldset class="pc-pay-account-fields pc-pay-switches"><legend>收款与店铺</legend>
					<input type="hidden" name="enabled" value="no"><label><input type="checkbox" name="enabled" value="yes" <?php checked( self::is_on( $raw['enabled'] ) ); ?>>开启此付款方式</label><p class="pc-pay-muted">关闭后停止新支付，在途付款继续回写。</p>
					<input type="hidden" name="all_sites" value="no"><label><input type="checkbox" name="all_sites" value="yes" data-pc-pay-all-sites <?php checked( self::is_on( $raw['all_sites'] ) ); ?>>同步到全部已接入店铺</label><p class="pc-pay-muted">包括后续接入的店铺；各店只能取得付款展示配置。</p>
					<details class="pc-pay-site-picker" <?php echo self::is_on( $raw['all_sites'] ) ? '' : 'open'; ?>><summary>指定店铺（未选择全部时生效）</summary><div class="pc-pay-store-options"><?php foreach ( $sites as $site ) : ?><label class="pc-pay-store-option"><input type="checkbox" name="sites[]" value="<?php echo esc_attr( (string) $site['id'] ); ?>" <?php checked( in_array( (int) $site['id'], $authorized, true ) ); ?>><span><strong><?php echo esc_html( $site['name'] ); ?></strong><small><?php echo esc_html( $site['url'] ); ?></small></span></label><?php endforeach; ?><?php if ( ! $sites ) : ?><p class="pc-pay-muted">暂无已接入店铺。</p><?php endif; ?></div></details>
				</fieldset>
				<details class="pc-pay-advanced"><summary>更多展示与连接设置</summary><div class="pc-pay-fields">
					<div class="pc-pay-field pc-pay-field-wide"><input type="hidden" name="test_mode" value="no"><label class="pc-pay-inline-check"><input type="checkbox" name="test_mode" value="yes" <?php checked( self::is_on( $raw['test_mode'] ) ); ?>>测试模式</label><p class="pc-pay-muted">需使用支付方测试账号；此开关不会自动切换网关环境。</p></div>
					<div class="pc-pay-field pc-pay-field-wide"><label for="pc-pay-hosts">可信收银台域名</label><textarea id="pc-pay-hosts" name="payment_hosts" rows="3" maxlength="8192"><?php echo esc_textarea( $hosts ); ?></textarea><p class="pc-pay-muted">每行一个已确认的精确域名；网关域名自动纳入。</p></div>
					<fieldset class="pc-pay-field pc-pay-field-wide pc-pay-icon-field"><legend>结账展示图标（可选）</legend><input type="hidden" name="payment_icons_present" value="1"><div class="pc-pay-icon-options"><?php foreach ( $catalog as $icon_id => $label ) : ?><label class="pc-pay-icon-option"><input type="checkbox" name="payment_icons[]" value="<?php echo esc_attr( $icon_id ); ?>" <?php checked( in_array( $icon_id, $selected_icons, true ) ); ?>><?php $url = self::icon_url( $icon_id ); if ( '' !== $url ) : ?><img src="<?php echo esc_url( $url ); ?>" alt="" width="54" height="32"><?php endif; ?><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></div><p class="pc-pay-muted">仅为图标，实际可用渠道由支付方账号决定。</p></fieldset>
				</div></details>
			</div>
			<footer class="pc-pay-dialog-footer"><p>保存后自动同步至授权店铺。</p><div><a class="button" href="<?php echo esc_url( self::url() ); ?>" data-pc-pay-cancel>取消</a><button class="button button-primary" type="submit">保存付款方式</button></div></footer>
		</form></dialog>
		<?php
	}

	private static function action_fields( string $id, int $revision ): void {
		echo '<input type="hidden" name="action" value="pc_panapay_hub_account_action"><input type="hidden" name="account_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="revision" value="' . esc_attr( (string) $revision ) . '">';
		wp_nonce_field( 'pc_panapay_hub_account_action' );
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
		$server = in_array( $name, array( 'gateway', 'mchid' ), true ) && 'default' === ( $raw['id'] ?? '' ) && Hub_Settings::server_override( 'PC_PANAPAY_' . strtoupper( $name ) );
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
