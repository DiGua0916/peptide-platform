<?php
/** Actual Menu navigation policy/metadata with synthetic WordPress boundaries. No WP bootstrap, DB, session or HTTP. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'PEPTIDE_HQ_DIR', dirname( __DIR__ ) );
define( 'PEPTIDE_HQ_FILE', dirname( __DIR__ ) . '/peptide-hq.php' );
define( 'PEPTIDE_HQ_VERSION', 'synthetic' );
define( 'PEPTIDE_PANAPAY_DIR', dirname( __DIR__, 2 ) . '/peptide-panapay' );
define( 'PEPTIDE_PANAPAY_FILE', PEPTIDE_PANAPAY_DIR . '/peptide-panapay.php' );
define( 'PEPTIDE_PANAPAY_VERSION', 'synthetic' );
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
function plugins_url( $path, $file ): string { return 'https://hq.example.invalid/wp-content/plugins/' . basename( dirname( $file ) ) . '/' . $path; }
function wp_enqueue_script( ...$args ): void { $GLOBALS['enqueued']['scripts'][] = $args; }
function wp_enqueue_style( ...$args ): void { $GLOBALS['enqueued']['styles'][] = $args; }
function wp_dequeue_script( ...$args ): void {}
function wp_dequeue_style( ...$args ): void {}
function check( string $name, bool $pass ): void { $GLOBALS['checks'][] = array( 'name' => $name, 'pass' => $pass ); if ( ! $pass ) { throw new RuntimeException( 'FAIL ' . $name ); } }
require dirname( __DIR__ ) . '/includes/admin/class-menu.php';
require dirname( __DIR__ ) . '/includes/admin/class-operations-page.php';
require dirname( __DIR__ ) . '/includes/admin/class-provision-page.php';
require PEPTIDE_PANAPAY_DIR . '/includes/class-hub-page.php';
use PeptideHq\Admin\Menu;
function meta( string $page ): ?array {
	$_GET = array( 'page' => $page ); $GLOBALS['screen'] = new Synthetic_Screen( 'master-admin_page_' . $page );
	ob_start(); Menu::navigation_meta(); $html = ob_get_clean();
	if ( '' === $html ) { return null; }
	$doc = new DOMDocument(); $doc->loadHTML( '<html><head>' . $html . '</head><body></body></html>' ); $node = $doc->getElementsByTagName( 'meta' )->item( 0 );
	$out = array(); foreach ( $node->attributes as $attribute ) { $out[ $attribute->name ] = $attribute->value; } return $out;
}
$expected = array( 'pchq', 'pchq-sites', 'pchq-servers', 'pchq-accounts', 'pchq-alerts', 'pchq-site-recycle', 'pchq-operations', 'pchq-payments', 'pchq-create-store' );
$policy = Menu::navigation_policy();
$keys = array_keys( $policy ); sort( $keys ); $sorted_expected = $expected; sort( $sorted_expected );
check( 'nine dedicated pages only', $keys === $sorted_expected );
check( 'operations exposes only known read tabs and signed site selectors', $policy['pchq-operations'] === array( 'tab' => array( 'tasks', 'releases', 'create', 'backups', 'agents' ), 'site' => 'signedId' ) );
check( 'provision page accepts only a typed UUID job selector', $policy['pchq-create-store'] === array( 'job' => 'uuid4' ) );
check( 'payment filter and editor queries have bounded semantic types', $policy['pchq-payments'] === array( 'site_id' => 'nonnegativeId', 'state' => array( '', 'creating', 'created', 'unknown', 'failed', 'succeeded', 'confirmed_failed', 'pending', 'delivered', 'review' ), 'paged' => 'pageNumber', 'deleted' => array( '1' ), 'edit_account' => 'accountId', 'new_account' => array( '1' ), 'account_error' => array( '1' ) ) );
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
	$script_handles = array_column( $GLOBALS['enqueued']['scripts'] ?? array(), 0 );
	check( $page . ' loads advanced page controllers once from every initial entry', count( array_intersect( $script_handles, array( 'pchq-operations', 'pchq-provision', 'pc-panapay-hub' ) ) ) === 3 && count( $script_handles ) === count( array_unique( $script_handles ) ) );
	$style_handles = array_column( $GLOBALS['enqueued']['styles'] ?? array(), 0 );
	check( $page . ' preloads first-party operations and payment styles', count( array_intersect( $style_handles, array( 'pchq-operations', 'pc-panapay-hub' ) ) ) === 2 );
}
foreach ( array( 'profile', 'unknown', '', 'pc-overview', 'pchq-login' ) as $page ) { check( $page . ' cannot emit navigation metadata', null === meta( $page ) ); }
$GLOBALS['can'] = false; check( 'nonadministrator has no navigation identity', null === meta( 'pchq-accounts' ) ); $GLOBALS['can'] = true;
$GLOBALS['ajax'] = true; check( 'AJAX has no navigation shell identity', null === meta( 'pchq' ) ); $GLOBALS['ajax'] = false;
$_GET = array( 'page' => 'pchq' ); $GLOBALS['screen'] = new Synthetic_Screen( 'master-admin_page_pchq', true ); ob_start(); Menu::navigation_meta(); check( 'native block editor never enhanced', '' === ob_get_clean() );
$GLOBALS['screen'] = null; ob_start(); Menu::navigation_meta(); check( 'missing screen fails closed', '' === ob_get_clean() );
$GLOBALS['session'] = 'different-synthetic-session'; $meta = meta( 'pchq' ); check( 'identity changes across synthetic sessions', $meta['data-session'] === 'different-synthetic-session:pchq_navigation_identity' );
$GLOBALS['hook_suffix'] = '%e6%80%bb%e5%90%8e%e5%8f%b0_page_pchq-accounts'; $meta = meta( 'pchq-accounts' );
check( 'encoded Chinese parent hook matches native admin-header body class instead of sanitized screen ID', $meta['data-screen'] === '-e6-80-bb-e5-90-8e-e5-8f-b0_page_pchq-accounts' ); unset( $GLOBALS['hook_suffix'] );
// Native WordPress screens keep a distinct cross-document continuity path, not fake PJAX metadata.
$_GET = array(); $GLOBALS['screen'] = new Synthetic_Screen( 'users' );
ob_start(); Menu::navigation_continuity(); $continuity = ob_get_clean();
check( 'native WP head uses the existing canvas before external styles', str_contains( $continuity, 'html,body{background:#f5f5f5}' ) );
check( 'native WP head opts in and respects reduced motion', str_contains( $continuity, '@view-transition{navigation:auto}' ) && str_contains( $continuity, '@media(prefers-reduced-motion:reduce){@view-transition{navigation:none}}' ) );
check( 'native WP head waits for parsed content only', str_contains( $continuity, '<link rel="expect" href="#pchq-document-ready" blocking="render">' ) );
ob_start(); Menu::navigation_continuity(); check( 'duplicate head callbacks do not emit another expectation', '' === ob_get_clean() );
ob_start(); Menu::navigation_ready(); $ready = ob_get_clean();
check( 'native WP footer emits the matching hidden ready marker', '<span id="pchq-document-ready" hidden aria-hidden="true"></span>' === $ready );
ob_start(); Menu::navigation_ready(); check( 'duplicate footer callbacks do not emit duplicate marker IDs', '' === ob_get_clean() );
ob_start(); Menu::navigation_continuity(); ob_end_clean(); $GLOBALS['can'] = false;
ob_start(); Menu::navigation_ready(); check( 'late eligibility change still releases an already emitted expectation', str_contains( ob_get_clean(), 'id="pchq-document-ready"' ) ); $GLOBALS['can'] = true;
foreach ( array( 'navigation_continuity', 'navigation_ready' ) as $method ) {
	$GLOBALS['can'] = false; ob_start(); Menu::$method(); check( $method . ' is absent without management capability', '' === ob_get_clean() ); $GLOBALS['can'] = true;
	$GLOBALS['ajax'] = true; ob_start(); Menu::$method(); check( $method . ' is absent from AJAX', '' === ob_get_clean() ); $GLOBALS['ajax'] = false;
}
$report = array( 'complete' => true, 'checks' => count( $GLOBALS['checks'] ), 'modelOnly' => true, 'cases' => $GLOBALS['checks'], 'policy' => $policy, 'realWordPress' => false, 'realHttp' => false );
if ( isset( $argv[1] ) ) { file_put_contents( $argv[1], json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); }
echo 'PASS ', $report['checks'], " actual Menu navigation metadata checks; synthetic boundaries only.\n";
