<?php
/** Persistent merchant chrome with document-isolated, authenticated content pages. */
namespace PeptideCore\Admin;
defined( 'ABSPATH' ) || exit;

final class Workspace_Shell {
    private static bool $shell = false;

    public static function register_hooks(): void {
        add_action( 'current_screen', array( self::class, 'screen' ), 100 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), PHP_INT_MAX );
    }

    public static function embedded(): bool {
        // A native POST redirect may retain its embedded referer. A top-level
        // document must regain normal chrome rather than hiding its navigation.
        if ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) {
            return 'iframe' === $_SERVER['HTTP_SEC_FETCH_DEST'];
        }
        return isset( $_GET['pc_embed'] ) && '1' === $_GET['pc_embed'];
    }

    public static function screen( $screen ): void {
        if ( is_network_admin() || wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) { return; }
        if ( self::embedded() ) { return; }
        if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $screen || $screen->is_block_editor() ) { return; }
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '';
        // Start the shell only on our ordinary dashboard/data landing URLs.
        // Native editor, action, export and POST routes keep their existing lifecycle.
        if ( ! in_array( $page, array( 'pc-overview', 'pc-data' ), true ) || count( $_GET ) !== 1 ) { return; }
        self::$shell = true;
        remove_action( 'admin_enqueue_scripts', array( Overview_Page::class, 'enqueue' ) );
        remove_action( 'admin_enqueue_scripts', array( Data_Pages::class, 'enqueue' ) );
        add_action( 'load-' . $screen->id, array( self::class, 'render' ), 0 );
    }

    public static function assets(): void {
        if ( ! self::$shell && ! self::embedded() ) { return; }
        $url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
        $version = static fn( $file ) => PEPTIDE_CORE_VERSION . '.' . filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file );
        wp_enqueue_style( 'pc-workspace-shell', $url . 'workspace-shell.css', array( 'pc-admin-workspace' ), $version( 'workspace-shell.css' ) );
        if ( self::$shell ) {
            wp_enqueue_script( 'pc-workspace-shell', $url . 'workspace-shell.js', array(), $version( 'workspace-shell.js' ), true );
        } else {
            wp_add_inline_style( 'pc-workspace-shell', 'html.wp-toolbar{padding-top:0!important}#wpadminbar,#adminmenumain,.pc-workspace-header,#wpfooter{display:none!important}#wpcontent,#wpfooter{margin-left:0!important}#wpcontent{padding-left:0!important}body.pc-admin-workspace #wpbody-content{padding:20px!important}html,body{min-height:100%;background:#f7f7f7}' );
        }
    }

    public static function render(): void {
        // Notices belong to the active authenticated page, not to persistent chrome.
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        require ABSPATH . 'wp-admin/admin-header.php';
        echo '<div id="pc-workspace-shell" data-page="' . esc_attr( sanitize_key( $_GET['page'] ) ) . '"><p class="pc-shell-status" role="status">正在打开页面…</p><noscript><p>请启用 JavaScript，或<a href="' . esc_url( add_query_arg( 'pc_embed', '1' ) ) . '">打开标准后台</a>。</p></noscript></div>';
        require ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }
}
