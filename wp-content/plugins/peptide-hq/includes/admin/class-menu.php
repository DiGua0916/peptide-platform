<?php
/**
 * 总后台的后台菜单和表单处理。菜单：总后台 → 概览、店铺、销售账号、告警。
 * 这个 WordPress 只用来当总后台：文章、媒体、页面、评论、外观、插件、工具、通用设置菜单去掉，仪表盘直接转到概览。
 * 所有页面和操作都只给管理员（manage_options）。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Alerts;
use PeptideHq\Client;
use PeptideHq\Monitor;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const CAP = 'manage_options';

	private const ACTIONS = array( 'pchq_site_refresh', 'pchq_site_rename', 'pchq_site_delete', 'pchq_site_server', 'pchq_site_config', 'pchq_secret_regenerate', 'pchq_server_save', 'pchq_server_delete', 'pchq_login', 'pchq_account_save', 'pchq_account_delete', 'pchq_account_toggle', 'pchq_alert_close' );

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_menu', array( self::class, 'clean_menu' ), 999 );
		add_action( 'admin_init', array( self::class, 'redirect_dashboard' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 1000 );
		add_filter( 'admin_body_class', array( self::class, 'workspace_body_class' ) );
		add_action( 'in_admin_header', array( self::class, 'workspace_header' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
	}

	/** 店铺权限模块（和店铺 peptide-core 的 Staff::modules() 同样的键）。 */
	public static function modules(): array {
		return array(
			'orders'    => __( 'Orders', 'peptide-hq' ),
			'products'  => __( 'Products', 'peptide-hq' ),
			'customers' => __( 'Customers', 'peptide-hq' ),
			'marketing' => __( 'Marketing campaigns', 'peptide-hq' ),
			'data'      => __( 'Data', 'peptide-hq' ),
			'blog'      => __( 'Blog', 'peptide-hq' ),
			'design'    => __( 'Store design', 'peptide-hq' ),
			'settings'  => __( 'Store settings', 'peptide-hq' ),
			'backend'   => __( 'Admin settings', 'peptide-hq' ),
			'apps'      => __( 'App center', 'peptide-hq' ),
		);
	}

	public static function menu(): void {
		$count = Alerts::open_count();
		$badge = $count ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>' : '';
		add_menu_page( __( 'Master admin', 'peptide-hq' ), __( 'Master admin', 'peptide-hq' ) . $badge, self::CAP, Dashboard_Page::SLUG, array( Dashboard_Page::class, 'render' ), 'dashicons-networking', 2 );
		add_submenu_page( Dashboard_Page::SLUG, __( 'Overview', 'peptide-hq' ), __( 'Overview', 'peptide-hq' ), self::CAP, Dashboard_Page::SLUG, array( Dashboard_Page::class, 'render' ) );
		add_submenu_page( Dashboard_Page::SLUG, __( 'Stores', 'peptide-hq' ), __( 'Stores', 'peptide-hq' ), self::CAP, Sites_Page::SLUG, array( Sites_Page::class, 'render' ) );
		add_submenu_page( Dashboard_Page::SLUG, __( 'Servers', 'peptide-hq' ), __( 'Servers', 'peptide-hq' ), self::CAP, Servers_Page::SLUG, array( Servers_Page::class, 'render' ) );
		add_submenu_page( Dashboard_Page::SLUG, __( 'Salesperson accounts', 'peptide-hq' ), __( 'Salesperson accounts', 'peptide-hq' ), self::CAP, Accounts_Page::SLUG, array( Accounts_Page::class, 'render' ) );
		add_submenu_page( Dashboard_Page::SLUG, __( 'Alerts', 'peptide-hq' ), __( 'Alerts', 'peptide-hq' ) . $badge, self::CAP, Alerts_Page::SLUG, array( Alerts_Page::class, 'render' ) );
	}

	/** 这个 WordPress 只当总后台用，去掉用不上的菜单（页面地址还在）。 */
	public static function clean_menu(): void {
		foreach ( array( 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'tools.php', 'options-general.php' ) as $slug ) {
			remove_menu_page( $slug );
		}
	}

	public static function redirect_dashboard(): void {
		global $pagenow;
		if ( 'index.php' === $pagenow && current_user_can( self::CAP ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . Dashboard_Page::SLUG ) );
			exit;
		}
	}

	public static function enqueue( string $hook ): void {
		if ( ! self::workspace_enabled() ) {
			return;
		}
		$dependencies = array();
		if ( str_contains( $hook, 'pchq' ) ) {
			$file = PEPTIDE_HQ_DIR . '/assets/hq-admin.css';
			wp_enqueue_style( 'peptide-hq-admin', plugins_url( 'assets/hq-admin.css', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$dependencies[] = 'peptide-hq-admin';
		}
		$file = PEPTIDE_HQ_DIR . '/assets/hq-workspace.css';
		wp_enqueue_style( 'peptide-hq-workspace', plugins_url( 'assets/hq-workspace.css', PEPTIDE_HQ_FILE ), $dependencies, PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$file = PEPTIDE_HQ_DIR . '/assets/hq-workspace.js';
		wp_enqueue_script( 'peptide-hq-workspace', plugins_url( 'assets/hq-workspace.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Presentation hooks also used on the separate HQ login screen. */
	public static function register_login_hooks(): void {
		add_action( 'login_enqueue_scripts', array( self::class, 'login_assets' ) );
		add_filter( 'login_headerurl', static fn(): string => admin_url() );
		add_filter( 'login_headertext', static fn(): string => 'VialCove' );
	}

	public static function login_assets(): void {
		if ( ! self::workspace_enabled() ) { return; }
		$file = PEPTIDE_HQ_DIR . '/assets/hq-login.css';
		wp_enqueue_style( 'peptide-hq-login', plugins_url( 'assets/hq-login.css', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function workspace_enabled(): bool {
		return ! ( defined( 'PEPTIDE_ADMIN_UI_DISABLED' ) && PEPTIDE_ADMIN_UI_DISABLED ) && ! wp_doing_ajax() && ! ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST );
	}

	public static function workspace_body_class( string $classes ): string {
		return self::workspace_enabled() ? $classes . ' pchq-workspace' : $classes;
	}

	/** A read-only workspace frame; native WordPress forms and menu behavior remain intact. */
	public static function workspace_header(): void {
		if ( ! self::workspace_enabled() ) {
			return;
		}
		global $title;
		$zh = str_starts_with( determine_locale(), 'zh' );
		$user = wp_get_current_user();
		?>
		<a class="pchq-workspace-brand" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Dashboard_Page::SLUG ) ); ?>">
			<span class="pchq-workspace-mark" aria-hidden="true">V</span>
			<span><strong>VialCove</strong><small><?php echo esc_html( $zh ? '管理后台' : 'Management' ); ?></small></span>
		</a>
		<header class="pchq-workspace-head">
			<nav class="pchq-workspace-location" aria-label="<?php echo esc_attr( $zh ? '当前位置' : 'Current location' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Dashboard_Page::SLUG ) ); ?>"><?php echo esc_html( $zh ? '管理后台' : 'Management' ); ?></a>
				<span aria-hidden="true">/</span><span aria-current="page"><?php echo esc_html( wp_strip_all_tags( (string) $title ) ); ?></span>
			</nav>
			<a class="pchq-workspace-account" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><span class="pchq-workspace-account__mark" aria-hidden="true"><?php echo esc_html( $zh ? '我' : 'Me' ); ?></span><span><?php echo esc_html( $user->display_name ); ?></span></a>
		</header>
		<?php
	}

	// ---------- 提示 ----------

	public static function flash( string $type, string $text ): void {
		set_transient( 'pchq_msg_' . get_current_user_id(), array( $type, $text ), 120 );
	}

	public static function notices(): void {
		$key = 'pchq_msg_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_array( $msg ) && 2 === count( $msg ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $msg[0] ? 'error' : 'success', esc_html( (string) $msg[1] ) );
		}
	}

	public static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	// ---------- 表单处理 ----------

	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-hq' ), 403 );
		}
		check_admin_referer( $action );
		// phpcs:disable WordPress.Security.NonceVerification -- 上面已用 check_admin_referer 校验。
		$site = isset( $_REQUEST['site'] ) ? absint( $_REQUEST['site'] ) : 0;
		$text = static fn( string $k ): string => isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		$back = admin_url( 'admin.php?page=' . Sites_Page::SLUG );

		switch ( $action ) {
			case 'pchq_site_refresh':
				$ids = $site ? array( $site ) : array_keys( Sites::all() );
				$ok  = 0;
				foreach ( $ids as $id ) {
					$ok += (int) Monitor::poll( (int) $id, true );
				}
				/* translators: 1: stores reached, 2: stores checked */
				self::flash( $ok === count( $ids ) ? 'success' : 'error', sprintf( __( 'Refreshed: %1$d of %2$d stores reached.', 'peptide-hq' ), $ok, count( $ids ) ) );
				$back = wp_get_referer() ? wp_get_referer() : $back;
				break;

			case 'pchq_site_rename':
				Sites::rename( $site, $text( 'name' ) );
				self::flash( 'success', __( 'Name saved.', 'peptide-hq' ) );
				break;

			case 'pchq_secret_regenerate':
				\PeptideHq\Register::regenerate();
				self::flash( 'success', __( 'New registration secret created. Stores already connected keep working; use the new lines when you open a new store.', 'peptide-hq' ) );
				break;

			case 'pchq_site_delete':
				Sites::delete( $site );
				self::flash( 'success', __( 'Store removed from the master admin. The store itself is not changed; if it still has the master admin lines in wp-config.php, it joins again by itself within a day.', 'peptide-hq' ) );
				break;

			case 'pchq_site_server':
				$server = isset( $_POST['server'] ) ? absint( $_POST['server'] ) : 0;
				$res    = \PeptideHq\Servers::assign( $site, $server );
				if ( is_wp_error( $res ) ) {
					/* translators: %s: error */
					self::flash( 'error', sprintf( __( 'Server saved, but the store could not be told yet (%s). It is sent again at the next check.', 'peptide-hq' ), $res->get_error_message() ) );
				} else {
					Monitor::poll( $site );
					self::flash( 'success', $server ? __( 'Server assigned. The store’s Domains page now shows this IP for the DNS records.', 'peptide-hq' ) : __( 'Server removed from this store.', 'peptide-hq' ) );
				}
				break;

			case 'pchq_site_config':
				$key  = isset( $_REQUEST['key'] ) ? sanitize_key( wp_unslash( $_REQUEST['key'] ) ) : '';
				$on   = ! empty( $_REQUEST['on'] );
				$conf = array(
					'search'   => array( 'blog_public' => $on ),
					'tracking' => array( 'tracking' => array( 'enabled' => $on ) ),
				)[ $key ] ?? null;
				if ( 'retention' === $key ) {
					$conf = array( 'tracking' => array( 'retention_days' => isset( $_REQUEST['days'] ) ? absint( $_REQUEST['days'] ) : 180 ) );
				}
				$res = null === $conf ? new \WP_Error( 'pchq_bad', 'Unknown setting.' ) : Client::config( $site, $conf );
				if ( ! is_wp_error( $res ) ) {
					Monitor::poll( $site );
				}
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Saved on the store.', 'peptide-hq' ) );
				break;

			case 'pchq_server_save':
				$back = admin_url( 'admin.php?page=' . Servers_Page::SLUG );
				$res  = \PeptideHq\Servers::save( isset( $_POST['server'] ) ? absint( $_POST['server'] ) : 0, $text( 'name' ), $text( 'ip' ), $text( 'note' ) );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
				} else {
					// 改了 IP 的话，马上下发给用这台服务器的店铺。
					foreach ( Sites::all() as $s ) {
						if ( $s['server_id'] === $res ) {
							Client::config( $s['id'], array( 'server_ip' => \PeptideHq\Servers::ip_for_site( $s['id'] ) ) );
						}
					}
					self::flash( 'success', __( 'Server saved.', 'peptide-hq' ) );
				}
				break;

			case 'pchq_server_delete':
				$back = admin_url( 'admin.php?page=' . Servers_Page::SLUG );
				$res  = \PeptideHq\Servers::delete( isset( $_REQUEST['server'] ) ? absint( $_REQUEST['server'] ) : 0 );
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Server deleted.', 'peptide-hq' ) );
				break;

			case 'pchq_login':
				$to   = isset( $_REQUEST['to'] ) ? sanitize_key( wp_unslash( $_REQUEST['to'] ) ) : '';
				$res  = Client::login( $site, isset( \PeptideHq\Platform::tools()[ $to ] ) ? $to : '' );
				$info = Sites::get( $site );
				$url  = is_array( $res ) ? (string) ( $res['url'] ?? '' ) : '';
				// 只跳到这个店铺自己的地址。
				if ( $info && '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( $info['url'], PHP_URL_HOST ) ) {
					wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- 店铺地址来自总后台保存的记录。
					exit;
				}
				self::flash( 'error', is_wp_error( $res ) ? $res->get_error_message() : __( 'The store did not return a login link.', 'peptide-hq' ) );
				$back = admin_url( 'admin.php?page=' . Dashboard_Page::SLUG );
				break;

			case 'pchq_account_save':
				$back    = admin_url( 'admin.php?page=' . Accounts_Page::SLUG . '&site=' . $site );
				$account = array(
					'id'       => isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0,
					'login'    => isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ), true ) : '',
					'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
					'name'     => $text( 'name' ),
					'password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 原样交给店铺加密保存。
					'modules'  => isset( $_POST['modules'] ) ? array_values( array_intersect( array_keys( self::modules() ), array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ) ) ) ) : array(),
					'disabled' => ! empty( $_POST['disabled'] ),
				);
				$res     = Client::staff_save( $site, $account );
				if ( is_wp_error( $res ) ) {
					unset( $account['password'] );
					set_transient( 'pchq_form_' . get_current_user_id(), $account, 300 );
					self::flash( 'error', $res->get_error_message() );
					$back .= $account['id'] ? '&edit=' . $account['id'] : '&add=1';
				} else {
					self::flash( 'success', $account['id'] ? __( 'Salesperson account saved on the store.', 'peptide-hq' ) : __( 'Salesperson account created on the store. Tell the salesperson the account name and password.', 'peptide-hq' ) );
				}
				break;

			case 'pchq_account_toggle':
				$back = admin_url( 'admin.php?page=' . Accounts_Page::SLUG . '&site=' . $site );
				$user = isset( $_REQUEST['user'] ) ? absint( $_REQUEST['user'] ) : 0;
				$list = Client::staff_list( $site );
				$acc  = is_array( $list ) ? current( array_filter( (array) ( $list['accounts'] ?? array() ), static fn( $a ) => (int) $a['id'] === $user ) ) : false;
				if ( ! $acc ) {
					self::flash( 'error', is_wp_error( $list ) ? $list->get_error_message() : __( 'Account not found on the store.', 'peptide-hq' ) );
					break;
				}
				$acc['disabled'] = empty( $acc['disabled'] );
				$res             = Client::staff_save( $site, $acc );
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : ( $acc['disabled'] ? __( 'Account disabled. The salesperson is logged out of the store.', 'peptide-hq' ) : __( 'Account enabled.', 'peptide-hq' ) ) );
				break;

			case 'pchq_account_delete':
				$back = admin_url( 'admin.php?page=' . Accounts_Page::SLUG . '&site=' . $site );
				$res  = Client::staff_delete( $site, isset( $_REQUEST['user'] ) ? absint( $_REQUEST['user'] ) : 0 );
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Account deleted. Its posts and products were moved to the store administrator.', 'peptide-hq' ) );
				break;

			case 'pchq_alert_close':
				Alerts::close( isset( $_REQUEST['alert'] ) ? absint( $_REQUEST['alert'] ) : 0 );
				self::flash( 'success', __( 'Alert marked as handled.', 'peptide-hq' ) );
				$back = admin_url( 'admin.php?page=' . Alerts_Page::SLUG );
				break;
		}
		// phpcs:enable
		wp_safe_redirect( $back );
		exit;
	}

	// ---------- 显示用 ----------

	/** 店铺状态标记。 */
	public static function status_badge( array $site ): string {
		$map   = array(
			'online'  => array( 'is-ok', __( 'Online', 'peptide-hq' ) ),
			'offline' => array( 'is-bad', __( 'Offline', 'peptide-hq' ) ),
			'denied'  => array( 'is-bad', __( 'Code invalid', 'peptide-hq' ) ),
		);
		[ $tone, $label ] = $map[ $site['status'] ] ?? array( 'is-wait', __( 'Checking', 'peptide-hq' ) );
		return '<span class="pchq-badge ' . esc_attr( $tone ) . '" title="' . esc_attr( $site['error'] ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * 金额：按币种分开显示，例如 $1,234.50 或 USD 1,234.50 · EUR 20.00。
	 *
	 * @param array<string,float> $by_currency
	 */
	public static function money( array $by_currency ): string {
		if ( ! $by_currency ) {
			return '$0.00';
		}
		$out = array();
		foreach ( $by_currency as $cur => $amount ) {
			$out[] = ( 'USD' === $cur || '' === $cur ? '$' : $cur . ' ' ) . number_format_i18n( $amount, 2 );
		}
		return implode( ' · ', $out );
	}

	/** 多久以前，例如“3 小时前”。 */
	public static function ago( int $time ): string {
		if ( ! $time ) {
			return '—';
		}
		/* translators: %s: time difference, e.g. 3 hours */
		return sprintf( __( '%s ago', 'peptide-hq' ), human_time_diff( $time ) );
	}
}
