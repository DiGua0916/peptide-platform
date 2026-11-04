<?php
/** Standalone loader contract against a staged plugin and installed, unmodified upstream JS. */
$role = $argv[1];
$root = rtrim( $argv[2], '/' ) . '/';
$plugin = rtrim( $argv[3], '/' );
define( 'ABSPATH', $root );
define( 'WPINC', 'wp-includes' );
define( 'WC_PLUGIN_FILE', $root . 'wp-content/plugins/woocommerce/woocommerce.php' );
define( 'WC_VERSION', '11.2.1' );
define( 'PEPTIDE_CORE_DIR', $plugin );
define( 'PEPTIDE_CORE_FILE', $plugin . '/peptide-core.php' );
define( 'PEPTIDE_HQ_DIR', $plugin );
define( 'PEPTIDE_HQ_FILE', $plugin . '/peptide-hq.php' );
$wp_version = '7.1.4';
function is_admin() { return true; }
function admin_url( $path ) { return 'https://fixture.invalid/wp-admin/' . $path; }
function includes_url( $path ) { return 'https://fixture.invalid/wp-includes/' . $path; }
function plugins_url( $path, $file ) { return 'https://fixture.invalid/wp-content/plugins/' . basename( dirname( $file ) ) . '/' . $path; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
require $plugin . '/includes/admin/' . ( 'hq' === $role ? 'class-menu.php' : 'class-dialogs.php' );
$class = 'hq' === $role ? 'PeptideHq\\Admin\\Menu' : 'PeptideCore\\Admin\\Dialogs';
$class = str_replace( '\\\\', '\\', $class );
$count = 0;
function verify_dialog_asset( $yes, $message ) { global $count; if ( ! $yes ) { throw new RuntimeException( $message ); } ++$count; }
$manifest = json_decode( file_get_contents( $plugin . '/assets/wordpress-dialogs/manifest.json' ), true );
foreach ( $manifest['files'] as $relative => $record ) {
    foreach ( array( $relative, str_replace( '.js', '.min.js', $relative ) ) as $source ) {
        $url = 'https://fixture.invalid/' . $source . '?ver=7.1.4';
        verify_dialog_asset( str_contains( $class::wordpress_dialogs( $url ), '/assets/wordpress-dialogs/' ), 'Missing WP override ' . $source );
        verify_dialog_asset( str_contains( $class::wordpress_dialogs( '/' . $source ), '/assets/wordpress-dialogs/' ), 'Missing relative WP override ' . $source );
    }
}
$foreign = 'https://other.invalid/wp-admin/js/common.min.js';
verify_dialog_asset( $class::wordpress_dialogs( $foreign ) === $foreign, 'Foreign URL rewritten' );
$wp_version = 'unsupported';
$url = 'https://fixture.invalid/wp-admin/js/common.min.js';
verify_dialog_asset( $class::wordpress_dialogs( $url ) === $url, 'Unknown version must retain upstream rather than guessing a patch' );
if ( 'hq' !== $role ) {
    $manifest = json_decode( file_get_contents( $plugin . '/assets/woocommerce-dialogs/manifest.json' ), true );
    foreach ( $manifest['files'] as $name => $record ) {
        foreach ( array( $name, str_replace( '.js', '.min.js', $name ) ) as $source ) {
            $url = plugins_url( 'assets/js/admin/' . $source, WC_PLUGIN_FILE ) . '?ver=11.2.1';
            verify_dialog_asset( str_contains( $class::vendor_dialogs( $url ), '/assets/woocommerce-dialogs/' ), 'Missing Woo override ' . $source );
        }
    }
}
echo json_encode( array( 'passed' => true, 'checks' => $count, 'role' => $role ) );
