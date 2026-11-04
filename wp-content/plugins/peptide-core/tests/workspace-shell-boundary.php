<?php
/** Actual PHP routing boundaries, independent of business data/WordPress boot. */
define( 'ABSPATH', '/' );
$allowed = true;
$hooks = array();
function is_network_admin() { return false; }
function wp_doing_ajax() { return false; }
function current_user_can( $cap ) { global $allowed; return $allowed; }
function add_action( $hook, $callback, $priority = 10 ) { global $hooks; $hooks[] = $hook; }
function remove_action( ...$args ) {}
require dirname( __DIR__ ) . '/includes/admin/class-workspace-shell.php';
use PeptideCore\Admin\Workspace_Shell as Shell;
$checks = 0;
function verify( $value ) { global $checks; if ( ! $value ) { throw new RuntimeException( 'boundary_failed' ); } ++$checks; }
$_GET = array( 'pc_embed' => '1' );
$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document'; verify( ! Shell::embedded() );
$_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe'; verify( Shell::embedded() );
unset( $_SERVER['HTTP_SEC_FETCH_DEST'] ); verify( Shell::embedded() );
$_GET = array(); verify( ! Shell::embedded() );
$screen = new class { public $id = 'toplevel_page_pc-overview'; public function is_block_editor() { return false; } };
foreach ( array(
    array( 'GET', array( 'page' => 'pc-overview' ), true ),
    array( 'GET', array( 'page' => 'pc-data' ), true ),
    array( 'POST', array( 'page' => 'pc-overview' ), false ),
    array( 'GET', array( 'page' => 'pc-overview', '_wpnonce' => 'synthetic' ), false ),
    array( 'GET', array( 'page' => 'pc-design-editor' ), false ),
    array( 'GET', array( 'page' => 'wc-orders', 'action' => 'edit' ), false ),
) as [ $method, $query, $expected ] ) {
    $hooks = array(); $_GET = $query; $_SERVER['REQUEST_METHOD'] = $method;
    Shell::screen( $screen ); verify( in_array( 'load-' . $screen->id, $hooks, true ) === $expected );
}
$allowed = false; $hooks = array(); $_GET = array( 'page' => 'pc-overview' ); Shell::screen( $screen ); verify( ! $hooks );
echo json_encode( array( 'checks' => $checks, 'passed' => true, 'nativeWordPress' => false ) ) . PHP_EOL;
