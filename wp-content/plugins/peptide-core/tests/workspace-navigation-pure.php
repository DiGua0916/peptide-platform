<?php
/** Pure Workspace navigation output/guard checks; no WordPress bootstrap or database. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/synthetic-no-wordpress/' );
$GLOBALS['pc_nav_enabled'] = true;
$GLOBALS['pc_nav_network'] = false;
$GLOBALS['pc_nav_hooks']   = array();
function apply_filters( $hook, $value ) { return 'peptide_admin_workspace_enabled' === $hook ? $GLOBALS['pc_nav_enabled'] : $value; }
function is_network_admin() { return $GLOBALS['pc_nav_network']; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['pc_nav_hooks'][ $hook ][] = array( $callback[1], $priority ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function wp_get_inline_script_tag( $script, $attributes = array() ) {
	$GLOBALS['pc_nav_inline_script_calls'][] = $attributes;
	return '<script id="' . htmlspecialchars( $attributes['id'], ENT_QUOTES ) . '" nonce="synthetic-csp-nonce">' . $script . '</script>';
}

require dirname( __DIR__ ) . '/includes/admin/class-workspace.php';
use PeptideCore\Admin\Workspace;

$checks = 0;
$check  = static function ( bool $condition, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $label ); }
};
$capture = static function ( string $method ): string {
	ob_start();
	Workspace::$method();
	return (string) ob_get_clean();
};

if ( in_array( '--emit-react-script', $argv ?? array(), true ) ) {
	$_GET['page'] = 'wc-admin';
	$capture( 'navigation_head' );
	$html = $capture( 'navigation_ready' );
	preg_match( '~<script[^>]*>([\s\S]*?)</script>~', $html, $match );
	echo json_encode( array( 'script' => $match[1] ?? '', 'html' => $html ) ), "\n";
	exit;
}

Workspace::register_hooks();
$check( in_array( array( 'navigation_head', 0 ), $GLOBALS['pc_nav_hooks']['admin_print_styles'], true ), 'Head emits before ordinary admin styles/scripts' );
$check( in_array( array( 'navigation_ready', 0 ), $GLOBALS['pc_nav_hooks']['admin_footer'], true ), 'Content parsing marker precedes footer scripts' );
$check( '' === $capture( 'navigation_ready' ), 'No marker without an emitted expectation' );

$head = $capture( 'navigation_head' );
$check( str_contains( $head, 'html,body{background:#f7f7f7}' ), 'Early canvas matches customer workspace' );
$check( str_contains( $head, '@view-transition{navigation:auto}' ), 'Native same-origin MPA opt-in' );
$check( str_contains( $head, '@media(prefers-reduced-motion:reduce){@view-transition{navigation:none}}' ), 'Reduced motion opts out without a simulated animation' );
$check( str_contains( $head, '<link rel="expect" href="#pc-workspace-content-ready" blocking="render">' ), 'Only a same-document parsed-content expectation' );
$check( ! str_contains( $head, '<script' ) && ! preg_match( '/https?:|prefetch|prerender|iframe|localStorage|sessionStorage|eval\(/i', $head ), 'No executable navigation, storage, prefetch or remote resource' );
$check( '' === $capture( 'navigation_head' ), 'Duplicate head callbacks cannot duplicate expectations' );
$ready = $capture( 'navigation_ready' );
$check( '<span id="pc-workspace-content-ready" hidden aria-hidden="true"></span>' === $ready, 'Parsed-content marker is hidden and matches the expectation' );
$check( '' === $capture( 'navigation_ready' ), 'Footer callback is idempotent' );

$GLOBALS['pc_nav_enabled'] = false;
$check( '' === $capture( 'navigation_head' ) && '' === $capture( 'navigation_ready' ), 'Workspace opt-out emits neither head nor marker' );
$GLOBALS['pc_nav_enabled'] = true;
$GLOBALS['pc_nav_network'] = true;
$check( '' === $capture( 'navigation_head' ) && '' === $capture( 'navigation_ready' ), 'Network admin remains native without opt-in' );
$GLOBALS['pc_nav_network'] = false;
$head = $capture( 'navigation_head' );
$GLOBALS['pc_nav_enabled'] = false;
$check( '' !== $head && '' !== $capture( 'navigation_ready' ), 'Already-emitted expectation releases even if a late filter changes' );
$GLOBALS['pc_nav_enabled'] = true;

$css = file_get_contents( dirname( __DIR__ ) . '/assets/admin-workspace.css' );
foreach ( array( 'toolbar', 'sidebar', 'header' ) as $part ) {
	$check( 1 === substr_count( $css, 'view-transition-name:pc-workspace-' . $part ), 'Stable shell name is unique: ' . $part );
}
$check( str_contains( $css, '::view-transition-group(root)' ) && str_contains( $css, '::view-transition-old(root),::view-transition-new(root)' ), 'Root participates without being faded out' );
$nav_css = substr( $css, strpos( $css, '/* Native document continuity:' ), strpos( $css, '.pc-admin-workspace :is(input,textarea,select,button)' ) - strpos( $css, '/* Native document continuity:' ) );
$check( 2 === substr_count( $nav_css, 'animation:none' ) && ! preg_match( '/opacity|translate|scale|duration|delay|keyframes/i', $nav_css ), 'No root fade, slide, timing delay or geometry animation' );

$_GET['page'] = 'wc-admin';
$check( '' === $capture( 'navigation_ready' ), 'Woo helper cannot render without paired head expectation' );
$capture( 'navigation_head' );
$ready = $capture( 'navigation_ready' );
$check( str_contains( $ready, 'nonce="synthetic-csp-nonce"' ) && str_contains( $ready, 'id="pc-workspace-react-readiness"' ), 'Woo helper uses nonce-filterable core inline script helper' );
$check( str_ends_with( $ready, '<span id="pc-workspace-content-ready" hidden aria-hidden="true"></span>' ), 'CSP-blocked helper still has server-rendered ordinary marker' );
$check( str_contains( $ready, 'window.setTimeout(finish, 700)' ) && str_contains( $ready, '#wpbody-content > .wrap > #root' ), 'Bounded gate uses the exact Woo root' );
$check( ! preg_match( '/fetch\(|XMLHttpRequest|history\.|localStorage|sessionStorage|eval\(|innerHTML|createRoot\(/', $ready ), 'Readiness helper cannot fetch, route, store HTML or replay React' );
$_GET['page'] = 'wc-orders';
$capture( 'navigation_head' );
$check( ! str_contains( $capture( 'navigation_ready' ), '<script' ), 'SSR Woo order page keeps immediate marker without helper' );
$_GET['page'] = array( 'wc-admin' );
$capture( 'navigation_head' );
$check( ! str_contains( $capture( 'navigation_ready' ), '<script' ), 'Malformed page query cannot enable Woo helper' );
unset( $_GET['page'] );

define( 'IFRAME_REQUEST', true );
$check( '' === $capture( 'navigation_head' ) && '' === $capture( 'navigation_ready' ), 'Native media/editor iframe requests are excluded' );
echo json_encode( array( 'status' => 'passed', 'checks' => $checks, 'wordpress_bootstrap' => false, 'database' => false, 'rendered_browser_verification' => false ), JSON_PRETTY_PRINT ), "\n";
