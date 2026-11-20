<?php
/**
 * 总后台 → CDN 加速（2026-11-08）。填 Cloudflare 令牌、打开自动配置、看每个域名的进度。
 * 实际步骤见 PeptideHq\Cdn。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Cdn;

defined( 'ABSPATH' ) || exit;

final class Cdn_Page {

	public const SLUG = 'pchq-cdn';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', 'CDN 加速', 'CDN 加速', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			21
		);
		foreach ( array( 'save', 'account', 'forget', 'check', 'add', 'remove' ) as $do ) {
			add_action( 'admin_post_pchq_cdn_' . $do, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_cdn_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理 CDN。', '', array( 'response' => 403 ) );
		}
		$do = substr( (string) current_action(), strlen( 'admin_post_pchq_cdn_' ) );
		check_admin_referer( 'pchq_cdn_' . $do );
		$domain = Provision_Page::customer_domain( (string) wp_unslash( $_POST['domain'] ?? '' ) );
		switch ( $do ) {
			case 'save':
				$res = Cdn::update( ! empty( $_POST['enabled'] ), ! empty( $_POST['create_zones'] ) );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
				} else {
					self::flash( 'success', '已保存。' . ( Cdn::settings()['enabled'] ? '以后开新店会自动配置 CDN，每 5 分钟检查一次进度。' : '自动配置已关闭。' ) );
				}
				break;
			case 'account':
				$res = Cdn::save_account(
					(string) wp_unslash( $_POST['account'] ?? '' ),
					(string) wp_unslash( $_POST['label'] ?? '' ),
					trim( (string) wp_unslash( $_POST['token'] ?? '' ) )
				);
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
				} else {
					self::flash( 'success', '账号已保存并验证。域名会在下一次处理时自动认出属于哪个账号。' );
				}
				break;
			case 'forget':
				$account = (string) wp_unslash( $_POST['account'] ?? '' );
				if ( '' === $account ) {
					self::flash( 'error', '没有指定要删除的账号。' );
					break;
				}
				Cdn::remove_account( $account );
				self::flash( 'success', '已删除这个账号的令牌。Cloudflare 里已经配好的不受影响。' );
				break;
			case 'check':
				$row  = Cdn::domains()[ $domain ] ?? null;
				$jobs = Cdn::provision_jobs();
				if ( '' === $domain || ( null === $row && ! isset( $jobs[ $domain ] ) ) ) {
					self::flash( 'error', '列表里没有这个域名。' );
					break;
				}
				$job   = $jobs[ $domain ] ?? null;
				$after = Cdn::process( $domain, (string) ( $job['ip'] ?? $row['ip'] ?? '' ), $job ? (bool) $job['ready'] : true, $job ? array( 'source' => 'provision', 'site_id' => (int) $job['site_id'] ) : array( 'source' => (string) ( $row['source'] ?? 'manual' ) ) );
				self::flash( 'error' === ( $after['stage'] ?? '' ) ? 'error' : 'success', $domain . '：' . ( Cdn::STAGES[ $after['stage'] ?? '' ] ?? '' ) . ( '' !== (string) ( $after['message'] ?? '' ) ? ' — ' . $after['message'] : '' ) );
				break;
			case 'add':
				$ip = (string) wp_unslash( $_POST['ip'] ?? '' );
				if ( '' === $domain || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					self::flash( 'error', '请填写根域名（例如 example.com）并选择服务器。' );
					break;
				}
				$after = Cdn::process( $domain, $ip, true, array( 'source' => 'manual' ) );
				self::flash( 'error' === ( $after['stage'] ?? '' ) ? 'error' : 'success', $domain . '：' . ( Cdn::STAGES[ $after['stage'] ?? '' ] ?? '' ) . ( '' !== (string) ( $after['message'] ?? '' ) ? ' — ' . $after['message'] : '' ) );
				break;
			case 'remove':
				Cdn::forget( $domain );
				self::flash( 'success', '已从列表移除（Cloudflare 里的记录没有改动）。' );
				break;
		}
		self::back();
	}

	private static function form( string $do, array $fields, string $label, string $class = 'pcp-link', string $confirm = '' ): string {
		$out = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . wp_nonce_field( 'pchq_cdn_' . $do, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="pchq_cdn_' . esc_attr( $do ) . '">';
		foreach ( $fields as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $out . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$s     = Cdn::settings();
		$flash = get_transient( 'pchq_cdn_flash_' . get_current_user_id() );
		delete_transient( 'pchq_cdn_flash_' . get_current_user_id() );
		$rows = Cdn::domains();
		foreach ( Cdn::provision_jobs() as $domain => $job ) {
			if ( ! isset( $rows[ $domain ] ) ) {
				$rows[ $domain ] = array( 'stage' => 'new', 'source' => 'provision', 'site_id' => $job['site_id'], 'ip' => $job['ip'], 'message' => $s['enabled'] ? '下一次检查时开始处理（最多 5 分钟）。' : '自动配置未开启。' );
			}
		}
		ksort( $rows );

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>CDN 加速</h1><p>开新店绑定客户域名时，自动在 Cloudflare 完成解析和 CDN：先建灰色解析让开店程序申请 HTTPS 证书，店铺开好、证书生效后自动切到橙色（走 CDN），并设好 SSL 和缓存规则。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}

		$accounts = $s['accounts'];
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>Cloudflare 账号</h2>' . ( $accounts ? '<span class="pcp-state is-ok"><i></i>已保存 ' . count( $accounts ) . ' 个账号</span>' : '<span class="pcp-state is-off"><i></i>未设置</span>' ) . '</div>';
		echo '<p class="pcp-note">一个 Cloudflare 账号一条，名字建议填投手名。域名归哪个账号不用手工指定：处理某个域名时系统会拿每个账号的令牌问一遍 Cloudflare，谁能看到这个域名就固定用谁。</p>';
		if ( $accounts ) {
			echo '<div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">账号</th><th scope="col">令牌</th><th scope="col">负责的域名</th><th scope="col">操作</th></tr></thead><tbody>';
			foreach ( $accounts as $id => $account ) {
				$mine = array();
				foreach ( Cdn::domains() as $domain => $row ) {
					if ( (string) ( $row['account'] ?? '' ) === (string) $id ) {
						$mine[] = (string) $domain;
					}
				}
				echo '<tr><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline">' . wp_nonce_field( 'pchq_cdn_account', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="pchq_cdn_account"><input type="hidden" name="account" value="' . esc_attr( (string) $id ) . '"><input name="label" value="' . esc_attr( $account['label'] ) . '" required><input type="password" name="token" autocomplete="new-password" spellcheck="false" placeholder="留空则不更换令牌"><button type="submit" class="pcp-link">保存</button></form></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td><span class="pcp-state is-ok"><i></i>已验证' . ( $account['verified_at'] ? ' · ' . esc_html( wp_date( 'Y-m-d H:i', $account['verified_at'] ) ) : '' ) . '</span></td>';
				echo '<td class="pcp-cdn-msg">' . ( $mine ? esc_html( implode( '、', $mine ) ) : '<small>还没有认领的域名</small>' ) . '</td>';
				echo '<td>' . self::form( 'forget', array( 'account' => (string) $id ), '删除', 'pcp-link is-danger', '确定删除「' . $account['label'] . '」的令牌？它负责的域名会变成没有账号可用，Cloudflare 里已配好的不受影响。' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-cdn-form">';
		wp_nonce_field( 'pchq_cdn_account' );
		echo '<input type="hidden" name="action" value="pchq_cdn_account"><input type="hidden" name="account" value="">';
		echo '<label class="pcp-field"><span>账号名字</span><input name="label" placeholder="例如：老毛" autocomplete="off" required></label>';
		echo '<label class="pcp-field"><span>API 令牌</span><input type="password" name="token" autocomplete="new-password" spellcheck="false" placeholder="粘贴 Cloudflare API 令牌" required></label>';
		echo '<p><button type="submit" class="button button-primary">添加账号并验证</button></p></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-cdn-form">';
		wp_nonce_field( 'pchq_cdn_save' );
		echo '<input type="hidden" name="action" value="pchq_cdn_save">';
		echo '<label class="pcp-check"><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 开新店时自动配置 CDN</label>';
		echo '<label class="pcp-check"><input type="checkbox" name="create_zones" value="1"' . checked( $s['create_zones'], true, false ) . '> 域名还不在 Cloudflare 时自动添加（之后需要你到注册商改 NS）</label>';
		echo '<p><button type="submit" class="button">保存开关</button></p></form>';
		echo '<details class="pcp-details"><summary>怎么创建令牌</summary><ol class="pcp-steps">';
		echo '<li>登录 Cloudflare → 右上角头像 → My Profile → API Tokens → Create Token → Create Custom Token。</li>';
		echo '<li>权限（Permissions）添加：Zone · Zone · Read；Zone · DNS · Edit；Zone · Zone Settings · Edit；Zone · Bot Management · Edit（机器人拦截）；Zone · SSL and Certificates · Read；Zone · Cache Rules · Edit。要自动添加新域名，再加 Account · Account Settings · Read 和 Zone · Zone · Edit。</li>';
		echo '<li>Zone Resources 选 All zones from an account → 选你的账户。不要用 Global API Key。</li>';
		echo '<li>创建后复制令牌，粘贴到上面，点“保存并验证”。令牌加密保存在总后台服务器上，页面不会再显示。</li></ol></details></section>';

		echo '<h2 class="pcp-section">域名进度 <span>' . count( $rows ) . '</span></h2>';
		if ( ! $rows ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有域名</strong><p>开新店填写客户域名后会自动出现在这里；已有域名也可以在下面手动加入。</p></section>';
		} else {
			echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix pcp-cdn-table"><thead><tr><th scope="col">域名</th><th scope="col">进度</th><th scope="col">说明</th><th scope="col">操作</th></tr></thead><tbody>';
			foreach ( $rows as $domain => $row ) {
				$stage = (string) ( $row['stage'] ?? 'new' );
				$cls   = 'done' === $stage ? 'is-ok' : ( 'error' === $stage ? 'is-bad' : ( in_array( $stage, array( 'ns', 'no_zone' ), true ) ? 'is-wait' : 'is-off' ) );
				$site  = ! empty( $row['site_id'] ) ? \PeptideHq\Sites::get( (int) $row['site_id'] ) : null;
				$owner = (string) ( $row['account'] ?? '' );
				$owner = '' !== $owner && isset( $accounts[ $owner ] ) ? ( '' !== $accounts[ $owner ]['label'] ? $accounts[ $owner ]['label'] : $owner ) : '';
				echo '<tr><td><strong>' . esc_html( (string) $domain ) . '</strong><small>' . esc_html( ( $site ? ( '' !== (string) $site['name'] ? $site['name'] : $site['url'] ) . ' · ' : '' ) . ( 'manual' === ( $row['source'] ?? '' ) ? '手动加入' : '自动开店' ) . ' · ' . ( $row['ip'] ?? '' ) . ( '' !== $owner ? ' · 账号：' . $owner : '' ) ) . '</small></td>';
				echo '<td><span class="pcp-state ' . esc_attr( $cls ) . '"><i></i>' . esc_html( Cdn::STAGES[ $stage ] ?? $stage ) . '</span></td>';
				echo '<td class="pcp-cdn-msg">' . esc_html( (string) ( $row['message'] ?? '' ) ) . ( ! empty( $row['updated'] ) ? '<small>' . esc_html( wp_date( 'm-d H:i', (int) $row['updated'] ) . ' 更新' ) . '</small>' : '' ) . '</td>';
				echo '<td>' . self::form( 'check', array( 'domain' => $domain ), '立即检查' ) . ' ' . ( isset( Cdn::domains()[ $domain ] ) ? self::form( 'remove', array( 'domain' => $domain ), '移除', 'pcp-link is-danger', '只从列表移除，不改 Cloudflare。确定？' ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></div></section>';
		}

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>手动加入已有域名</h2><span class="pcp-hint">已经开好的店铺换 / 加域名时用</span></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-cdn-form pcp-cdn-add">';
		wp_nonce_field( 'pchq_cdn_add' );
		echo '<input type="hidden" name="action" value="pchq_cdn_add">';
		echo '<label class="pcp-field"><span>根域名</span><input name="domain" placeholder="example.com" autocomplete="off" spellcheck="false" required></label>';
		echo '<label class="pcp-field"><span>店铺服务器</span><select name="ip" required>';
		foreach ( \PeptideHq\Servers::all() as $srv ) {
			echo '<option value="' . esc_attr( (string) $srv['ip'] ) . '">' . esc_html( $srv['name'] . ' · ' . $srv['ip'] ) . '</option>';
		}
		echo '</select></label><p><button type="submit" class="button"' . ( $s['accounts'] ? '' : ' disabled' ) . '>加入并检查</button></p></form>';
		echo '<p class="pcp-note">已经走 CDN 的域名不会被改回灰色；遇到同名的 AAAA / CNAME 记录会停下提示，不会删除你的记录。</p></section>';
		echo '</div>';
	}
}
