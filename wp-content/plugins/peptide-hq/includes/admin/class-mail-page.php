<?php
/**
 * 总后台 → 邮件中心（2026-11-08）。发信邮箱连接状态、开关和上限、每家店的发送量、最近记录、发测试邮件。
 * 实际代发见 PeptideHq\Mail。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Mail;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Mail_Page {

	public const SLUG = 'pchq-mail';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '邮件中心', '邮件中心', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			22
		);
		foreach ( array( 'save', 'test' ) as $do ) {
			add_action( 'admin_post_pchq_mail_' . $do, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) );
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true );
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_mail_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理邮件中心。', '', array( 'response' => 403 ) );
		}
		$do = substr( (string) current_action(), strlen( 'admin_post_pchq_mail_' ) );
		check_admin_referer( 'pchq_mail_' . $do );
		if ( 'save' === $do ) {
			Mail::save_settings( array(
				'enabled'   => ! empty( $_POST['enabled'] ),
				'daily_cap' => (int) ( $_POST['daily_cap'] ?? 280 ),
				'site_hour' => (int) ( $_POST['site_hour'] ?? 120 ),
			) );
			self::flash( 'success', '已保存。' );
		} elseif ( 'test' === $do ) {
			$to = sanitize_email( (string) wp_unslash( $_POST['to'] ?? '' ) );
			$sender = Mail::sender();
			if ( ! is_email( $to ) ) {
				self::flash( 'error', '请填写正确的收件邮箱。' );
			} elseif ( ! $sender['ready'] ) {
				self::flash( 'error', '还没有连接发信邮箱，请先在 FluentSMTP 里连接。' );
			} else {
				$error = '';
				$catch = static function ( $e ) use ( &$error ) { if ( $e instanceof \WP_Error ) { $error = $e->get_error_message(); } };
				add_action( 'wp_mail_failed', $catch );
				$ok = wp_mail( $to, '邮件中心测试 · VialCove', "这是一封来自总后台邮件中心的测试邮件。\n\n收到说明发信邮箱已连接成功，所有店铺的邮件都会通过它发出。", array( 'From: "VialCove" <' . $sender['email'] . '>' ) );
				remove_action( 'wp_mail_failed', $catch );
				Mail::log( 0, array( $to ), '邮件中心测试', $ok ? 'sent' : 'failed', $ok ? '' : $error );
				self::flash( $ok ? 'success' : 'error', $ok ? '测试邮件已发出，请到收件箱查看（也看一下垃圾箱）。' : '发送失败：' . ( '' !== $error ? $error : '请检查 FluentSMTP 连接。' ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$s      = Mail::settings();
		$sender = Mail::sender();
		$flash  = get_transient( 'pchq_mail_flash_' . get_current_user_id() );
		delete_transient( 'pchq_mail_flash_' . get_current_user_id() );
		$today  = Mail::count_since( time() - DAY_IN_SECONDS );
		$smtp   = admin_url( 'options-general.php?page=fluent-mail#/connections' );
		$labels = array( 'outlook' => '微软邮箱（Outlook）', 'gmail' => 'Gmail', 'smtp' => 'SMTP', 'sendinblue' => 'Brevo', 'ses' => 'Amazon SES' );

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>邮件中心</h1><p>所有店铺的邮件（订单通知、弃单提醒等）统一由总后台的发信邮箱发出。新开的店铺不用任何设置；换发信邮箱只改这里一处。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>发信邮箱</h2>' . ( $sender['ready'] ? '<span class="pcp-state is-ok"><i></i>已连接</span>' : '<span class="pcp-state is-bad"><i></i>未连接</span>' ) . '</div>';
		if ( $sender['ready'] ) {
			echo '<p>' . esc_html( ( $labels[ $sender['provider'] ] ?? $sender['provider'] ) . ' · ' . $sender['email'] ) . '</p>';
		} else {
			echo '<p>还没有连接发信邮箱。在 FluentSMTP 里添加连接（微软邮箱选 Outlook），连接后回到这里发一封测试邮件。</p>';
		}
		echo '<p><a class="button" href="' . esc_url( $smtp ) . '">打开 FluentSMTP 设置</a></p></section>';

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>代发设置</h2><span class="pcp-hint">最近 24 小时已发 ' . (int) $today . ' / ' . (int) $s['daily_cap'] . ' 封</span></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-cdn-form">';
		wp_nonce_field( 'pchq_mail_save' );
		echo '<input type="hidden" name="action" value="pchq_mail_save">';
		echo '<label class="pcp-check"><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 由总后台代发所有店铺的邮件</label>';
		echo '<label class="pcp-field"><span>每天最多发送（所有店铺合计）</span><input type="number" name="daily_cap" min="1" max="100000" value="' . (int) $s['daily_cap'] . '"></label>';
		echo '<label class="pcp-field"><span>每家店每小时最多</span><input type="number" name="site_hour" min="1" max="10000" value="' . (int) $s['site_hour'] . '"></label>';
		echo '<p class="pcp-note">个人微软邮箱每天约 300 封，超了会被微软临时限制发信，所以默认每天 280 封。达到上限后，当天剩余的邮件不再发送，第二天自动恢复。换成 Brevo 等发信服务后可以调高。</p>';
		echo '<p><button type="submit" class="button button-primary">保存</button></p></form></section>';

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>发测试邮件</h2></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-cdn-form pcp-cdn-add">';
		wp_nonce_field( 'pchq_mail_test' );
		echo '<input type="hidden" name="action" value="pchq_mail_test">';
		echo '<label class="pcp-field"><span>收件邮箱</span><input type="email" name="to" required autocomplete="email" spellcheck="false" placeholder="you@example.com"></label>';
		echo '<p><button type="submit" class="button"' . ( $sender['ready'] ? '' : ' disabled' ) . '>发送</button></p></form></section>';

		$sites = Sites::all();
		$week  = Mail::per_site( 7 );
		$day   = Mail::per_site( 1 );
		echo '<h2 class="pcp-section">各店铺发送量 <span>' . count( $sites ) . '</span></h2>';
		echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">店铺</th><th scope="col">24 小时已发</th><th scope="col">7 天已发</th><th scope="col">7 天失败</th><th scope="col">7 天被拒（超上限 / 未连接）</th></tr></thead><tbody>';
		foreach ( $sites as $id => $site ) {
			$w = $week[ $id ] ?? array();
			echo '<tr><td><strong>' . esc_html( '' !== (string) $site['name'] ? $site['name'] : $site['url'] ) . '</strong></td><td>' . (int) ( $day[ $id ]['sent'] ?? 0 ) . '</td><td>' . (int) ( $w['sent'] ?? 0 ) . '</td><td>' . (int) ( $w['failed'] ?? 0 ) . '</td><td>' . (int) ( $w['refused'] ?? 0 ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';

		$recent = Mail::recent( 50 );
		echo '<h2 class="pcp-section">最近记录 <span>' . count( $recent ) . '</span></h2>';
		if ( ! $recent ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有邮件</strong><p>店铺发出的邮件会记录在这里（只记主题和结果，不保存正文）。</p></section>';
		} else {
			$st = array( 'sent' => array( '已发送', 'is-ok' ), 'failed' => array( '发送失败', 'is-bad' ), 'refused' => array( '未发送', 'is-wait' ) );
			echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">时间</th><th scope="col">店铺</th><th scope="col">收件人</th><th scope="col">主题</th><th scope="col">结果</th></tr></thead><tbody>';
			foreach ( $recent as $r ) {
				$site = (int) $r['site_id'] ? ( $sites[ (int) $r['site_id'] ] ?? null ) : null;
				$name = (int) $r['site_id'] ? ( $site ? ( '' !== (string) $site['name'] ? $site['name'] : $site['url'] ) : '店铺 #' . (int) $r['site_id'] ) : '总后台';
				$s2   = $st[ $r['status'] ] ?? array( $r['status'], 'is-off' );
				echo '<tr><td>' . esc_html( wp_date( 'm-d H:i', (int) $r['created_at'] ) ) . '</td><td>' . esc_html( $name ) . '</td><td>' . esc_html( $r['first_to'] . ( (int) $r['recipients'] > 1 ? ' 等 ' . (int) $r['recipients'] . ' 人' : '' ) ) . '</td><td>' . esc_html( $r['subject'] ) . '</td><td><span class="pcp-state ' . esc_attr( $s2[1] ) . '"><i></i>' . esc_html( $s2[0] ) . '</span>' . ( '' !== (string) $r['error'] ? '<small>' . esc_html( $r['error'] ) . '</small>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table></div></section>';
		}
		echo '</div>';
	}
}
