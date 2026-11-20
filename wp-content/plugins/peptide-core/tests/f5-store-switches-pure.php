<?php
/**
 * 前台 5 个按店开关放进店铺后台“插件”页（Admin\Storefront_Switches，F5 2026-11-16）的纯逻辑用例，不加载 WordPress。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/f5-store-switches-pure.php
 */

namespace PeptideCore {
	final class Page_Cache {
		public static int $flushes = 0;
		public static function flush(): void { ++self::$flushes; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
	$GLOBALS['opts']   = array();
	$GLOBALS['writes'] = array();
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $name ] : $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['opts'][ $name ] = $value; $GLOBALS['writes'][] = "update $name=$value"; return true; }
	function delete_option( $name ) { $had = array_key_exists( $name, $GLOBALS['opts'] ); unset( $GLOBALS['opts'][ $name ] ); $GLOBALS['writes'][] = "delete $name"; return $had; }

	$root = dirname( __DIR__ );
	require $root . '/includes/admin/class-storefront-switches.php';
	use PeptideCore\Admin\Storefront_Switches as S;
	use PeptideCore\Page_Cache;

	$reset = static function ( array $opts = array() ): void { $GLOBALS['opts'] = $opts; $GLOBALS['writes'] = array(); Page_Cache::$flushes = 0; };

	// ---------- 放哪 5 个开关、各读哪个选项 ----------
	$defs = S::definitions();
	check( 'exactly the five storefront switches agreed with F1', array( 'trust-badges', 'front-speed', 'product-activity', 'entry-address', 'returning-buyer' ) === array_keys( $defs ) );
	check( 'option names are the ones the features read', array( 'pc_trust_badges', 'pc_front_speed', 'pc_product_activity', 'pc_entry_address', 'pc_buyer_recognition' ) === array_column( $defs, 'option' ) );
	check( 'buyer back-office switches are not on the owner page', ! in_array( 'pc_buyer_no_palette', array_column( $defs, 'option' ), true ) && ! in_array( 'pc_buyer_extras', array_column( $defs, 'option' ), true ) );
	$theme = dirname( $root, 2 ) . '/themes/peptide-site/inc/';
	foreach ( array( 'product-trust.php' => 'pc_trust_badges', 'front-speed.php' => 'pc_front_speed', 'product-activity.php' => 'pc_product_activity', 'entry-address.php' => 'pc_entry_address' ) as $file => $option ) {
		check( "the theme still reads $option as 'on'", false !== strpos( (string) file_get_contents( $theme . $file ), "'on' === (string) get_option( '$option', 'off' )" ) );
	}
	check( 'the theme minimum reads the same option', false !== strpos( (string) file_get_contents( $theme . 'product-activity.php' ), "get_option( 'pc_product_activity_min', 5 )" ) );
	$buyer_status = (string) file_get_contents( $root . '/includes/class-buyer-status.php' );
	check( 'returning-buyer recognition still reads pc_buyer_recognition as on', false !== strpos( $buyer_status, "public const OPTION      = 'pc_buyer_recognition';" ) && false !== strpos( $buyer_status, "'on' === (string) get_option( self::OPTION, 'off' )" ) );
	foreach ( $defs as $key => $def ) {
		check( "$key has a name, a description and an icon", '' !== $def['name'] && '' !== $def['text'] && str_starts_with( $def['icon'], 'dashicons-' ) );
	}

	// ---------- 读：只有 on 算开 ----------
	check( 'only the value on is on', S::is_on( 'on' ) && ! S::is_on( 'off' ) && ! S::is_on( '1' ) && ! S::is_on( 'yes' ) && ! S::is_on( '' ) && ! S::is_on( false ) );
	$reset( array( 'pc_trust_badges' => 'on', 'pc_buyer_recognition' => 'off' ) );
	$cat = S::catalog();
	check( 'cards carry the same four fields as the other apps', array( 'name', 'text', 'icon', 'on' ) === array_keys( $cat['trust-badges'] ) );
	check( 'cards show the current state of each option', true === $cat['trust-badges']['on'] && false === $cat['returning-buyer']['on'] && false === $cat['front-speed']['on'] );
	check( 'reading the cards writes nothing and clears nothing', array() === $GLOBALS['writes'] && 0 === Page_Cache::$flushes );

