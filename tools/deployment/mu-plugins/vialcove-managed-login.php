<?php
/** Chinese staff login presentation on an explicitly configured management host. */
defined( 'ABSPATH' ) || exit;

$vc_management_host = defined( 'PEPTIDE_CORE_MANAGEMENT_URL' ) ? parse_url( PEPTIDE_CORE_MANAGEMENT_URL, PHP_URL_HOST ) : '';
$vc_request_host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
$vc_request_path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
if ( ! $vc_management_host || strtolower( $vc_management_host ) !== $vc_request_host || '/wp-login.php' !== $vc_request_path ) {
	return;
}

add_filter( 'determine_locale', static fn(): string => 'zh_CN' );
add_filter( 'login_display_language_dropdown', '__return_false' );
add_filter( 'login_site_html_link', '__return_empty_string' );
add_filter( 'login_remember_me_help_text', '__return_empty_string' );
add_action( 'login_form', static function (): void {
	echo '<p class="vc-managed-login-hint">仅在私人设备上勾选“记住登录”。</p>';
} );
add_action( 'login_enqueue_scripts', static function (): void {
	wp_add_inline_style( 'login', 'body.login form .vc-managed-login-hint{margin:0 0 8px;color:#666;font-size:12px;line-height:20px}body.login form .forgetmenot{display:flex;align-items:center;min-height:44px;float:none;margin:0 0 12px}body.login .forgetmenot label{display:inline-flex;align-items:center;min-height:44px;padding-left:8px}body.login .button-primary{min-height:44px}body.login .wp-pwd .input{padding-right:48px}@media(max-width:480px){body.login #login{padding-top:24px}}' );
}, 20 );
