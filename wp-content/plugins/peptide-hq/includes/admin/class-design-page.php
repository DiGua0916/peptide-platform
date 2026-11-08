<?php
/**
 * 总后台 → 模板库（2026-11-08 第二版）。
 *
 * 在这里新建、改名、装修模板（装修编辑器直接嵌在总后台里），勾选“下发给店铺”的模板就是系统模板，
 * 会自动下发到所有店铺（店铺里只读）；店铺自己的修订版和店铺选用哪个模板不受影响。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Design_Push;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Design_Page {

	public const SLUG        = 'pchq-design';
	public const EDITOR_SLUG = 'pchq-design-editor';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '模板库', '模板库', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
				add_submenu_page( 'pchq', '装修模板', '装修模板', Menu::CAP, self::EDITOR_SLUG, array( self::class, 'render_editor' ) );
			},
			19
		);
		add_action(
			'admin_head',
			static function (): void {
				echo '<style>#adminmenu a[href$="page=' . esc_attr( self::EDITOR_SLUG ) . '"]{display:none}</style>';
			}
		);
		foreach ( array( 'settings', 'push', 'push_one', 'auto', 'create', 'update', 'trash' ) as $do ) {
			add_action( 'admin_post_pchq_design_' . $do, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		$page = (string) ( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && self::EDITOR_SLUG !== $page ) {
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
			wp_die( '无权管理模板。', '', array( 'response' => 403 ) );
		}
		$do = substr( (string) current_action(), strlen( 'admin_post_pchq_design_' ) );
		check_admin_referer( 'pchq_design_' . $do );
		$s  = Design_Push::settings();
		$id = absint( $_POST['template'] ?? 0 );
		switch ( $do ) {
			case 'settings':
				Design_Push::save_settings( absint( $_POST['master'] ?? 0 ) ?: $s['master'] );
				self::flash( 'success', '模板工作室已保存。' );
				break;
			case 'auto':
				$auto = '1' === ( $_POST['auto'] ?? '' );
				Design_Push::save_settings( $s['master'], $auto );
				self::flash( 'success', $auto ? '已开启自动下发：系统模板改完发布后，5 分钟内所有店铺自动更新。' : '已关闭自动下发：改完需要手动点“立即下发”。' );
				break;
			case 'push':
			case 'push_one':
				$res = Design_Push::start( 'push_one' === $do ? array( absint( $_POST['site'] ?? 0 ) ) : array() );
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : '正在把系统模板下发给 ' . count( $res ) . ' 家店铺（后台逐个处理，页面会自动刷新）。' );
				break;
			case 'create':
				$res = Design_Push::studio( array( 'op' => 'create', 'name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 'note' => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ), 'from' => absint( $_POST['from'] ?? 0 ) ) );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
					break;
				}
				$new = (int) ( $res['result']['id'] ?? 0 );
				self::flash( 'success', '模板已新建。装修好、发布后，打开“下发给店铺”就会发到所有店铺。' );
				if ( $new ) {
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::EDITOR_SLUG . '&template=' . $new ) );
					exit;
				}
				break;
			case 'update':
				$payload = array( 'op' => 'update', 'id' => $id );
				if ( isset( $_POST['name'] ) ) {
					$payload['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ) );
					$payload['note'] = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
				}
				if ( isset( $_POST['system'] ) ) {
					$payload['system'] = '1' === $_POST['system'];
				}
				$res = Design_Push::studio( $payload );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
				} elseif ( isset( $payload['system'] ) ) {
					self::flash( 'success', $payload['system'] ? '已设为系统模板，5 分钟内下发到所有店铺（店铺里只读）。' : '已停止下发这个模板。店铺里已有的那份保留，以后不再更新。' );
				} else {
					self::flash( 'success', '已保存。' );
				}
				break;
			case 'trash':
				$res = Design_Push::studio( array( 'op' => 'trash', 'id' => $id ) );
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : '模板已删除。' );
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

	private static function short_time( string $mysql ): string {
		$t = '' !== $mysql ? strtotime( $mysql ) : false;
		return $t ? gmdate( 'Y-m-d H:i', $t ) : '—';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$flash = get_transient( 'pchq_design_flash_' . get_current_user_id() );
		delete_transient( 'pchq_design_flash_' . get_current_user_id() );
		$settings  = Design_Push::settings();
		$sites     = Design_Push::sites();
		$master    = Design_Push::master_id();
		$templates = Design_Push::templates();
		$latest    = Design_Push::latest();
		$results   = Design_Push::results();
		$job       = Design_Push::job();
		$others    = array_values( array_filter( $sites, static fn( array $s ): bool => (int) $s['id'] !== $master ) );
		$system    = is_array( $templates ) ? array_values( array_filter( $templates, static fn( array $t ): bool => ! empty( $t['system'] ) ) ) : array();

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>模板库</h1><p>在这里新建和装修模板。打开“下发给店铺”的就是<strong>系统模板</strong>：自动下发到所有店铺，店铺里只读；每家店另有一份可以随意修改的<strong>修订版</strong>，用哪一个由店铺自己选，下发不会动它们的修订版和选择。</p></div>';
		echo '<dl class="pcp-stats"><div><dt>模板</dt><dd>' . ( is_array( $templates ) ? count( $templates ) : 0 ) . '</dd></div><div><dt>系统模板</dt><dd>' . count( $system ) . '</dd></div><div><dt>店铺</dt><dd>' . count( $others ) . '</dd></div></dl></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
		if ( $job ) {
			echo '<div class="pcp-flash is-wait">正在下发系统模板：还剩 ' . count( (array) $job['targets'] ) . ' 家店铺，页面每 15 秒自动刷新。</div><script>setTimeout(function(){location.reload();},15000);</script>';
		}

		// 模板列表
		echo '<section class="pcp-card pcp-tpl"><div class="pcp-card__head"><h2>模板</h2>';
		echo '<details class="pcp-new"><summary class="button button-primary">新建模板</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-new__form">';
		wp_nonce_field( 'pchq_design_create' );
		echo '<input type="hidden" name="action" value="pchq_design_create"><label class="pcp-field"><span>模板名称</span><input name="name" maxlength="80" required placeholder="例如：2026 黑五主题"></label><label class="pcp-field"><span>备注（可选）</span><input name="note" maxlength="200"></label><label class="pcp-field"><span>起点</span><select name="from"><option value="0">空白模板</option>';
		foreach ( is_array( $templates ) ? $templates : array() as $t ) {
			echo '<option value="' . (int) $t['id'] . '">复制「' . esc_html( (string) $t['name'] ) . '」</option>';
		}
		echo '</select></label><button type="submit" class="button button-primary">新建并去装修</button></form></details></div>';
		if ( is_wp_error( $templates ) ) {
			echo '<div class="pcp-flash is-bad">' . esc_html( $templates->get_error_message() ) . '</div></section>';
		} elseif ( ! $templates ) {
			echo '<p class="pcp-note">还没有模板，点右上角“新建模板”。</p></section>';
		} else {
			echo '<div class="pcp-tpl__list">';
			echo '<div class="pcp-tpl__row pcp-tpl__row--head"><span>模板</span><span>首页</span><span>修改时间</span><span>下发给店铺</span><span class="pcp-tpl__right">操作</span></div>';
			foreach ( $templates as $t ) {
				$tid = (int) $t['id'];
				if ( empty( $t['published'] ) ) {
					$chip = array( 'is-off', '首页未发布' );
				} elseif ( ! empty( $t['changed'] ) ) {
					$chip = array( 'is-wait', '有未发布的修改' );
				} else {
					$chip = array( 'is-ok', '已发布 · ' . self::short_time( (string) $t['at'] ) );
				}
				echo '<div class="pcp-tpl__row"><div class="pcp-tpl__name"><div><strong>' . esc_html( (string) $t['name'] ) . '</strong>' . ( ! empty( $t['system'] ) ? ' <span class="pcp-tag is-on">系统模板</span>' : '' ) . ( ! empty( $t['default'] ) ? ' <span class="pcp-tag">工作室在用</span>' : '' ) . '</div>' . ( '' !== (string) $t['note'] ? '<small>' . esc_html( (string) $t['note'] ) . '</small>' : '' ) . '</div>';
				echo '<div><span class="pcp-state ' . esc_attr( $chip[0] ) . '"><i></i>' . esc_html( $chip[1] ) . '</span></div>';
				echo '<div class="pcp-tpl__time">' . esc_html( self::short_time( (string) $t['modified'] ) ) . '</div>';
				$on = ! empty( $t['system'] );
				echo '<div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline pcp-switch-form"' . ( $on ? ' data-confirm="' . esc_attr( '停止下发「' . $t['name'] . '」？店铺里已有的那份会保留，以后不再更新。' ) . '"' : ' data-confirm="' . esc_attr( '把「' . $t['name'] . '」设为系统模板？会下发到所有店铺（店铺里只读）。请先确认已发布。' ) . '"' ) . '>';
				wp_nonce_field( 'pchq_design_update' );
				echo '<input type="hidden" name="action" value="pchq_design_update"><input type="hidden" name="template" value="' . $tid . '"><input type="hidden" name="system" value="' . ( $on ? '0' : '1' ) . '"><button type="submit" class="pcp-switch" role="switch" aria-checked="' . ( $on ? 'true' : 'false' ) . '" aria-label="' . esc_attr( '下发「' . $t['name'] . '」给店铺' ) . '"><span></span></button></form></div>';
				echo '<div class="pcp-tpl__actions"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::EDITOR_SLUG . '&template=' . $tid ) ) . '">装修</a>';
				echo '<details class="pcp-more"><summary class="button" aria-label="更多操作">⋯</summary><div class="pcp-more__menu"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'pchq_design_update' );
				echo '<input type="hidden" name="action" value="pchq_design_update"><input type="hidden" name="template" value="' . $tid . '"><label class="pcp-field"><span>名称</span><input name="name" maxlength="80" required value="' . esc_attr( (string) $t['name'] ) . '"></label><label class="pcp-field"><span>备注</span><input name="note" maxlength="200" value="' . esc_attr( (string) $t['note'] ) . '"></label><button type="submit" class="button">保存</button></form>';
				if ( empty( $t['system'] ) && empty( $t['default'] ) ) {
					echo self::form( 'trash', array( 'template' => $tid ), '删除模板', 'pcp-link is-danger', '确定删除「' . $t['name'] . '」？' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div></details></div></div>';
			}
			echo '</div></section>';
		}

		// 下发
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>下发</h2>' . ( ! empty( $latest['at'] ) ? '<span class="pcp-hint">上次下发读取：' . esc_html( wp_date( 'Y-m-d H:i', (int) $latest['at'] ) ) . '</span>' : '' ) . '</div>';
		echo '<p class="pcp-note">自动下发：<strong>' . ( $settings['auto'] ? '已开启' : '已关闭' ) . '</strong>' . ( $settings['auto'] ? '（每 5 分钟检查一次，系统模板改完发布后自动更新所有店铺，不需要通知店铺）' : '' ) . ' ' . self::form( 'auto', array( 'auto' => $settings['auto'] ? '0' : '1' ), $settings['auto'] ? '关闭自动下发' : '开启自动下发', 'pcp-link' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="pcp-actions">' . self::form( 'push', array(), '立即下发到所有店铺', 'button button-primary', '确定现在把所有系统模板下发给所有店铺？只会更新店铺里的系统模板，不动店铺的修订版和选择。' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $others ) {
			echo '<div class="pcp-scroll"><table class="pcp-matrix pcp-cdn-table"><thead><tr><th scope="col">店铺</th><th scope="col">系统模板</th><th scope="col">店铺在用</th><th scope="col">说明</th><th scope="col">操作</th></tr></thead><tbody>';
			$want = (array) ( $latest['templates'] ?? array() );
			foreach ( $others as $st ) {
				$sid    = (int) $st['id'];
				$design = (array) ( $st['info']['design'] ?? array() );
				$have   = (array) ( $design['templates'] ?? array() );
				$fresh  = 0;
				foreach ( $want as $key => $w ) {
					$fresh += ( $have[ $key ]['version'] ?? '' ) === ( $w['digest'] ?? '-' ) ? 1 : 0;
				}
				$res   = $results[ $sid ] ?? array();
				$state = (string) ( $res['state'] ?? '' );
				$badge = in_array( $state, array( 'queued', 'running' ), true ) ? array( 'is-wait', 'queued' === $state ? '排队中' : '下发中' ) : ( 'failed' === $state ? array( 'is-bad', '下发失败' ) : ( $want && $fresh === count( $want ) ? array( 'is-ok', '已是最新' ) : array( 'is-off', $want ? $fresh . ' / ' . count( $want ) . ' 最新' : '还没下发过' ) ) );
				$using = array( 'system' => '系统模板', 'revision' => '修订版', 'own' => '自建模板' )[ (string) ( $design['using'] ?? '' ) ] ?? '—';
				echo '<tr><td><strong>' . esc_html( self::label( $st ) ) . '</strong><small>' . esc_html( (string) wp_parse_url( \PeptideHq\Site_Urls::public_url( $st ), PHP_URL_HOST ) ) . '</small></td><td><span class="pcp-state ' . esc_attr( $badge[0] ) . '"><i></i>' . esc_html( $badge[1] ) . '</span></td><td>' . esc_html( $using ) . '</td><td class="pcp-cdn-msg">' . esc_html( (string) ( $res['message'] ?? '' ) ) . '</td><td>' . self::form( 'push_one', array( 'site' => $sid ), '单独下发', 'pcp-link' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p class="pcp-note">除模板工作室外还没有其他店铺；新开的店铺开好后会自动收到系统模板和一份修订版。</p>';
		}
		echo '</section>';

		// 模板工作室（高级）
		echo '<details class="pcp-details"><summary>模板工作室<span>高级</span></summary><p class="pcp-note">模板实际保存在这个店铺里（装修编辑器借用它的“店铺装修”）。一般不用改。</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-master-form">';
		wp_nonce_field( 'pchq_design_settings' );
		echo '<input type="hidden" name="action" value="pchq_design_settings"><label class="pcp-field"><span>模板工作室店铺</span><select name="master">';
		foreach ( $sites as $st ) {
			echo '<option value="' . (int) $st['id'] . '"' . selected( (int) $st['id'], $master, false ) . '>' . esc_html( self::label( $st ) ) . '</option>';
		}
		echo '</select></label><button type="submit" class="button">保存</button></form></details>';
		echo '</div>';
	}

	/** 在总后台里装修：整页嵌入模板工作室的装修编辑器。 */
	public static function render_editor(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$tid    = absint( $_GET['template'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$master = Design_Push::master_id();
		$name   = '';
		$list   = Design_Push::templates();
		foreach ( is_array( $list ) ? $list : array() as $t ) {
			if ( (int) $t['id'] === $tid ) {
				$name = (string) $t['name'];
			}
		}
		echo '<div class="wrap pchq pcp pcp-studio">';
		echo '<div class="pcp-studio__bar"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">← 返回模板库</a><strong>' . esc_html( '' !== $name ? '装修：' . $name : '装修模板' ) . '</strong><span class="pcp-hint">编辑完点编辑器里的“发布”。系统模板发布后 5 分钟内自动下发到所有店铺。</span></div>';
		if ( ! $master || '' === $name ) {
			echo '<div class="pcp-flash is-bad">找不到这个模板，请回到模板库重新打开。</div></div>';
			return;
		}
		$src = Menu::action_url( 'pchq_login', array( 'site' => $master, 'to' => 'design_tpl_' . $tid ) );
		echo '<iframe class="pcp-studio__frame" src="' . esc_url( $src ) . '" title="装修编辑器" allow="clipboard-write; fullscreen"></iframe>';
		echo '</div>';
	}
}
