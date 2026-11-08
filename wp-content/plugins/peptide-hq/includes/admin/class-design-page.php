<?php
/**
 * 总后台 → 统一模板（2026-11-08）。在母版店编辑装修，在这里一键下发给所有店铺并锁定店铺装修。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Design_Push;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Design_Page {

	public const SLUG = 'pchq-design';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '统一模板', '统一模板', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			19
		);
		foreach ( array( 'settings', 'push', 'push_one', 'lock', 'auto' ) as $do ) {
			add_action( 'admin_post_pchq_design_' . $do, array( self::class, 'handle' ) );
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
		set_transient( 'pchq_design_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理统一模板。', '', array( 'response' => 403 ) );
		}
		$do = substr( (string) current_action(), strlen( 'admin_post_pchq_design_' ) );
		check_admin_referer( 'pchq_design_' . $do );
		$s = Design_Push::settings();
		switch ( $do ) {
			case 'settings':
				$master = absint( $_POST['master'] ?? 0 );
				Design_Push::save_settings( $master ?: $s['master'], $s['lock'], $s['auto'] );
				self::flash( 'success', '母版店已保存。' );
				break;
			case 'push':
			case 'push_one':
				$targets = 'push_one' === $do ? array( absint( $_POST['site'] ?? 0 ) ) : array();
				$res     = Design_Push::start( $targets );
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : '已从母版店读取最新装修，正在下发给 ' . count( $res ) . ' 家店铺（后台逐个处理，页面会自动刷新）。' );
				break;
			case 'auto':
				$auto = '1' === ( $_POST['auto'] ?? '' );
				Design_Push::save_settings( $s['master'], $s['lock'], $auto );
				self::flash( 'success', $auto ? '已开启自动下发：母版店发布装修后，5 分钟内所有店铺自动更新。' : '已关闭自动下发：改完需要手动点“立即下发”。' );
				break;
			case 'lock':
				$lock = '1' === ( $_POST['lock'] ?? '' );
				Design_Push::save_settings( $s['master'], $lock, $s['auto'] );
				$r = Design_Push::lock_all( $lock );
				$what = $lock ? '已改为锁定：店铺不能自己改装修。' : '已改为不锁定：店铺可以自己改装修，下次下发会覆盖。';
				$done = 0 === $r['ok'] && ! $r['failed'] ? '目前除母版店外没有其他店铺，以后新开的店会按这个设置。' : '已通知 ' . $r['ok'] . ' 家店铺' . ( $r['failed'] ? '；没通知到：' . implode( '；', array_slice( $r['failed'], 0, 5 ) ) : '' ) . '。';
				self::flash( $r['failed'] ? 'error' : 'success', $what . $done );
				break;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function form( string $do, array $fields, string $label, string $class = 'button', string $confirm = '' ): string {
		$out = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . wp_nonce_field( 'pchq_design_' . $do, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="pchq_design_' . esc_attr( $do ) . '">';
		foreach ( $fields as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $out . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function label( array $site ): string {
		return '' !== (string) $site['name'] ? (string) $site['name'] : (string) $site['url'];
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$flash = get_transient( 'pchq_design_flash_' . get_current_user_id() );
		delete_transient( 'pchq_design_flash_' . get_current_user_id() );
		$settings = Design_Push::settings();
		$sites    = Design_Push::sites();
		$master   = Design_Push::master_id();
		$msite    = $master ? Sites::get( $master ) : null;
		$latest   = Design_Push::latest();
		$results  = Design_Push::results();
		$job      = Design_Push::job();
		$others   = array_values( array_filter( $sites, static fn( array $s ): bool => (int) $s['id'] !== $master ) );

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>统一模板</h1><p>所有店铺使用同一套装修（首页、页眉、页脚、全局样式）。点“编辑统一模板”修改并发布后，系统自动下发给所有店铺，店铺直接用最新的，不需要通知、也不能自己改。新开的店铺开好后也会自动收到。</p></div>';
		echo '<dl class="pcp-stats"><div><dt>店铺</dt><dd>' . count( $sites ) . '</dd></div><div><dt>已是最新</dt><dd>' . count( array_filter( $others, static fn( array $s ): bool => '' !== (string) ( $latest['digest'] ?? '' ) && ( $s['info']['design']['version'] ?? '' ) === $latest['digest'] ) ) . '<small> / ' . count( $others ) . '</small></dd></div></dl></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}

		// 母版店
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>母版店</h2>' . ( $msite ? '<span class="pcp-hint">装修最近修改：' . esc_html( (string) ( $msite['info']['design']['modified'] ?? '—' ) ) . '</span>' : '' ) . '</div>';
		echo '<div class="pcp-master-row"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-master-form">';
		wp_nonce_field( 'pchq_design_settings' );
		echo '<input type="hidden" name="action" value="pchq_design_settings"><label class="pcp-field"><span>用哪个店铺的装修作为统一模板</span><select name="master">';
		foreach ( $sites as $s ) {
			echo '<option value="' . (int) $s['id'] . '"' . selected( (int) $s['id'], $master, false ) . '>' . esc_html( self::label( $s ) ) . '</option>';
		}
		echo '</select></label><button type="submit" class="button">保存</button></form>';
		if ( $msite ) {
			echo '<a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( Menu::action_url( 'pchq_login', array( 'site' => $master, 'to' => 'design' ) ) ) . '">编辑统一模板</a>';
		}
		echo '</div><p class="pcp-note">在打开的“主题装修”里编辑并发布即可' . ( $settings['auto'] ? '，5 分钟内所有店铺自动更新，不需要通知店铺' : '，然后回来点“立即下发”' ) . '。母版店本身不会被锁定。</p>';
		echo '</section>';

		// 下发
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>下发</h2>' . ( ! empty( $latest['at'] ) ? '<span class="pcp-hint">上次读取母版：' . esc_html( wp_date( 'Y-m-d H:i', (int) $latest['at'] ) ) . ' · 版本 ' . esc_html( substr( (string) $latest['digest'], 0, 8 ) ) . ' · ' . (int) ( $latest['media'] ?? 0 ) . ' 个图片 / 视频</span>' : '' ) . '</div>';
		echo '<p class="pcp-note">自动下发：<strong>' . ( $settings['auto'] ? '已开启' : '已关闭' ) . '</strong>' . ( $settings['auto'] ? '（每 5 分钟检查母版店，发布后自动更新所有店铺）' : '' ) . ' ' . self::form( 'auto', array( 'auto' => $settings['auto'] ? '0' : '1' ), $settings['auto'] ? '关闭自动下发' : '开启自动下发', 'pcp-link' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="pcp-actions">' . self::form( 'push', array(), '立即下发到所有店铺', 'button button-primary', '确定把母版店现在已发布的装修下发给所有店铺？各店铺的首页、页眉、页脚和全局样式会被替换。' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::form( 'lock', array( 'lock' => $settings['lock'] ? '0' : '1' ), $settings['lock'] ? '解锁店铺装修' : '锁定店铺装修', 'button', $settings['lock'] ? '解锁后店铺可以自己改装修（下次下发会覆盖）。确定？' : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="pcp-note">' . ( $settings['lock'] ? '现在是<strong>锁定</strong>：店铺后台看不到编辑入口，也不能发布装修；首页 SEO、域名、菜单、自定义页面不受影响。' : '现在是<strong>未锁定</strong>：店铺可以自己改装修，下次下发会覆盖。' ) . '</p>';
		if ( $job ) {
			echo '<div class="pcp-flash is-wait">正在下发：还剩 ' . count( (array) $job['targets'] ) . ' 家店铺，页面每 15 秒自动刷新。</div><script>setTimeout(function(){location.reload();},15000);</script>';
		}
		echo '</section>';

		// 店铺
		echo '<h2 class="pcp-section">店铺 <span>' . count( $others ) . '</span></h2>';
		if ( ! $others ) {
			echo '<section class="pcp-card pcp-empty"><strong>除母版店外还没有其他店铺</strong><p>新开的店铺开好后会自动收到统一装修。</p></section></div>';
			return;
		}
		echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix pcp-cdn-table"><thead><tr><th scope="col">店铺</th><th scope="col">装修</th><th scope="col">说明</th><th scope="col">操作</th></tr></thead><tbody>';
		foreach ( $others as $s ) {
			$id      = (int) $s['id'];
			$design  = (array) ( $s['info']['design'] ?? array() );
			$res     = $results[ $id ] ?? array();
			$state   = (string) ( $res['state'] ?? '' );
			$current = '' !== (string) ( $latest['digest'] ?? '' ) && ( $design['version'] ?? '' ) === $latest['digest'];
			if ( in_array( $state, array( 'queued', 'running' ), true ) ) {
				$badge = array( 'is-wait', 'queued' === $state ? '排队中' : '下发中' );
			} elseif ( 'failed' === $state ) {
				$badge = array( 'is-bad', '下发失败' );
			} elseif ( $current ) {
				$badge = array( 'is-ok', '已是最新' . ( ! empty( $design['locked'] ) ? ' · 已锁定' : '' ) );
			} elseif ( ! empty( $design['version'] ) ) {
				$badge = array( 'is-off', '不是最新' );
			} else {
				$badge = array( 'is-off', '还没下发过' );
			}
			echo '<tr><td><strong>' . esc_html( self::label( $s ) ) . '</strong><small>' . esc_html( (string) wp_parse_url( \PeptideHq\Site_Urls::public_url( $s ), PHP_URL_HOST ) ) . '</small></td>';
			echo '<td><span class="pcp-state ' . esc_attr( $badge[0] ) . '"><i></i>' . esc_html( $badge[1] ) . '</span></td>';
			echo '<td class="pcp-cdn-msg">' . esc_html( (string) ( $res['message'] ?? '' ) ) . ( ! empty( $design['applied_at'] ) ? '<small>' . esc_html( '上次下发 ' . wp_date( 'm-d H:i', (int) $design['applied_at'] ) ) . '</small>' : '' ) . '</td>';
			echo '<td>' . self::form( 'push_one', array( 'site' => $id ), '单独下发', 'pcp-link' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div></section></div>';
	}
}
