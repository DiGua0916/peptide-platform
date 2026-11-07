<?php
/**
 * 总后台的后台菜单和表单处理。菜单：总后台 → 概览、店铺、告警。
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
	private static bool $navigation_pending = false;

	private const ACTIONS = array( 'pchq_site_refresh', 'pchq_site_rename', 'pchq_site_delete', 'pchq_site_retire', 'pchq_site_server', 'pchq_site_config', 'pchq_secret_regenerate', 'pchq_server_save', 'pchq_server_delete', 'pchq_login', 'pchq_alert_close' );

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'dialogs' ), 1 );
		add_filter( 'script_loader_src', array( self::class, 'wordpress_dialogs' ), 18 );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_menu', array( self::class, 'clean_menu' ), 999 );
		add_action( 'admin_init', array( self::class, 'redirect_dashboard' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 1000 );
		add_filter( 'admin_body_class', array( self::class, 'workspace_body_class' ) );
		add_action( 'in_admin_header', array( self::class, 'workspace_header' ) );
		add_action( 'admin_head', array( self::class, 'navigation_meta' ) );
		add_action( 'admin_print_styles', array( self::class, 'navigation_continuity' ), 0 );
		add_action( 'admin_footer', array( self::class, 'navigation_ready' ), 0 );
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
		// Retired extra-account screen: a store uses its provisioning login.
		if ( 'admin.php' === $pagenow && ( $_GET['page'] ?? '' ) === 'pchq-accounts' && current_user_can( self::CAP ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . Sites_Page::SLUG ) );
			exit;
		}
		if ( 'index.php' === $pagenow && current_user_can( self::CAP ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . Dashboard_Page::SLUG ) );
			exit;
		}
	}

	/** Hash-matched WordPress adapters keep core files and beforeunload guards intact. */
	public static function wordpress_dialogs( string $src ): string {
		global $wp_version;
		if ( ! is_admin() ) { return $src; }
		$name = str_replace( '.min.js', '.js', basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
		$relative = null;
		if ( str_starts_with( $src, admin_url( 'js/' ) ) || str_starts_with( '/' . ltrim( $src, '/' ), '/wp-admin/js/' ) ) { $relative = 'wp-admin/js/' . $name; }
		elseif ( str_starts_with( $src, includes_url( 'js/' ) ) || str_starts_with( '/' . ltrim( $src, '/' ), '/' . WPINC . '/js/' ) ) { $relative = WPINC . '/js/' . $name; }
		if ( null === $relative ) { return $src; }
		static $manifest = null;
		if ( null === $manifest ) { $manifest = json_decode( (string) file_get_contents( PEPTIDE_HQ_DIR . '/assets/wordpress-dialogs/manifest.json' ), true ); }
		if ( ! is_array( $manifest ) || $manifest['version'] !== $wp_version || ! isset( $manifest['files'][ $relative ] ) ) { return $src; }
		$file = ABSPATH . $relative;
		$record = $manifest['files'][ $relative ];
		if ( ! is_file( $file ) || hash_file( 'sha256', $file ) !== $record['upstream'] ) { return $src; }
		return plugins_url( 'assets/wordpress-dialogs/' . $name, PEPTIDE_HQ_FILE ) . '?ver=' . substr( $record['adapted'], 0, 16 );
	}

	public static function dialogs(): void {
		// Keep adapted core dependencies in their normal order rather than load-scripts.php concatenation.
		$GLOBALS['concatenate_scripts'] = false;
		$version = PEPTIDE_HQ_VERSION . '.' . (int) filemtime( PEPTIDE_HQ_DIR . '/assets/admin-dialogs.js' );
		wp_enqueue_style( 'peptide-hq-dialogs', plugins_url( 'assets/admin-dialogs.css', PEPTIDE_HQ_FILE ), array(), $version );
		wp_enqueue_script( 'peptide-hq-dialogs', plugins_url( 'assets/admin-dialogs.js', PEPTIDE_HQ_FILE ), array(), $version, false );
	}

	public static function enqueue( string $hook ): void {
		if ( ! self::workspace_enabled() ) {
			return;
		}
		self::trim_command_palette();
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
		$file = PEPTIDE_HQ_DIR . '/assets/workspace-touch.js';
		wp_enqueue_script( 'peptide-hq-touch', plugins_url( 'assets/workspace-touch.js', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '';
		if ( current_user_can( self::CAP ) && isset( self::navigation_policy()[ $page ] ) ) {
			// All first-party HQ controllers are loaded once; fetched HTML never executes scripts.
			if ( class_exists( Operations_Page::class ) ) { Operations_Page::workspace_assets(); }
			if ( class_exists( Provision_Page::class ) ) { Provision_Page::workspace_assets(); }
			if ( class_exists( \VialCove\Panapay\Hub_Page::class ) ) { \VialCove\Panapay\Hub_Page::assets( 'pchq-payments' ); }
			$file = PEPTIDE_HQ_DIR . '/assets/hq-navigation.js';
			wp_enqueue_script( 'peptide-hq-navigation', plugins_url( 'assets/hq-navigation.js', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/** Explicit read-only URL shapes for every first-party HQ screen. POST stays native. */
	public static function navigation_policy(): array {
		return array(
			'pchq' => array( 'range' => array( 'today', 'yesterday', '7d', '30d' ) ),
			'pchq-sites' => array( 'site_server' => 'positiveId', 'site_tools' => 'positiveId' ),
			'pchq-servers' => array( 'edit' => 'positiveId' ),
			'pchq-alerts' => array(),
			'pchq-site-recycle' => array( 'restore_requested' => array( '1' ), 'retry_requested' => array( '1' ) ),
			'pchq-operations' => array( 'tab' => array( 'tasks', 'releases', 'create', 'backups', 'agents' ), 'site' => 'signedId' ),
			'pchq-create-store' => array( 'job' => 'uuid4' ),
			'pchq-payments' => array( 'site_id' => 'nonnegativeId', 'state' => array( '', 'creating', 'created', 'unknown', 'failed', 'succeeded', 'confirmed_failed', 'pending', 'delivered', 'review' ), 'paged' => 'pageNumber', 'deleted' => array( '1' ), 'edit_account' => 'accountId', 'new_account' => array( '1' ), 'account_error' => array( '1' ) ),
		);
	}

	/** Native WP screens keep their own scripts and POST lifecycle without a blank paint. */
	public static function navigation_continuity(): void {
		if ( self::$navigation_pending || ! self::workspace_enabled() || ! current_user_can( self::CAP )
			|| ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) ) { return; }
		self::$navigation_pending = true;
		echo '<style id="pchq-workspace-navigation">html,body{background:#f5f5f5}@view-transition{navigation:auto}@media(prefers-reduced-motion:reduce){@view-transition{navigation:none}}</style>';
		// No render-blocking <link rel="expect"> (code review 2026-11-06): screens paint progressively.
	}
	public static function navigation_ready(): void {
		if ( ! self::$navigation_pending ) { return; }
		self::$navigation_pending = false;
		// Release the emitted expectation even if a late filter changes workspace eligibility.
		echo '<span id="pchq-document-ready" hidden aria-hidden="true"></span>';
	}

	/** Identity/build markers let the browser reject login pages and stale shells. */
	public static function navigation_meta(): void {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '';
		if ( ! self::workspace_enabled() || ! current_user_can( self::CAP ) || ! isset( self::navigation_policy()[ $page ] ) ) { return; }
		$screen = get_current_screen();
		if ( ! $screen || $screen->is_block_editor() ) { return; }
		global $hook_suffix;
		// Match wp-admin/admin-header.php exactly, including encoded Chinese menu names.
		$screen_class = preg_replace( '/[^a-z0-9_-]+/i', '-', (string) ( $hook_suffix ?? $screen->id ) );
		$build = array();
		$files = array_map( static fn( string $relative ): string => PEPTIDE_HQ_DIR . '/' . $relative, array( 'assets/hq-admin.css', 'assets/hq-workspace.css', 'assets/hq-workspace.js', 'assets/workspace-touch.js', 'assets/hq-navigation.js', 'assets/hq-operations.css', 'assets/hq-operations.js', 'assets/hq-provision.js', 'includes/admin/class-menu.php', 'includes/admin/class-operations-page.php', 'includes/admin/class-provision-page.php' ) );
		if ( defined( 'PEPTIDE_PANAPAY_DIR' ) && class_exists( \VialCove\Panapay\Hub_Page::class ) ) {
			foreach ( array( 'assets/hub.css', 'assets/hub.js', 'includes/class-hub-page.php' ) as $relative ) { $files[] = PEPTIDE_PANAPAY_DIR . '/' . $relative; }
		}
		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) { return; }
			$build[] = hash_file( 'sha256', $file );
		}
		printf( '<meta name="pchq-navigation" content="%1$s" data-base="%2$s" data-page="%3$s" data-screen="%4$s" data-session="%5$s" data-policy="%6$s">',
			esc_attr( hash( 'sha256', implode( '|', $build ) ) ), esc_url( admin_url( 'admin.php' ) ), esc_attr( $page ), esc_attr( $screen_class ),
			esc_attr( wp_create_nonce( 'pchq_navigation_identity' ) ), esc_attr( wp_json_encode( self::navigation_policy() ) ) );
	}

	/** Keep the native palette on editors; HQ's own operational screens use the normal menu. */
	private static function trim_command_palette(): void {
		$screen = get_current_screen();
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '';
		if ( ! $screen || is_network_admin() || ! preg_match( '/\Apchq(?:-[a-z0-9-]+)?\z/', $page )
			|| ! str_ends_with( $screen->id, '_page_' . $page ) || $screen->is_block_editor() ) { return; }
		foreach ( array( 'wp-core-commands', 'wp-commands' ) as $handle ) { wp_dequeue_script( $handle ); }
		wp_dequeue_style( 'wp-commands' );
	}

	/** Presentation hooks also used on the separate HQ login screen. */
	public static function register_login_hooks(): void {
		add_filter( 'determine_locale', static function ( string $locale ): string {
			return 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) && self::workspace_enabled() ? 'zh_CN' : $locale;
		} );
		add_action( 'login_enqueue_scripts', array( self::class, 'login_assets' ) );
		add_filter( 'login_headerurl', static fn(): string => admin_url() );
		add_filter( 'login_headertext', static fn(): string => 'VialCove' );
		add_action( 'login_init', array( self::class, 'login_presentation' ) );
	}

	/** Login-only copy; native authentication and cookie lifetimes remain unchanged. */
	public static function login_presentation(): void {
		if ( ! self::workspace_enabled() ) { return; }
		add_filter( 'login_display_language_dropdown', '__return_false' );
		add_filter( 'login_site_html_link', '__return_empty_string' );
		add_filter( 'gettext', static function ( string $translated, string $text, string $domain ): string {
			$labels = array(
				'Username or Email Address' => '用户名或邮箱', 'Password' => '密码',
				'Remember Me' => '记住登录', 'Log In' => '登录',
				'Lost your password?' => '忘记密码？', 'Show password' => '显示密码',
				'Hide password' => '隐藏密码', 'Log In Again' => '重新登录',
				'&larr; Go to %s' => '&larr; 返回 %s',
			);
			return 'default' === $domain && isset( $labels[ $text ] ) ? $labels[ $text ] : $translated;
		}, 10, 3 );
		// WP 7.1's toggletip can obscure the submit button on small touch screens.
		add_filter( 'login_remember_me_help_text', '__return_empty_string' );
		add_action( 'login_form', static function (): void {
			echo '<p class="pchq-login-hint">仅在私人设备上勾选“记住登录”。</p>';
		} );
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
			<span class="pchq-workspace-mark" aria-hidden="true"><svg viewBox="0 0 28 24" width="28" height="24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m4 15 8-7 12 8"/><circle cx="4" cy="15" r="2.5" fill="currentColor"/><circle cx="12" cy="8" r="2.5" fill="currentColor"/><circle cx="24" cy="16" r="2.5" fill="currentColor"/></svg></span>
			<span><strong>VialCove</strong><small><?php echo esc_html( $zh ? '管理后台' : 'Management' ); ?></small></span>
		</a>
		<header class="pchq-workspace-head">
			<button type="button" class="pchq-mobile-nav-toggle" data-vc-nav-toggle aria-label="打开导航" aria-controls="adminmenuwrap" aria-expanded="false" hidden><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
			<nav class="pchq-workspace-location" aria-label="<?php echo esc_attr( $zh ? '当前位置' : 'Current location' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Dashboard_Page::SLUG ) ); ?>"><?php echo esc_html( $zh ? '管理后台' : 'Management' ); ?></a>
				<span aria-hidden="true">/</span><span aria-current="page"><?php echo esc_html( wp_strip_all_tags( (string) $title ) ); ?></span>
			</nav>
			<div class="pchq-workspace-user">
				<a class="pchq-workspace-account" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>" title="<?php echo esc_attr( $zh ? '个人资料' : 'Profile' ); ?>"><span class="pchq-workspace-account__mark" aria-hidden="true"><?php echo esc_html( $zh ? '我' : 'Me' ); ?></span><span><?php echo esc_html( $user->display_name ); ?></span></a>
				<?php // The WordPress toolbar (with its log-out link) is hidden on desktop in Layout v2. ?>
				<a class="pchq-workspace-logout" href="<?php echo esc_url( wp_logout_url( admin_url() ) ); ?>"><?php echo esc_html( $zh ? '退出登录' : 'Log out' ); ?></a>
			</div>
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
				// Old bookmarked GET actions must never erase registration or start a removal.
				self::flash( 'error', '旧移除入口已停用。请从店铺“更多”中选择“移除店铺”，完整下线并保留72小时恢复期。' );
				break;

			case 'pchq_site_retire':
				if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
					wp_die( '移除店铺必须通过表单提交。', '', array( 'response' => 405 ) );
				}
				$site = isset( $_POST['site'] ) && is_scalar( $_POST['site'] ) ? absint( $_POST['site'] ) : 0;
				$res = \PeptideHq\Site_Lifecycle::request( $site, 'retire' );
				self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : '已提交完整下线任务。成功下线后数据保留72小时，可在店铺回收站查看进度及恢复。' );
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
				if ( $info && '' !== $url && \PeptideHq\Site_Urls::allows_login( $info, $url ) ) {
					wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- 店铺地址来自总后台保存的记录。
					exit;
				}
				self::flash( 'error', is_wp_error( $res ) ? $res->get_error_message() : __( 'The store did not return a login link.', 'peptide-hq' ) );
				$back = admin_url( 'admin.php?page=' . Dashboard_Page::SLUG );
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
