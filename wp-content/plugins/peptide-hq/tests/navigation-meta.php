<?php
/** Actual Menu navigation policy/metadata with synthetic WordPress boundaries. No WP bootstrap, DB, session or HTTP. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'PEPTIDE_HQ_DIR', dirname( __DIR__ ) );
define( 'PEPTIDE_HQ_FILE', dirname( __DIR__ ) . '/peptide-hq.php' );
define( 'PEPTIDE_HQ_VERSION', 'synthetic' );
$GLOBALS['can'] = true; $GLOBALS['ajax'] = false; $GLOBALS['network'] = false; $GLOBALS['session'] = 'synthetic-session'; $GLOBALS['checks'] = array(); $GLOBALS['enqueued'] = array();
final class Synthetic_Screen { public function __construct( public string $id, public bool $editor = false ) {} public function is_block_editor(): bool { return $this->editor; } }
function current_user_can( $cap ): bool { return $GLOBALS['can']; }
function wp_doing_ajax(): bool { return $GLOBALS['ajax']; }
function is_network_admin(): bool { return $GLOBALS['network']; }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }
function admin_url( $path = '' ): string { return 'https://hq.example.invalid/wp-admin/' . $path; }
function wp_create_nonce( $action ): string { return $GLOBALS['session'] . ':' . $action; }
function wp_json_encode( $value ): string { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
function esc_attr( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function sanitize_html_class( $value ): string { return preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value ); }
function esc_url( $value ): string { return esc_attr( $value ); }
function plugins_url( $path, $file ): string { return 'https://hq.example.invalid/wp-content/plugins/peptide-hq/' . $path; }
function wp_enqueue_script( ...$args ): void { $GLOBALS['enqueued']['scripts'][] = $args; }
function wp_enqueue_style( ...$args ): void { $GLOBALS['enqueued']['styles'][] = $args; }
function wp_dequeue_script( ...$args ): void {}
function wp_dequeue_style( ...$args ): void {}
function check( string $name, bool $pass ): void { $GLOBALS['checks'][] = array( 'name' => $name, 'pass' => $pass ); if ( ! $pass ) { throw new RuntimeException( 'FAIL ' . $name ); } }
require dirname( __DIR__ ) . '/includes/admin/class-menu.php';
use PeptideHq\Admin\Menu;
function meta( string $page ): ?array {
	$_GET = array( 'page' => $page ); $GLOBALS['screen'] = new Synthetic_Screen( 'master-admin_page_' . $page );
	ob_start(); Menu::navigation_meta(); $html = ob_get_clean();
	if ( '' === $html ) { return null; }
	$doc = new DOMDocument(); $doc->loadHTML( '<html><head>' . $html . '</head><body></body></html>' ); $node = $doc->getElementsByTagName( 'meta' )->item( 0 );
	$out = array(); foreach ( $node->attributes as $attribute ) { $out[ $attribute->name ] = $attribute->value; } return $out;
}
$expected = array( 'pchq', 'pchq-sites', 'pchq-servers', 'pchq-accounts', 'pchq-alerts', 'pchq-site-recycle' );
$policy = Menu::navigation_policy();
check( 'six dedicated pages only', array_keys( $policy ) === $expected );
check( 'complex operations payments provisioning excluded', array() === array_intersect( array_keys( $policy ), array( 'pchq-operations', 'pchq-payments', 'pchq-create-store' ) ) );
check( 'dashboard range is finite known set', $policy['pchq']['range'] === array( 'today', 'yesterday', '7d', '30d' ) );
check( 'sales account form navigation uses positive IDs and explicit add flag', $policy['pchq-accounts'] === array( 'site' => 'positiveId', 'edit' => 'positiveId', 'add' => array( '1' ) ) );
check( 'no route contains mutation or nonce query parameters', ! array_filter( $policy, static fn( array $route ): bool => (bool) array_intersect( array_keys( $route ), array( 'action', '_wpnonce', 'nonce', 'token', 'ticket' ) ) ) );
foreach ( $expected as $page ) {
	$meta = meta( $page );
	check( $page . ' emits bounded workspace metadata', is_array( $meta ) && 'pchq-navigation' === $meta['name'] && 1 === preg_match( '/^[a-f0-9]{64}$/D', $meta['content'] ) );
	check( $page . ' preserves exact HQ base page screen and session', $meta['data-base'] === admin_url( 'admin.php' ) && $meta['data-page'] === $page && $meta['data-screen'] === 'master-admin_page_' . $page && $meta['data-session'] === 'synthetic-session:pchq_navigation_identity' );
	check( $page . ' emits only declared route policy', json_decode( $meta['data-policy'], true ) === $policy );
	$GLOBALS['enqueued'] = array(); Menu::enqueue( 'master-admin_page_' . $page );
	check( $page . ' includes navigation after shared workspace script', count( array_filter( $GLOBALS['enqueued']['scripts'] ?? array(), static fn( array $script ): bool => $script[0] === 'peptide-hq-navigation' && $script[2] === array( 'peptide-hq-workspace' ) ) ) === 1 );
}
foreach ( array( 'pchq-operations', 'pchq-payments', 'pchq-create-store', 'profile', 'unknown', '' ) as $page ) { check( $page . ' cannot emit navigation metadata', null === meta( $page ) ); }
$GLOBALS['can'] = false; check( 'nonadministrator has no navigation identity', null === meta( 'pchq-accounts' ) ); $GLOBALS['can'] = true;
$GLOBALS['ajax'] = true; check( 'AJAX has no navigation shell identity', null === meta( 'pchq' ) ); $GLOBALS['ajax'] = false;
$_GET = array( 'page' => 'pchq' ); $GLOBALS['screen'] = new Synthetic_Screen( 'master-admin_page_pchq', true ); ob_start(); Menu::navigation_meta(); check( 'native block editor never enhanced', '' === ob_get_clean() );
$GLOBALS['screen'] = null; ob_start(); Menu::navigation_meta(); check( 'missing screen fails closed', '' === ob_get_clean() );
$GLOBALS['session'] = 'different-synthetic-session'; $meta = meta( 'pchq' ); check( 'identity changes across synthetic sessions', $meta['data-session'] === 'different-synthetic-session:pchq_navigation_identity' );
$GLOBALS['hook_suffix'] = '%e6%80%bb%e5%90%8e%e5%8f%b0_page_pchq-accounts'; $meta = meta( 'pchq-accounts' );
check( 'encoded Chinese parent hook matches native admin-header body class instead of sanitized screen ID', $meta['data-screen'] === '-e6-80-bb-e5-90-8e-e5-8f-b0_page_pchq-accounts' ); unset( $GLOBALS['hook_suffix'] );
$report = array( 'complete' => true, 'checks' => count( $GLOBALS['checks'] ), 'modelOnly' => true, 'cases' => $GLOBALS['checks'], 'realWordPress' => false, 'realHttp' => false );
if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); }
echo 'PASS ', $report['checks'], " actual Menu navigation metadata checks; synthetic boundaries only.\n";
