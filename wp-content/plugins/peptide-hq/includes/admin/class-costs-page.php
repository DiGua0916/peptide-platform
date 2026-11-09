<?php
/**
 * 总后台 → 费用（2026-11-09）。看两台 AWS 服务器和 Cloudflare 的费用，设预算、余额和提醒邮箱。
 * 数据来源和提醒规则见 PeptideHq\Costs。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Costs;

defined( 'ABSPATH' ) || exit;

final class Costs_Page {

	public const SLUG = 'pchq-costs';

	/** AWS 服务名 → 中文。 */
	private const SERVICES = array(
		'Amazon Elastic Compute Cloud - Compute' => '服务器（EC2 计算）',
		'EC2 - Other'                            => '服务器附属（硬盘、快照、流量）',
		'Amazon Virtual Private Cloud'           => '网络（VPC / 公网 IP）',
		'Amazon Simple Storage Service'          => '对象存储（S3）',
		'AWS Cost Explorer'                      => '费用查询接口（本页调用）',
		'Amazon Route 53'                        => '域名解析（Route 53）',
		'AWS Data Transfer'                      => '数据传输',
		'Amazon CloudWatch'                      => '监控（CloudWatch）',
		'AmazonCloudWatch'                       => '监控（CloudWatch）',
		'Amazon Elastic Block Store'             => '硬盘（EBS）',
		'AWS Key Management Service'             => '密钥管理（KMS）',
		'Tax'                                    => '税费',
		'AWS Support (Developer)'                => '技术支持（Developer）',
		'AWS Support (Business)'                 => '技术支持（Business）',
	);

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '费用', '费用', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			22
		);
		foreach ( array( 'save', 'forget_aws', 'refresh' ) as $do ) {
			add_action( 'admin_post_pchq_costs_' . $do, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'pchq-costs', plugins_url( 'assets/hq-costs.css', PEPTIDE_HQ_FILE ), array( 'pchq-plugins' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-costs.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_costs_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权查看费用。', '', array( 'response' => 403 ) );
		}
		$do = substr( (string) current_action(), strlen( 'admin_post_pchq_costs_' ) );
		check_admin_referer( 'pchq_costs_' . $do );
		switch ( $do ) {
			case 'save':
				$res = Costs::update( array(
					'aws_key_id' => (string) wp_unslash( $_POST['aws_key_id'] ?? '' ),
					'aws_secret' => (string) wp_unslash( $_POST['aws_secret'] ?? '' ),
					'budget'     => (string) wp_unslash( $_POST['budget'] ?? '' ),
					'balance'    => (string) wp_unslash( $_POST['balance'] ?? '' ),
					'email'      => (string) wp_unslash( $_POST['email'] ?? '' ),
				) );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
				} else {
					$snap = Costs::refresh();
					$err  = (string) ( $snap['aws']['error'] ?? '' );
					self::flash( '' === $err ? 'success' : 'error', '已保存。' . ( '' === $err ? '费用数据已刷新。' : 'AWS 数据没拉到：' . $err ) );
				}
				break;
			case 'forget_aws':
				Costs::forget_aws();
				self::flash( 'success', '已删除保存的 AWS 密钥。记得到 AWS 控制台把这把密钥也停用。' );
				break;
			case 'refresh':
				$snap = Costs::refresh();
				$errs = array_filter( array( (string) ( $snap['aws']['error'] ?? '' ), (string) ( $snap['cloudflare']['error'] ?? '' ) ) );
				self::flash( $errs ? 'error' : 'success', $errs ? '已刷新，但有问题：' . implode( ' ', $errs ) : '已刷新。' );
				break;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function form( string $do, string $label, string $class = 'button', string $confirm = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . wp_nonce_field( 'pchq_costs_' . $do, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="pchq_costs_' . esc_attr( $do ) . '"><button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function usd( $v ): string {
		return '$' . number_format( (float) $v, 2 );
	}

	private static function bytes( $b ): string {
		$b = (float) $b;
		if ( $b >= 1073741824 ) {
			return number_format( $b / 1073741824, 2 ) . ' GB';
		}
		if ( $b >= 1048576 ) {
			return number_format( $b / 1048576, 1 ) . ' MB';
		}
		return number_format( $b / 1024, 0 ) . ' KB';
	}

	private static function service_name( string $s ): string {
		return self::SERVICES[ $s ] ?? $s;
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$s     = Costs::settings();
		$snap  = Costs::snapshot();
		$flash = get_transient( 'pchq_costs_flash_' . get_current_user_id() );
		delete_transient( 'pchq_costs_flash_' . get_current_user_id() );
		$aws = is_array( $snap['aws'] ?? null ) ? $snap['aws'] : array( 'error' => '还没有数据。' );
		$cf  = is_array( $snap['cloudflare'] ?? null ) ? $snap['cloudflare'] : array( 'error' => '还没有数据。', 'zones' => array() );
		$sum = is_array( $snap['summary'] ?? null ) ? $snap['summary'] : array();
		$remaining = Costs::remaining_balance( $snap );

		echo '<div class="wrap pchq pcp pcp-costs">';
		echo '<header class="pcp-head"><div><h1>费用</h1><p>两台 AWS 服务器（Cost Explorer，滞后约一天）和 Cloudflare（当月流量、Argo 预估、历史账单）。每 8 小时自动更新一次。</p></div>';
		echo '<div class="pcp-costs__tools">' . ( $snap ? '<span class="pcp-state ' . ( empty( $aws['error'] ) ? 'is-ok' : 'is-off' ) . '"><i></i>更新于 ' . esc_html( wp_date( 'm-d H:i', (int) $snap['at'] ) ) . '</span>' : '' ) . self::form( 'refresh', '立即刷新', 'button' ) . '</div></header>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}

		// 先给两个数：本月已花、本月预计。其他都收在“查看明细”里。
		$spent    = $sum ? (float) ( $sum['spent'] ?? 0 ) : null;
		$forecast = $sum ? (float) ( $sum['forecast'] ?? 0 ) : null;
		$partial  = ! empty( $aws['error'] ) || ! empty( $cf['error'] );
		$over     = $s['budget'] > 0 && null !== $forecast && $forecast > $s['budget'];
		$low      = null !== $remaining && null !== $forecast && $remaining < $forecast;
		echo '<section class="pcp-card pcp-costs__hero' . ( $over || $low ? ' is-bad' : '' ) . '">';
		echo '<div class="pcp-costs__big"><div><span>本月已花</span><strong>' . ( null !== $spent ? esc_html( self::usd( $spent ) ) : '—' ) . '</strong><small>' . esc_html( wp_date( 'n 月 1 日' ) ) . ' 至今' . ( empty( $aws['error'] ) && ! empty( $aws['data_through'] ) ? '，AWS 数据到 ' . esc_html( substr( (string) $aws['data_through'], 5 ) ) : '' ) . '</small></div>';
		echo '<div><span>本月预计</span><strong>' . ( null !== $forecast ? esc_html( self::usd( $forecast ) ) : '—' ) . '</strong><small>' . ( $s['budget'] > 0 ? '预算 ' . esc_html( self::usd( $s['budget'] ) ) . ( $over ? '，<b>超出</b>' : '，在预算内' ) : '没设预算' ) . '</small></div>';
		if ( null !== $remaining ) {
			echo '<div><span>余额约剩</span><strong>' . esc_html( self::usd( max( 0, $remaining ) ) ) . '</strong><small>' . ( $low ? '<b>不够下个月，请充值</b>' : '够下个月' ) . '</small></div>';
		}
		echo '</div>';
		echo '<ul class="pcp-costs__lines">';
		echo '<li><span>AWS 两台服务器</span><em>' . ( empty( $aws['error'] ) ? esc_html( self::usd( $aws['month_to_date'] ) . ' 已花 · 预计 ' . self::usd( $aws['forecast'] ) ) : '<i class="pcp-costs__warn">' . esc_html( (string) $aws['error'] ) . '</i>' ) . '</em></li>';
		$argo_on = array_values( array_filter( (array) ( $cf['zones'] ?? array() ), static fn( $z ) => 'on' === $z['argo'] ) );
		echo '<li><span>Cloudflare Argo' . ( $argo_on ? '（' . esc_html( implode( '、', array_column( $argo_on, 'name' ) ) ) . '）' : '' ) . '</span><em>' . ( empty( $cf['error'] ) ? esc_html( self::usd( $cf['argo_estimate'] ?? 0 ) . ' 已产生 · 预计 ' . self::usd( $cf['argo_forecast'] ?? 0 ) ) : '<i class="pcp-costs__warn">' . esc_html( (string) $cf['error'] ) . '</i>' ) . '</em></li>';
		echo '<li><span>Cloudflare 域名套餐</span><em>$0.00 · 全部免费版</em></li>';
		echo '</ul>';
		if ( $partial ) {
			echo '<p class="pcp-costs__warn">数字还不完整：' . esc_html( implode( ' ', array_filter( array( ! empty( $aws['error'] ) ? 'AWS 没接上。' : '', ! empty( $cf['analytics_error'] ) ? 'Cloudflare 流量数据没权限，Argo 只按固定 $5 算。' : '' ) ) ) ) . ' 展开下面“设置”处理。</p>';
		} elseif ( ! empty( $cf['analytics_error'] ) ) {
			echo '<p class="pcp-costs__warn">Cloudflare 流量数据没权限，Argo 只按固定 $5 算。展开下面“设置”处理。</p>';
		}
		echo '</section>';

		// 明细：AWS。
		echo '<details class="pcp-card pcp-costs__detail"><summary><h2>AWS 服务器明细' . ( '' !== $s['aws_account'] ? ' <span class="pcp-tag">账号 ' . esc_html( $s['aws_account'] ) . '</span>' : '' ) . '</h2><span class="pcp-hint">' . ( empty( $aws['error'] ) ? '按项目拆分、近 30 天每日费用，预测' . ( 'aws' === ( $aws['forecast_source'] ?? '' ) ? '来自 AWS' : '按日均推算' ) : '未连接' ) . '</span></summary>';
		if ( ! empty( $aws['error'] ) ) {
			echo '<div class="pcp-flash is-wait">' . esc_html( (string) $aws['error'] ) . ( Costs::has_aws() ? '' : ' 在“设置”里填入只读访问密钥即可。' ) . '</div>';
		} else {
			$daily = (array) ( $aws['daily'] ?? array() );
			krsort( $daily );
			$daily = array_slice( $daily, 0, 30, true );
			ksort( $daily );
			$max = max( 0.01, (float) max( $daily ?: array( 0 ) ) );
			echo '<div class="pcp-costs__chart" role="img" aria-label="最近 30 天每天的 AWS 费用">';
			foreach ( $daily as $d => $v ) {
				$h = max( 2, (int) round( (float) $v / $max * 100 ) );
				echo '<span class="pcp-costs__bar' . ( $d >= gmdate( 'Y-m-01' ) ? ' is-month' : '' ) . '" style="height:' . $h . '%" title="' . esc_attr( $d . '  ' . self::usd( $v ) ) . '"></span>';
			}
			echo '</div><p class="pcp-costs__axis"><span>' . esc_html( (string) array_key_first( $daily ) ) . '</span><span>日费用，深色 = 本月</span><span>' . esc_html( (string) array_key_last( $daily ) ) . '</span></p>';
			echo '<div class="pcp-scroll"><table class="pcp-matrix pcp-costs__table"><thead><tr><th scope="col">项目</th><th scope="col">本月（到昨天）</th><th scope="col">上月</th></tr></thead><tbody>';
			foreach ( (array) ( $aws['services'] ?? array() ) as $svc => $row ) {
				if ( (float) $row['month'] < 0.005 && (float) $row['prev'] < 0.005 ) {
					continue;
				}
				echo '<tr><td><strong>' . esc_html( self::service_name( (string) $svc ) ) . '</strong>' . ( isset( self::SERVICES[ $svc ] ) ? '<small>' . esc_html( (string) $svc ) . '</small>' : '' ) . '</td><td>' . esc_html( self::usd( $row['month'] ) ) . '</td><td>' . esc_html( self::usd( $row['prev'] ) ) . '</td></tr>';
			}
			echo '<tr class="is-total"><td>合计</td><td>' . esc_html( self::usd( $aws['month_to_date'] ) ) . '</td><td>' . esc_html( self::usd( $aws['previous_month'] ) ) . '</td></tr>';
			echo '</tbody></table></div>';
			echo '<p class="pcp-note">AWS 是后付费，每月初从绑定的卡扣上个月的费用；以 AWS 账单为准。Cost Explorer 每次查询 AWS 收 $0.01，本页每天自动查 6 次。</p>';
		}
		echo '</details>';

		// 明细：Cloudflare。
		$zones    = (array) ( $cf['zones'] ?? array() );
		$visible  = array_values( array_filter( $zones, static fn( $z ) => 'on' === $z['argo'] || $z['bytes'] > 0 ) );
		$hidden_n = count( $zones ) - count( $visible );
		echo '<details class="pcp-card pcp-costs__detail"><summary><h2>Cloudflare 明细</h2><span class="pcp-hint">Argo：前 1 GB $5/月，之后每 GB $0.10；各域名流量与账单</span></summary>';
		if ( ! empty( $cf['error'] ) ) {
			echo '<div class="pcp-flash is-wait">' . esc_html( (string) $cf['error'] ) . '</div>';
		} else {
			foreach ( array( 'analytics_error' => '流量数据没拉到', 'argo_error' => 'Argo 状态没查到', 'billing_error' => '账单历史没拉到' ) as $k => $label ) {
				if ( ! empty( $cf[ $k ] ) ) {
					echo '<div class="pcp-flash is-wait">' . esc_html( $label . '：' . $cf[ $k ] ) . '</div>';
				}
			}
			$table = static function ( array $rows ): void {
				echo '<div class="pcp-scroll"><table class="pcp-matrix pcp-costs__table"><thead><tr><th scope="col">域名</th><th scope="col">本月流量</th><th scope="col">请求数</th><th scope="col">缓存命中</th><th scope="col">Argo</th><th scope="col">Argo 费用</th></tr></thead><tbody>';
				foreach ( $rows as $z ) {
					$hit  = $z['bytes'] > 0 ? round( $z['cached'] / $z['bytes'] * 100 ) . '%' : '—';
					$argo = array( 'on' => '<span class="pcp-state is-ok"><i></i>已开</span>', 'off' => '<span class="pcp-state is-off"><i></i>未开</span>' )[ $z['argo'] ] ?? '<span class="pcp-state is-off"><i></i>未知</span>';
					echo '<tr><td><strong>' . esc_html( $z['name'] ) . '</strong></td><td>' . esc_html( self::bytes( $z['bytes'] ) ) . '</td><td>' . esc_html( number_format( (int) $z['requests'] ) ) . '</td><td>' . esc_html( $hit ) . '</td><td>' . $argo . '</td><td>' . ( isset( $z['argo_estimate'] ) ? esc_html( self::usd( $z['argo_estimate'] ) . '，月底约 ' . self::usd( $z['argo_forecast'] ) ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</tbody></table></div>';
			};
			if ( $visible ) {
				$table( $visible );
			} else {
				echo '<p class="pcp-note">本月还没有域名产生流量数据或开通 Argo。</p>';
			}
			if ( $hidden_n > 0 ) {
				echo '<details class="pcp-details"><summary>其余 ' . (int) $hidden_n . ' 个域名（免费版、没开 Argo、本月没流量）</summary>';
				$table( array_values( array_filter( $zones, static fn( $z ) => ! ( 'on' === $z['argo'] || $z['bytes'] > 0 ) ) ) );
				echo '</details>';
			}
			if ( ! empty( $cf['bills'] ) ) {
				echo '<h3 class="pcp-costs__sub">账单历史</h3><div class="pcp-scroll"><table class="pcp-matrix pcp-costs__table"><thead><tr><th scope="col">日期</th><th scope="col">项目</th><th scope="col">域名</th><th scope="col">金额</th></tr></thead><tbody>';
				foreach ( $cf['bills'] as $b ) {
					echo '<tr><td>' . esc_html( substr( $b['at'], 0, 10 ) ) . '</td><td>' . esc_html( $b['description'] ) . '</td><td>' . esc_html( $b['zone'] ?: '—' ) . '</td><td>' . esc_html( self::usd( $b['amount'] ) . ' ' . $b['currency'] ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			} elseif ( empty( $cf['billing_error'] ) ) {
				echo '<p class="pcp-note">还没有账单记录。开了 Argo 后，每月账单会出现在这里。</p>';
			}
		}
		echo '</details>';

		// 设置。
		echo '<details class="pcp-card pcp-costs__detail"' . ( $partial ? ' open' : '' ) . '><summary><h2>设置</h2>' . ( Costs::has_aws() ? '<span class="pcp-state is-ok"><i></i>AWS 已连接' . ( $s['aws_verified'] ? ' · ' . esc_html( wp_date( 'Y-m-d H:i', $s['aws_verified'] ) ) : '' ) . '</span>' : '<span class="pcp-state is-off"><i></i>AWS 未连接</span>' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-cdn-form pcp-costs__form">';
		wp_nonce_field( 'pchq_costs_save' );
		echo '<input type="hidden" name="action" value="pchq_costs_save">';
		echo '<div class="pcp-costs__grid">';
		echo '<label class="pcp-field"><span>AWS 访问密钥 ID</span><input type="text" name="aws_key_id" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( '' !== $s['aws_key_id'] ? '已保存 ' . substr( $s['aws_key_id'], 0, 4 ) . '…' . substr( $s['aws_key_id'], -4 ) . '（留空则不更换）' : 'AKIA…' ) . '"></label>';
		echo '<label class="pcp-field"><span>AWS 私有访问密钥</span><input type="password" name="aws_secret" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( Costs::has_aws() ? '已保存（留空则不更换）' : '粘贴 Secret access key' ) . '"></label>';
		echo '<label class="pcp-field"><span>月预算（美元，0 = 不提醒）</span><input type="text" inputmode="decimal" name="budget" value="' . esc_attr( $s['budget'] > 0 ? number_format( $s['budget'], 2, '.', '' ) : '' ) . '" placeholder="例如 150"></label>';
		echo '<label class="pcp-field"><span>账户余额（美元，预付费账户才填）</span><input type="text" inputmode="decimal" name="balance" value="' . esc_attr( $s['balance'] > 0 ? number_format( $s['balance'], 2, '.', '' ) : '' ) . '" placeholder="充值后填一次当前余额">' . ( $s['balance_at'] ? '<small>填写于 ' . esc_html( wp_date( 'Y-m-d', $s['balance_at'] ) ) . '，之后每天的 AWS 费用自动从中扣减</small>' : '' ) . '</label>';
		echo '<label class="pcp-field"><span>提醒邮箱</span><input type="email" name="email" value="' . esc_attr( $s['email'] ) . '" placeholder="超预算 / 余额不足时发到这里"></label>';
		echo '</div>';
		echo '<p class="pcp-costs__actions"><button type="submit" class="button button-primary">保存并刷新</button></p></form>';
		if ( Costs::has_aws() ) {
			echo self::form( 'forget_aws', '删除保存的 AWS 密钥', 'pcp-link is-danger', '确定删除 AWS 密钥？费用页将不再显示 AWS 数据。' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<details class="pcp-details"><summary>怎么创建 AWS 只读密钥</summary><ol class="pcp-steps">';
		echo '<li>AWS 控制台 → 右上角账号 → <strong>Billing and Cost Management</strong> → 左侧 Cost Explorer，第一次打开点“启用”，等 24 小时才有数据。</li>';
		echo '<li>控制台 → <strong>IAM</strong> → Users → Create user，名字例如 <code>hq-cost-readonly</code>，不要勾选控制台访问。</li>';
		echo '<li>权限选 Attach policies directly → Create policy → JSON，粘贴：</li></ol>';
		echo '<pre class="pcp-costs__code">' . esc_html( "{\n  \"Version\": \"2012-10-17\",\n  \"Statement\": [{\n    \"Effect\": \"Allow\",\n    \"Action\": [\"ce:GetCostAndUsage\", \"ce:GetCostForecast\", \"sts:GetCallerIdentity\"],\n    \"Resource\": \"*\"\n  }]\n}" ) . '</pre>';
		echo '<ol class="pcp-steps" start="4"><li>建好用户后 → Security credentials → Create access key → 选 Application running outside AWS → 复制 Access key 和 Secret access key，填到上面保存。Secret 只显示一次。</li>';
		echo '<li>密钥加密保存在总后台服务器上，页面不再显示。这把密钥只能查费用，不能动服务器。</li></ol></details>';
		echo '<details class="pcp-details"><summary>Cloudflare 令牌要加的权限</summary><ol class="pcp-steps">';
		echo '<li>Cloudflare → 右上角头像 → My Profile → API Tokens → 找到总后台在用的令牌 → Edit。</li>';
		echo '<li>Permissions 加两条：<strong>Zone · Analytics · Read</strong>（流量数据）和 <strong>User · Billing · Read</strong>（账单历史）。保存后回这里点“立即刷新”。</li></ol></details>';
		echo '</details>';
		echo '</div>';
	}
}
