<?php
/** Presentation layer for the VialCove store workspace. No business data writes. */
namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Workspace {

	public static function register_hooks(): void {
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 1000 );
		add_action( 'adminmenu', array( self::class, 'brand' ), 1000 );
		add_action( 'in_admin_header', array( self::class, 'header' ) );
		add_action( 'login_enqueue_scripts', array( self::class, 'login_assets' ) );
		add_filter( 'login_headerurl', array( self::class, 'login_url' ) );
		add_filter( 'login_headertext', array( self::class, 'login_title' ) );
	}

	public static function enabled(): bool {
		return ! ( defined( 'PEPTIDE_ADMIN_UI_DISABLED' ) && PEPTIDE_ADMIN_UI_DISABLED ) && (bool) apply_filters( 'peptide_admin_workspace_enabled', true );
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
		wp_enqueue_script( 'pc-admin-workspace', $url . 'admin-workspace.js', array(), $ver( 'admin-workspace.js' ), true );
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

	public static function brand(): void {
		if ( ! self::enabled() || self::canvas() || is_network_admin() ) { return; }
		echo '<li class="pc-workspace-brand-item"><a class="pc-workspace-brand" href="' . esc_url( admin_url( 'admin.php?page=pc-overview' ) ) . '"><span class="pc-workspace-mark" aria-hidden="true">V</span><span class="pc-workspace-brand__name">VialCove<small>客户后台</small></span></a></li>';
	}

	public static function header(): void {
		if ( ! self::enabled() || self::canvas() || is_network_admin() ) { return; }
		global $title;
		$label = wp_strip_all_tags( (string) $title );
		$screen = get_current_screen();
		$is_product = $screen && 'product' === $screen->post_type && 'post' === $screen->base;
		$back_url = admin_url( $is_product ? 'edit.php?post_type=product' : 'admin.php?page=pc-overview' );
		echo '<header class="pc-workspace-header" aria-label="工作区"><nav class="pc-workspace-header__context" aria-label="当前位置"><a href="' . esc_url( $back_url ) . '">' . ( $is_product ? '商品管理' : '客户后台' ) . '</a><span aria-hidden="true">/</span><strong aria-current="page">' . esc_html( $label ) . '</strong></nav><div class="pc-workspace-header__actions"><span class="pc-workspace-store">' . esc_html( get_bloginfo( 'name' ) ) . '</span><a href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener">查看店铺<span class="screen-reader-text">（在新标签页打开）</span></a></div></header>';
	}

	public static function login_assets(): void {
		if ( ! self::enabled() ) { return; }
		wp_enqueue_style( 'pc-workspace-login', plugins_url( 'assets/workspace-login.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) filemtime( PEPTIDE_CORE_DIR . '/assets/workspace-login.css' ) );
	}

	public static function login_url( string $url ): string { return self::enabled() ? home_url( '/' ) : $url; }
	public static function login_title( string $title ): string { return self::enabled() ? 'VialCove 客户后台' : $title; }
}
