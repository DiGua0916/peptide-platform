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

	private static function button( string $slug, string $do, string $label, int $site = 0, string $class = 'button', string $confirm = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pchq-inline-form" style="display:inline"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>'
			. wp_nonce_field( 'pchq_plugin_action', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="pchq_plugin_action"><input type="hidden" name="slug" value="' . esc_attr( $slug ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="site" value="' . (int) $site . '">'
			. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function state_label( ?array $row ): string {
		if ( ! $row || 'removed' === $row['state'] ) {
			return '<span class="pchq-muted">未安装</span>';
		}
		$map = array(
			'installing' => array( 'pchq-badge is-wait', '安装中…' ),
			'removing'   => array( 'pchq-badge is-wait', '卸载中…' ),
			'active'     => array( 'pchq-badge is-ok', '已启用' . ( '' !== $row['installed_version'] ? ' ' . $row['installed_version'] : '' ) ),
			'failed'     => array( 'pchq-badge is-bad', '失败' ),
		);
		[ $cls, $text ] = $map[ $row['state'] ] ?? array( 'pchq-badge', $row['state'] );
		$out = '<span class="' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</span>';
		if ( 'failed' === $row['state'] && '' !== $row['error'] ) {
			$out .= '<br><small class="pchq-muted">' . esc_html( $row['error'] ) . '</small>';
		}
		return $out;
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

		echo '<div class="wrap pchq pchq-plugins">';
		echo '<h1>插件管理</h1>';
		echo '<p class="description">在这里上传你购买的第三方 WordPress 插件，再选择装到哪些店铺。安装时系统会同时放到店铺的前台和后台服务器，启用后自动检查首页和购物车能否正常打开，打不开会自动停用。店铺后台不能自己装插件。</p>';
		if ( is_array( $flash ) ) {
			echo '<div class="notice notice-' . esc_attr( 'error' === $flash[0] ? 'error' : 'success' ) . ' inline"><p>' . esc_html( (string) $flash[1] ) . '</p></div>';
		}
		if ( ! Store_Plugins::ready() ) {
			echo '<div class="notice notice-warning inline"><p>插件服务还没准备好（后台任务尚未创建队列目录）。请一分钟后刷新；如果一直这样，请联系技术人员。</p></div>';
		}

		echo '<section class="pchq-card"><h2>上传插件</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pchq_plugin_upload' );
		echo '<input type="hidden" name="action" value="pchq_plugin_upload">';
		echo '<p><input type="file" name="plugin_zip" accept=".zip,application/zip" required> <button type="submit" class="button button-primary">上传到插件库</button></p>';
		echo '<p class="description">请上传插件作者提供的原始 .zip（最大 32 MB）。同一个插件再次上传新版本，会作为新版本保存；之后点“安装 / 更新”会用最新版本。只用正版、可信来源的插件。</p>';
		echo '</form></section>';

		if ( ! $library ) {
			echo '<section class="pchq-card"><p class="pchq-muted">插件库还是空的。</p></section></div>';
			return;
		}
		foreach ( $library as $p ) {
			$slug = (string) $p['slug'];
			$used = 0;
			foreach ( $assign[ $slug ] ?? array() as $row ) {
				$used += 'removed' !== $row['state'] ? 1 : 0;
			}
			echo '<section class="pchq-card pchq-plugin">';
			echo '<h2>' . esc_html( $p['name'] ) . ' <small class="pchq-muted">' . esc_html( $slug . ( '' !== $p['version'] ? ' · 版本 ' . $p['version'] : '' ) . ' · ' . size_format( (int) $p['size'] ) . ( (int) $p['versions'] > 1 ? ' · 共 ' . (int) $p['versions'] . ' 个版本' : '' ) ) . '</small></h2>';
			echo '<p>' . ( (int) $p['auto_new']
				? '<span class="pchq-badge is-ok">新店自动安装</span> ' . self::button( $slug, 'auto_off', '取消新店自动安装', 0, 'button-link' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				: self::button( $slug, 'auto_on', '设为新店自动安装', 0, 'button-link' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. ( $used ? '' : ' · ' . self::button( $slug, 'delete', '从插件库删除', 0, 'button-link delete', '确定从插件库删除这个插件的所有版本？' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="pchq-scroll"><table class="widefat striped pchq-table"><thead><tr><th>店铺</th><th>状态</th><th>操作</th></tr></thead><tbody>';
			foreach ( $sites as $site ) {
				$row      = $assign[ $slug ][ (int) $site['id'] ] ?? null;
				$state    = $row['state'] ?? 'removed';
				$pending  = $pending || in_array( $state, array( 'installing', 'removing' ), true );
				$label    = '' !== (string) $site['name'] ? (string) $site['name'] : (string) $site['url'];
				$host     = (string) wp_parse_url( \PeptideHq\Site_Urls::public_url( $site ), PHP_URL_HOST );
				$actions  = '';
				if ( ! in_array( $state, array( 'installing', 'removing' ), true ) ) {
					$installed = in_array( $state, array( 'active', 'failed' ), true ) && $row && 'removed' !== $row['state'];
					$newer     = $installed && 'active' === $state && (string) $row['installed_version'] !== (string) $p['version'];
					$actions  .= self::button( $slug, 'install', $installed ? ( $newer ? '更新到 ' . $p['version'] : '重新安装' ) : '安装并启用', (int) $site['id'], $installed ? 'button' : 'button button-primary' );
					if ( $installed ) {
						$actions .= ' ' . self::button( $slug, 'uninstall', '停用并卸载', (int) $site['id'], 'button', '确定在这个店铺停用并卸载这个插件？' );
					}
				}
				echo '<tr><td><strong>' . esc_html( $label ) . '</strong><br><small class="pchq-muted">' . esc_html( $host ) . '</small></td><td>' . self::state_label( $row ) . '</td><td>' . $actions . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></div></section>';
		}
		if ( $pending ) {
			// 有任务在处理时每 15 秒刷新一次页面，直到出结果。
			echo '<p class="pchq-muted">有插件正在处理，页面会自动刷新。</p><script>setTimeout(function(){location.reload();},15000);</script>';
		}
		echo '</div>';
	}
}
