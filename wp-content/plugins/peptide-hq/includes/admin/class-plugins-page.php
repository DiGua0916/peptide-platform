<?php
/**
 * 总后台 → 插件管理（2026-11-08）。上传第三方插件到插件库，按店铺安装 / 卸载，勾选“新店自动安装”。
 * 真正动服务器的是 root 后台任务；这里只上传包、排队、显示结果。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Sites;
use PeptideHq\Store_Plugins;

defined( 'ABSPATH' ) || exit;

final class Plugins_Page {

	public const SLUG = 'pchq-plugins';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '插件管理', '插件管理', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			20
		);
		add_action( 'admin_post_pchq_plugin_upload', array( self::class, 'upload' ) );
		add_action( 'admin_post_pchq_plugin_action', array( self::class, 'action' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	private static function back( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_plugins_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	public static function upload(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权上传插件。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_plugin_upload' );
		if ( empty( $_FILES['plugin_zip'] ) || ! is_array( $_FILES['plugin_zip'] ) ) {
			self::flash( 'error', '没有收到文件。文件太大（超过 32 MB）时也会这样。' );
			self::back();
		}
		$row = Store_Plugins::upload( $_FILES['plugin_zip'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 在 Store_Plugins::upload/inspect 里校验。
		if ( is_wp_error( $row ) ) {
			self::flash( 'error', $row->get_error_message() );
		} else {
			self::flash( 'success', '已上传：' . $row['name'] . ( '' !== $row['version'] ? ' ' . $row['version'] : '' ) . '。现在可以在下面选择要安装的店铺。' );
		}
		self::back();
	}

	public static function action(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理插件。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_plugin_action' );
		$slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$site = absint( $_POST['site'] ?? 0 );
		$res  = true;
		if ( in_array( $do, array( 'install', 'uninstall' ), true ) ) {
			$res = Store_Plugins::request( $site, $slug, $do );
			if ( true === $res ) {
				self::flash( 'success', 'install' === $do ? '已提交安装，通常 1 分钟内完成（会先检查首页和购物车能正常打开）。' : '已提交卸载。' );
			}
		} elseif ( 'auto_on' === $do || 'auto_off' === $do ) {
			Store_Plugins::set_auto( $slug, 'auto_on' === $do );
			self::flash( 'success', 'auto_on' === $do ? '以后新开的店会自动安装这个插件。' : '已取消新店自动安装。' );
		} elseif ( 'delete' === $do ) {
			$res = Store_Plugins::delete( $slug );
			if ( true === $res ) {
				self::flash( 'success', '已从插件库删除。' );
			}
		}
		if ( is_wp_error( $res ) ) {
			self::flash( 'error', $res->get_error_message() );
		}
		self::back();
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function button( string $slug, string $do, string $label, int $site = 0, string $class = 'button', string $confirm = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>'
			. wp_nonce_field( 'pchq_plugin_action', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="pchq_plugin_action"><input type="hidden" name="slug" value="' . esc_attr( $slug ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="site" value="' . (int) $site . '">'
			. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function state_label( ?array $row ): string {
		if ( ! $row || 'removed' === $row['state'] ) {
			return '<span class="pcp-state is-off"><i></i>未安装</span>';
		}
		$map = array(
			'installing' => array( 'is-wait', '安装中…' ),
			'removing'   => array( 'is-wait', '卸载中…' ),
			'active'     => array( 'is-ok', '已启用' . ( '' !== $row['installed_version'] ? ' · ' . $row['installed_version'] : '' ) ),
			'failed'     => array( 'is-bad', '失败' ),
		);
		[ $cls, $text ] = $map[ $row['state'] ] ?? array( 'is-off', $row['state'] );
		$out = '<span class="pcp-state ' . esc_attr( $cls ) . '"><i></i>' . esc_html( $text ) . '</span>';
		if ( 'failed' === $row['state'] && '' !== $row['error'] ) {
			$out .= '<small class="pcp-error">' . esc_html( $row['error'] ) . '</small>';
		}
		return $out;
	}

	private static function ago( int $time ): string {
		$diff = max( 0, time() - $time );
		if ( $diff < 60 ) {
			return '刚刚';
		}
		if ( $diff < 3600 ) {
			return intdiv( $diff, 60 ) . ' 分钟前';
		}
		return $diff < 86400 ? intdiv( $diff, 3600 ) . ' 小时前' : intdiv( $diff, 86400 ) . ' 天前';
	}

	private static function initial( string $name ): string {
		$name = trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name ) );
		return '' === $name ? '?' : mb_strtoupper( mb_substr( $name, 0, 1 ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		Store_Plugins::sync();
		$flash = get_transient( 'pchq_plugins_flash_' . get_current_user_id() );
		delete_transient( 'pchq_plugins_flash_' . get_current_user_id() );
		$library = Store_Plugins::library();
		$assign  = Store_Plugins::assignments();
		$sites   = array_values( array_filter( Sites::all(), static fn( array $s ): bool => 'retired' !== ( $s['status'] ?? '' ) && '' !== (string) $s['uuid'] ) );
		$pending = false;
		$installs = 0;
		foreach ( $assign as $rows ) {
			foreach ( $rows as $row ) {
				$installs += 'active' === $row['state'] ? 1 : 0;
			}
		}
		$library_names = array();
		foreach ( $library as $p ) {
			$library_names[ strtolower( (string) $p['name'] ) ] = true;
		}

		echo '<div class="wrap pchq pchq-plugins pcp">';
		echo '<header class="pcp-head"><div><h1>插件管理</h1><p>上传你购买的第三方插件，再选择装到哪些店铺。安装时会同时放到店铺的前台和后台服务器，启用后自动检查首页和购物车，打不开会自动停用。</p></div>';
		echo '<dl class="pcp-stats"><div><dt>插件库</dt><dd>' . count( $library ) . '</dd></div><div><dt>已安装</dt><dd>' . (int) $installs . '</dd></div><div><dt>店铺</dt><dd>' . count( $sites ) . '</dd></div></dl></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
		if ( ! Store_Plugins::ready() ) {
			echo '<div class="pcp-flash is-wait">插件服务还没准备好（后台任务尚未创建队列目录）。请一分钟后刷新；如果一直这样，请联系技术人员。</div>';
		}

		// Upload: a real file input inside a drop zone (keyboard / screen reader friendly), checked client-side too.
		echo '<section class="pcp-card pcp-upload"><div class="pcp-card__head"><h2>上传插件</h2><span class="pcp-hint">.zip · 最大 32 MB</span></div>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-upload__form" data-max="' . (int) ( 32 * 1024 * 1024 ) . '">';
		wp_nonce_field( 'pchq_plugin_upload' );
		echo '<input type="hidden" name="action" value="pchq_plugin_upload">';
		echo '<label class="pcp-drop" for="pcp-file"><input id="pcp-file" type="file" name="plugin_zip" accept=".zip,application/zip" required>';
		echo '<span class="pcp-drop__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg></span>';
		echo '<span class="pcp-drop__text"><strong data-empty="把插件 .zip 拖到这里，或点击选择文件">把插件 .zip 拖到这里，或点击选择文件</strong><small data-empty="请上传插件作者提供的原始压缩包">请上传插件作者提供的原始压缩包</small></span></label>';
		echo '<div class="pcp-upload__bar"><p class="pcp-hint">同一个插件再次上传会保存为新版本，之后点“更新”使用最新版本。只用正版、可信来源的插件。</p><button type="submit" class="button button-primary pcp-upload__btn" disabled>上传到插件库</button></div>';
		echo '</form></section>';

		echo '<h2 class="pcp-section">第三方插件 <span>' . count( $library ) . '</span></h2>';
		if ( ! $library ) {
			echo '<section class="pcp-card pcp-empty"><strong>插件库还是空的</strong><p>上传后会在这里显示，再按店铺安装。店铺自带的插件在下面“店铺自带插件”里。</p></section>';
		}
		foreach ( $library as $p ) {
			$slug = (string) $p['slug'];
			$used = 0;
			foreach ( $assign[ $slug ] ?? array() as $row ) {
				$used += 'removed' !== $row['state'] ? 1 : 0;
			}
			$meta = array( $slug );
			if ( '' !== (string) $p['version'] ) {
				$meta[] = '版本 ' . $p['version'];
			}
			$meta[] = size_format( (int) $p['size'] );
			if ( (int) $p['versions'] > 1 ) {
				$meta[] = '共 ' . (int) $p['versions'] . ' 个版本';
			}
			echo '<section class="pcp-card pcp-plugin"><div class="pcp-plugin__head">';
			echo '<span class="pcp-avatar" aria-hidden="true">' . esc_html( self::initial( (string) $p['name'] ) ) . '</span>';
			echo '<div class="pcp-plugin__title"><h3>' . esc_html( $p['name'] ) . '</h3><p>' . esc_html( implode( ' · ', $meta ) ) . '</p></div>';
			echo '<div class="pcp-plugin__tools">';
			echo (int) $p['auto_new']
				? '<span class="pcp-tag is-on">新店自动安装</span>' . self::button( $slug, 'auto_off', '取消', 0, 'pcp-link' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				: self::button( $slug, 'auto_on', '设为新店自动安装', 0, 'pcp-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( ! $used ) {
				echo self::button( $slug, 'delete', '删除', 0, 'pcp-link is-danger', '确定从插件库删除这个插件的所有版本？' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div></div><ul class="pcp-stores">';
			foreach ( $sites as $site ) {
				$row     = $assign[ $slug ][ (int) $site['id'] ] ?? null;
				$state   = $row['state'] ?? 'removed';
				$pending = $pending || in_array( $state, array( 'installing', 'removing' ), true );
				$label   = '' !== (string) $site['name'] ? (string) $site['name'] : (string) $site['url'];
				$host    = (string) wp_parse_url( \PeptideHq\Site_Urls::public_url( $site ), PHP_URL_HOST );
				$actions = '';
				if ( ! in_array( $state, array( 'installing', 'removing' ), true ) ) {
					$installed = in_array( $state, array( 'active', 'failed' ), true );
					$newer     = $installed && 'active' === $state && (string) $row['installed_version'] !== (string) $p['version'];
					$actions  .= self::button( $slug, 'install', $installed ? ( $newer ? '更新到 ' . $p['version'] : '重新安装' ) : '安装并启用', (int) $site['id'], $installed && ! $newer ? 'button' : 'button button-primary' );
					if ( $installed ) {
						$actions .= self::button( $slug, 'uninstall', '卸载', (int) $site['id'], 'button pcp-btn-quiet', '确定在这个店铺停用并卸载这个插件？' );
					}
				}
				echo '<li><div class="pcp-store"><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $host ) . '</small></div><div class="pcp-store__state">' . self::state_label( $row ) . '</div><div class="pcp-store__actions">' . $actions . '</div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul></section>';
		}

		// Built-in: what each store reports as active (refreshed with the store summary). Read-only on purpose.
		echo '<h2 class="pcp-section">店铺自带插件</h2>';
		echo '<p class="pcp-note">这些插件是店铺系统本身的一部分（商城、支付、发信等），开新店时自带，随平台版本统一更新。为避免店铺停摆，这里只显示、不提供停用。</p>';
		foreach ( $sites as $site ) {
			$info    = is_array( $site['info'] ?? null ) ? $site['info'] : array();
			$plugins = is_array( $info['plugins'] ?? null ) ? $info['plugins'] : array();
			$label   = '' !== (string) $site['name'] ? (string) $site['name'] : (string) $site['url'];
			$when    = isset( $info['time'] ) && is_numeric( $info['time'] ) ? self::ago( (int) $info['time'] ) . '同步' : '';
			echo '<section class="pcp-card pcp-builtin"><div class="pcp-card__head"><h3>' . esc_html( $label ) . '</h3><span class="pcp-hint">' . esc_html( count( $plugins ) . ' 个已启用' . ( '' !== $when ? ' · ' . $when : '' ) ) . '</span></div>';
			if ( ! $plugins ) {
				echo '<p class="pcp-note">还没有收到这个店铺的插件信息，稍后刷新。</p></section>';
				continue;
			}
			echo '<ul class="pcp-chips">';
			foreach ( $plugins as $plugin ) {
				$name = (string) ( $plugin['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$from = isset( $library_names[ strtolower( $name ) ] );
				echo '<li><span class="pcp-avatar is-sm" aria-hidden="true">' . esc_html( self::initial( $name ) ) . '</span><span><strong>' . esc_html( $name ) . '</strong><small>' . esc_html( ( '' !== (string) ( $plugin['version'] ?? '' ) ? '版本 ' . $plugin['version'] : '' ) ) . '</small></span>' . ( $from ? '<em class="pcp-tag">插件库</em>' : '<em class="pcp-tag is-core">自带</em>' ) . '</li>';
			}
			echo '</ul></section>';
		}

		if ( $pending ) {
			// 有任务在处理时每 15 秒刷新一次页面，直到出结果。
			echo '<p class="pcp-note pcp-live">有插件正在处理，页面会自动刷新。</p><script>setTimeout(function(){location.reload();},15000);</script>';
		}
		echo '</div>';
	}
}
