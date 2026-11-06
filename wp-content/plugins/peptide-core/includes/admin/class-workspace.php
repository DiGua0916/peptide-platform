<?php
/** Presentation layer for the VialCove store workspace. No business data writes. */
namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Workspace {

	private static bool $navigation_pending = false;

	public static function register_hooks(): void {
		require_once __DIR__ . '/class-workspace-shell.php';
		Workspace_Shell::register_hooks();
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 1000 );
		add_action( 'admin_print_styles', array( self::class, 'navigation_head' ), 0 );
		add_action( 'admin_footer', array( self::class, 'navigation_ready' ), 0 );
		add_action( 'adminmenu', array( self::class, 'brand' ), 1000 );
		add_action( 'in_admin_header', array( self::class, 'header' ) );
		add_action( 'login_enqueue_scripts', array( self::class, 'login_assets' ) );
		add_filter( 'login_headerurl', array( self::class, 'login_url' ) );
		add_filter( 'login_headertext', array( self::class, 'login_title' ) );
	}

	public static function enabled(): bool {
		return ! ( defined( 'PEPTIDE_ADMIN_UI_DISABLED' ) && PEPTIDE_ADMIN_UI_DISABLED ) && (bool) apply_filters( 'peptide_admin_workspace_enabled', true );
	}

	/** Keep native document/editor lifecycles; the browser alone owns the transition. */
	public static function navigation_head(): void {
		if ( self::$navigation_pending || ! self::enabled() || is_network_admin()
			|| ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) ) { return; }
		self::$navigation_pending = true;
		// Critical canvas/opt-in precede ordinary admin styles/scripts. SSR screens
		// release on content parsing; only Woo's empty root gets a bounded footer gate.
		echo '<style id="pc-workspace-navigation">html,body{background:#f7f7f7}@view-transition{navigation:auto}@media(prefers-reduced-motion:reduce){@view-transition{navigation:none}}</style>';
		// No render-blocking <link rel="expect">: it kept every admin screen blank until its
		// footer was generated (code review 2026-11-06). Pages now paint progressively while the
		// cross-document view transition is kept. The ready marker stays for existing listeners.
	}

	/** admin_footer follows wpbody-content, before the normal footer script queue. */
	public static function navigation_ready(): void {
		if ( ! self::$navigation_pending ) { return; }
		self::$navigation_pending = false;
		// Always release an expectation already emitted, even if a filter changes
		// later in this request. Woo's empty React root may add one bounded blocker;
		// the ordinary marker still releases when inline JS is disabled by CSP.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '';
		if ( 'wc-admin' === $page && self::enabled() ) {
			echo wp_get_inline_script_tag( self::react_navigation_readiness(), array( 'id' => 'pc-workspace-react-readiness' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script; core applies inline-script attributes/CSP nonce filters.
		}
		echo '<span id="pc-workspace-content-ready" hidden aria-hidden="true"></span>';
	}

	/** Wait only for Woo's first layout commit, never report/API/data completion. */
	private static function react_navigation_readiness(): string {
		return <<<'JS'
(function () {
  'use strict';
  var body = document.body;
  if (!body || !body.classList.contains('pc-admin-workspace') || !body.classList.contains('woocommerce-admin-page') || body.classList.contains('woocommerce-embed-page')) return;
  if (!document.querySelector('link[rel="expect"][href="#pc-workspace-content-ready"]') || document.getElementById('pc-workspace-react-expect') || document.getElementById('pc-workspace-react-ready')) return;
  var root = document.querySelector('#wpbody-content > .wrap > #root');
  if (!root || typeof MutationObserver !== 'function' || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
  function ready() {
    // Woo synchronously adds an empty CES tracking div before React commits.
    // Mere child nodes therefore do not prove that the application is painted.
    if (root.querySelector('.woocommerce-global-error-boundary')) return true;
    // Woo hides the whole work pane until its first mount effect removes this
    // class. Observe that native change, without changing it or waiting for data.
    return !body.classList.contains('woocommerce-admin-is-loading') && !!root.querySelector('.woocommerce-layout .woocommerce-layout__main');
  }
  if (ready()) return;
  var gate = document.createElement('link');
  try { if (!gate.relList || !gate.relList.supports || !gate.relList.supports('expect')) return; }
  catch (_) { return; }
  gate.id = 'pc-workspace-react-expect';
  gate.rel = 'expect';
  gate.href = '#pc-workspace-react-ready';
  gate.setAttribute('blocking', 'render');
  var observer = null;
  var timer = null;
  var finished = false;
  function finish() {
    if (finished) return;
    finished = true;
    if (observer) observer.disconnect();
    if (timer !== null) window.clearTimeout(timer);
    window.removeEventListener('pagehide', finish);
    try {
      var marker = document.createElement('span');
      marker.id = 'pc-workspace-react-ready';
      marker.hidden = true;
      marker.setAttribute('aria-hidden', 'true');
      if (document.body) document.body.appendChild(marker);
    } finally {
      if (gate.parentNode) gate.parentNode.removeChild(gate);
    }
  }
  function check() { if (!root.isConnected || ready()) finish(); }
  try {
    // Install the fallback before adding a render blocker. A CSP-blocked script
    // never adds this link, so the server-rendered ordinary marker stays enough.
    timer = window.setTimeout(finish, 700);
    observer = new MutationObserver(check);
    window.addEventListener('pagehide', finish, {once: true});
    document.head.appendChild(gate);
    observer.observe(root, {childList: true, subtree: true});
    observer.observe(body, {attributes: true, attributeFilter: ['class']});
    check();
  } catch (_) { finish(); }
}());
JS;
	}

	private static function canvas(): bool {
		$screen = get_current_screen();
		return $screen && str_contains( $screen->id, 'pc-design-editor' );
	}

	public static function body_class( string $classes ): string {
		if ( ! self::enabled() || is_network_admin() ) { return $classes; }
		$classes .= ' pc-admin-workspace';
		$screen = get_current_screen();
		if ( $screen && 'product' === $screen->post_type && 'post' === $screen->base ) { $classes .= ' pc-admin-product'; }
		return $classes;
	}

	public static function enqueue(): void {
		if ( ! self::enabled() || is_network_admin() ) { return; }
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file );
		wp_enqueue_style( 'pc-admin-workspace', $url . 'admin-workspace.css', array(), $ver( 'admin-workspace.css' ) );
		if ( self::canvas() ) {
			wp_enqueue_style( 'pc-editor-workspace', $url . 'editor-workspace.css', array( 'pc-admin-workspace' ), $ver( 'editor-workspace.css' ) );
			return;
		}
		self::trim_optional_assets();
		wp_enqueue_script( 'pc-admin-workspace', $url . 'admin-workspace.js', array(), $ver( 'admin-workspace.js' ), true );
		wp_enqueue_script( 'pc-workspace-navigation-feedback', $url . 'workspace-navigation-feedback.js', array( 'pc-admin-workspace' ), $ver( 'workspace-navigation-feedback.js' ), true );
		wp_enqueue_script( 'pc-workspace-touch', $url . 'workspace-touch.js', array( 'pc-admin-workspace' ), $ver( 'workspace-touch.js' ), true );
		$home = \PeptideCore\Staff::is_staff( get_current_user_id() ) ? \PeptideCore\Staff::home_url( get_current_user_id() ) : Overview_Page::url();
		wp_add_inline_script( 'pc-admin-workspace', 'window.pcWorkspaceHome=' . wp_json_encode( $home ) . ';', 'before' );
		$screen = get_current_screen();
		if ( $screen && str_contains( $screen->id, 'pc-visitors' ) ) {
			wp_enqueue_style( 'pc-tracking-workspace', $url . 'tracking-workspace.css', array( 'pc-admin-workspace' ), $ver( 'tracking-workspace.css' ) );
			wp_enqueue_script( 'pc-tracking-workspace', $url . 'tracking-workspace.js', array( 'pc-admin-workspace' ), $ver( 'tracking-workspace.js' ), true );
		}
		if ( $screen && str_contains( $screen->id, 'pc-overview' ) ) {
			wp_enqueue_style( 'pc-overview-workspace', $url . 'overview-workspace.css', array( 'pc-admin-workspace' ), $ver( 'overview-workspace.css' ) );
		}
		if ( $screen && 'product' === $screen->post_type && 'post' === $screen->base ) {
			wp_enqueue_style( 'pc-product-workspace', $url . 'product-workspace.css', array( 'pc-admin-workspace' ), $ver( 'product-workspace.css' ) );
			wp_enqueue_script( 'pc-product-workspace', $url . 'product-workspace.js', array( 'pc-admin-workspace' ), $ver( 'product-workspace.js' ), true );
		}
	}

	/**
	 * Store pages (pc-*) do not use the global command palette, entity or abilities clients;
	 * on WordPress 7.1 / Woo 11.2 those roots pull ~3.5 MB of editor packages into every screen.
	 * Previously limited to three pages (code review 2026-11-06). The visual editor is excluded.
	 */
	public static function trims_optional_assets( string $page ): bool {
		return 1 === preg_match( '/\Apc-[a-z0-9-]{1,60}\z/', $page ) && 'pc-design-editor' !== $page;
	}

	private static function trim_optional_assets(): void {
		$screen = get_current_screen();
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '';
		if ( ! $screen || ! self::trims_optional_assets( $page )
			|| ! str_ends_with( $screen->id, '_page_' . $page )
			|| ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST )
			|| $screen->is_block_editor() ) { return; }
		// Dequeue roots only. Shared packages remain registered and load if another feature needs them.
		foreach ( array( 'wc-admin-command-palette', 'wc-admin-command-palette-analytics', 'wp-core-commands', 'wp-commands', 'wc-entities', 'wp-abilities' ) as $handle ) {
			wp_dequeue_script( $handle );
		}
		wp_dequeue_style( 'wp-commands' );
	}

	public static function brand(): void {
		if ( ! self::enabled() || self::canvas() || is_network_admin() ) { return; }
		echo '<li class="pc-workspace-brand-item"><a class="pc-workspace-brand" href="' . esc_url( admin_url( 'admin.php?page=pc-overview' ) ) . '"><span class="pc-workspace-mark" aria-hidden="true"><svg viewBox="0 0 28 24" width="28" height="24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m4 15 8-7 12 8"/><circle cx="4" cy="15" r="2.5" fill="currentColor"/><circle cx="12" cy="8" r="2.5" fill="currentColor"/><circle cx="24" cy="16" r="2.5" fill="currentColor"/></svg></span><span class="pc-workspace-brand__name">VialCove<small>客户后台</small></span></a></li>';
	}

	public static function header(): void {
		if ( ! self::enabled() || self::canvas() || is_network_admin() ) { return; }
		global $title;
		$label = wp_strip_all_tags( (string) $title );
		$screen = get_current_screen();
		$is_product = $screen && 'product' === $screen->post_type && 'post' === $screen->base;
		$back_url = admin_url( $is_product ? 'edit.php?post_type=product' : 'admin.php?page=pc-overview' );
		echo '<header class="pc-workspace-header" aria-label="工作区"><button type="button" class="pc-workspace-nav-toggle" data-vc-nav-toggle aria-label="打开导航" aria-controls="adminmenuwrap" aria-expanded="false" hidden><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><nav class="pc-workspace-header__context" aria-label="当前位置"><a href="' . esc_url( $back_url ) . '">' . ( $is_product ? '商品管理' : '客户后台' ) . '</a><span aria-hidden="true">/</span><strong aria-current="page">' . esc_html( $label ) . '</strong></nav><div class="pc-workspace-header__actions"><span class="pc-workspace-store">' . esc_html( get_bloginfo( 'name' ) ) . '</span><a href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener">查看店铺<span class="screen-reader-text">（在新标签页打开）</span></a></div></header>';
	}

	public static function login_assets(): void {
		if ( ! self::enabled() ) { return; }
		wp_enqueue_style( 'pc-workspace-login', plugins_url( 'assets/workspace-login.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) filemtime( PEPTIDE_CORE_DIR . '/assets/workspace-login.css' ) );
	}

	public static function login_url( string $url ): string { return self::enabled() ? home_url( '/' ) : $url; }
	public static function login_title( string $title ): string { return self::enabled() ? 'VialCove 客户后台' : $title; }
}
