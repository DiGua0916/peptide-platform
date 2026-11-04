<?php
/** Permission, CSRF, validation and isolated-option save checks; no WordPress bootstrap. */
declare( strict_types=1 );
if ( PHP_SAPI !== 'cli' ) { exit( 2 ); }
define( 'ABSPATH', __DIR__ . '/' );
final class WP_Error {}
final class Language_Admin_Stop extends RuntimeException {}
$checks = 0;
$store = array( 'unrelated' => array( 'preserved' => true ) );
$writes = array();
$allowed = true;
$nonce_valid = true;
$nonce_calls = 0;
function check_language_admin( string $label, bool $result ): void {
	if ( ! $result ) { throw new RuntimeException( $label ); }
	++$GLOBALS['checks'];
}
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function current_user_can( $cap ) { check_language_admin( 'uses dedicated settings capability', 'pc_manage_settings' === $cap ); return $GLOBALS['allowed']; }
function wp_die( $message, $title, $args ) { check_language_admin( 'unauthorized is 403', 403 === $args['response'] ); throw new Language_Admin_Stop( 'forbidden' ); }
function check_admin_referer( $action ) { ++$GLOBALS['nonce_calls']; check_language_admin( 'dedicated nonce', 'pc_save_storefront_language' === $action ); if ( ! $GLOBALS['nonce_valid'] ) { throw new Language_Admin_Stop( 'csrf' ); } }
function wp_unslash( $value ) { return stripslashes( $value ); }
function admin_url( $value ) { return 'https://example.invalid/wp-admin/' . $value; }
function add_query_arg( $key, $value, $url ) { return $url . '&' . $key . '=' . $value; }
function wp_safe_redirect( $url ) { throw new Language_Admin_Stop( $url ); }
function get_option( $name, $default = false ) { return $GLOBALS['store'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload ) { $GLOBALS['writes'][] = array( $name, $autoload ); $GLOBALS['store'][ $name ] = $value; return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( ...$args ) {}
require_once dirname( __DIR__ ) . '/includes/class-storefront-language.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-storefront-language-page.php';
eval( 'namespace PeptideCore\\Admin; final class Store_Settings { public const SYSTEM_SLUG = "pc-system"; }' );
use PeptideCore\Admin\Storefront_Language_Page as Page;
use PeptideCore\Storefront_Language as Language;
function submit_language_admin( array $input ): string {
	$_POST = $input;
	try { Page::handle(); } catch ( Language_Admin_Stop $stop ) { return $stop->getMessage(); }
	throw new RuntimeException( 'Handler failed to redirect or stop.' );
}
$valid = array( 'enabled' => '1', 'default_language' => 'en', 'us_language' => 'en', 'pr_language' => 'es' );
$allowed = false;
check_language_admin( 'permission gate stops save', 'forbidden' === submit_language_admin( $valid ) );
check_language_admin( 'permission gate precedes nonce and write', 0 === $nonce_calls && array() === $writes );
$allowed = true; $nonce_valid = false;
check_language_admin( 'invalid nonce stops save', 'csrf' === submit_language_admin( $valid ) );
check_language_admin( 'CSRF cannot write', array() === $writes );
$nonce_valid = true;
foreach ( array(
	array( 'pr_language' => 'fr' ), array( 'us_language' => array( 'en' ) ),
	array( 'default_language' => '../es' ), array( 'enabled' => array( '1' ) ), array( 'enabled' => 'yes' ),
) as $bad ) {
	check_language_admin( 'malformed settings rejected', str_ends_with( submit_language_admin( array_replace( $valid, $bad ) ), 'language_notice=invalid' ) );
	check_language_admin( 'malformed settings cannot write', array() === $writes );
}
check_language_admin( 'valid settings save redirects', str_ends_with( submit_language_admin( $valid + array( 'WPLANG' => 'es_PR', 'support' => 'overwrite' ) ), 'language_notice=saved' ) );
check_language_admin( 'only dedicated option written without autoload', array( array( Language::OPTION, false ) ) === $writes );
check_language_admin( 'saved mapping', Language::get() === array( 'enabled' => true, 'default_language' => 'en', 'us_language' => 'en', 'pr_language' => 'es' ) );
check_language_admin( 'unrelated site settings preserved', array( 'preserved' => true ) === $store['unrelated'] && ! isset( $store['WPLANG'], $store['support'] ) );
$disabled = $valid; unset( $disabled['enabled'] );
check_language_admin( 'unchecked switch saves', str_ends_with( submit_language_admin( $disabled ), 'language_notice=saved' ) && false === Language::get()['enabled'] );
echo "PASS {$checks} storefront language admin checks; no WordPress, network, orders or customer data used.\n";