	// ---------- 写：开 = on，关 = 删除，不变不写；改了就清缓存 ----------
	$reset();
	check( 'turning on succeeds', true === S::set( 'front-speed', true ) );
	check( 'turning on writes on and clears the page cache once', array( 'update pc_front_speed=on' ) === $GLOBALS['writes'] && 1 === Page_Cache::$flushes );
	$reset( array( 'pc_front_speed' => 'on' ) );
	S::set( 'front-speed', true );
	check( 'no change, no write, no flush', array() === $GLOBALS['writes'] && 0 === Page_Cache::$flushes );
	S::set( 'front-speed', false );
	check( 'turning off deletes the option (as wp option delete) and clears the cache', array( 'delete pc_front_speed' ) === $GLOBALS['writes'] && 1 === Page_Cache::$flushes && ! array_key_exists( 'pc_front_speed', $GLOBALS['opts'] ) );
	$reset( array( 'pc_entry_address' => 'off' ) );
	S::set( 'entry-address', false );
	check( 'an option already set to off counts as off: nothing to do', array() === $GLOBALS['writes'] );
	$reset();
	check( 'unknown keys are refused', S::set( 'exit-intent', true ) instanceof WP_Error && S::set( 'pc_trust_badges', true ) instanceof WP_Error && array() === $GLOBALS['writes'] );
	check( 'only the five keys are handled here', S::handles( 'trust-badges' ) && ! S::handles( 'exit-intent' ) && ! S::handles( '' ) );

	// ---------- 人数下限：1–1000 的整数 ----------
	check( 'minimum accepts whole numbers 1–1000', 5 === S::normalize_min( '5' ) && 12 === S::normalize_min( ' 12 ' ) && 1 === S::normalize_min( '1' ) && 1000 === S::normalize_min( 1000 ) );
	check( 'minimum refuses anything else', null === S::normalize_min( '0' ) && null === S::normalize_min( '1001' ) && null === S::normalize_min( 'abc' ) && null === S::normalize_min( '1.5' ) && null === S::normalize_min( '-3' ) && null === S::normalize_min( '' ) && null === S::normalize_min( array( '5' ) ) );

	// ---------- 保存入口：先权限、再 nonce、再看总后台开没开通，然后才写 ----------
	$src  = (string) file_get_contents( $root . '/includes/admin/class-storefront-switches.php' );
	$save = substr( $src, strpos( $src, 'public static function save()' ), strpos( $src, 'public static function render(' ) - strpos( $src, 'public static function save()' ) );
	$at   = static fn( string $needle ): int => false === strpos( $save, $needle ) ? PHP_INT_MAX : strpos( $save, $needle );
	check( 'save checks the owner capability first', $at( 'self::access();' ) < $at( 'check_admin_referer(' ) );
	check( 'save accepts POST only', $at( "'POST' !== ( \$_SERVER['REQUEST_METHOD'] ?? '' )" ) < $at( 'self::set(' ) );
	check( 'save checks a per-switch nonce before writing', false !== strpos( $save, "check_admin_referer( self::ACTION . '_' . \$key );" ) && $at( 'check_admin_referer(' ) < $at( 'self::set(' ) );
	check( 'save honours the master admin blocking an app', $at( 'Apps_Page::require_allowed( $key );' ) < $at( 'self::set(' ) );
	check( 'a bad minimum stops the whole save before anything is written', $at( "'saved' => 'min'" ) < $at( 'self::set(' ) );
	check( 'the capability is the same as the other apps', 'manage_woocommerce' === S::CAP );
	$render = substr( $src, strpos( $src, 'public static function render(' ) );
	check( 'the settings page checks access and app access before drawing', strpos( $render, 'self::access();' ) < strpos( $render, '<form' ) && strpos( $render, 'Apps_Page::require_allowed( $key );' ) < strpos( $render, '<form' ) );

	// ---------- 接线：插件页、总后台停用、启动、投手 ----------
	$apps = (string) file_get_contents( $root . '/includes/admin/class-apps-page.php' );
	check( 'the apps page lists the five cards after the existing apps', false !== strpos( $apps, ') + Storefront_Switches::catalog();' ) );
	check( 'the apps page opens a switch page for these keys', false !== strpos( $apps, "if ( Storefront_Switches::handles( \$requested ) ) {\n\t\t\tStorefront_Switches::render( \$requested );" ) );
	$switch = (string) file_get_contents( $root . '/includes/class-app-switch.php' );
	check( 'blocking from the master admin turns these off through the same setter', false !== strpos( $switch, 'return Admin\\Storefront_Switches::set( $key, $on );' ) );
	check( 'the save handler is registered with the other app pages', false !== strpos( (string) file_get_contents( $root . '/includes/class-plugin.php' ), "Admin\\Apps_Page::register_hooks();\n\t\t\tAdmin\\Storefront_Switches::register_hooks();" ) );
	$staff = (string) file_get_contents( $root . '/includes/class-staff-buyer.php' );
	preg_match( '/private const POST = array\(([^;]*)\);/', $staff, $m );
	check( 'media buyers cannot submit the switch form (not in their admin-post list)', isset( $m[1] ) && false === strpos( $m[1], 'pc_app_switch_save' ) && false === strpos( $m[1], 'Storefront_Switches' ) );

	echo "PASS {$checks} storefront switch checks\n";
}
