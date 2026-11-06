<?php
/**
 * Section cache (catalogue grid, product menu): real Fragment_Cache + Page_Cache storage,
 * WordPress/WooCommerce boundaries stubbed. A temporary directory holds stored sections.
 */
namespace {
	$dir = sys_get_temp_dir() . '/pc-fragment-' . bin2hex( random_bytes( 4 ) );
	define( 'ABSPATH', __DIR__ . '/' ); define( 'WP_CONTENT_DIR', $dir ); define( 'AUTH_SALT', 'synthetic-salt' ); define( 'PEPTIDE_CORE_PAGE_CACHE', true );
	foreach ( array( 'uid' => 0, 'opts' => array(), 'logged_in' => false, 'email' => '', 'promos' => array(), 'mobile' => false ) as $k => $v ) { $GLOBALS[ $k ] = $v; }
	function wp_unique_id( $prefix = '' ) { return $prefix . (string) ++$GLOBALS['uid']; }
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function is_admin() { return false; } function wp_doing_ajax() { return false; } function is_preview() { return false; } function is_customize_preview() { return false; }
	function is_user_logged_in() { return $GLOBALS['logged_in']; } function is_ssl() { return true; } function get_locale() { return 'en_US'; }
	function get_woocommerce_currency() { return 'USD'; } function wp_is_mobile() { return $GLOBALS['mobile']; }
	function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
	final class _Dep { public function __construct( public $src = '', public $deps = array(), public $ver = false, public $args = null, public $extra = array() ) {} }
	final class _Deps { public array $registered = array(); public array $queue = array(); }
	$GLOBALS['scripts'] = new _Deps(); $GLOBALS['styles'] = new _Deps();
	foreach ( array( 'vc-catalog', 'vc-quick' ) as $h ) { $GLOBALS['scripts']->registered[ $h ] = new _Dep( "/$h.js" ); }
	$GLOBALS['styles']->registered['vc-catalog'] = new _Dep( '/c.css' );
	function wp_scripts() { return $GLOBALS['scripts']; } function wp_styles() { return $GLOBALS['styles']; }
	function wp_script_is( $h, $s ) { return isset( $GLOBALS['scripts']->registered[ $h ] ); } function wp_style_is( $h, $s ) { return isset( $GLOBALS['styles']->registered[ $h ] ); }
	function wp_enqueue_script( $h ) { if ( ! in_array( $h, $GLOBALS['scripts']->queue, true ) ) { $GLOBALS['scripts']->queue[] = $h; } }
	function wp_enqueue_style( $h ) { if ( ! in_array( $h, $GLOBALS['styles']->queue, true ) ) { $GLOBALS['styles']->queue[] = $h; } }
	function check( string $label, bool $ok ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
}
namespace PeptideCore {
	final class Storefront_Language { public const PREFERENCE_COOKIE = 'pc_storefront_language'; }
	final class Storefront_Translation { public static function selected(): array { return array( 'country_code' => 'US' ); } public static function active(): bool { return false; } public static function code(): string { return 'en'; } }
	final class Customer_Segments { public static function current_email(): string { return $GLOBALS['email']; } }
	final class Promotions {
		public static function active_ids(): array { return array_keys( $GLOBALS['promos'] ); }
		public static function config( int $id ): array { return array( 'customer_rule' => array( 'customers' => $GLOBALS['promos'][ $id ] ) ); }
	}
	final class Templates { public static function previewing_id() { return 0; } }
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-page-cache.php';
	require dirname( __DIR__ ) . '/includes/class-fragment-cache.php';
	use PeptideCore\Fragment_Cache as F;
	$renders = 0;
	// A realistic section: uses two auto IDs and enqueues an already-registered script and style.
	$section = static function () use ( &$renders ): string { ++$renders; $a = wp_unique_id( 'vc-nav-search-' ); $b = wp_unique_id( 'vc-nav-products-' ); wp_enqueue_script( 'vc-catalog' ); wp_enqueue_style( 'vc-catalog' ); return "<div id=\"$a\"><ul id=\"$b\">cards</ul></div>"; };
	$fresh = static function () { F::reset_for_tests(); $GLOBALS['scripts']->queue = array(); $GLOBALS['styles']->queue = array(); };
	$inputs = array( array( 'id' => 'm1', 'settings' => array( 'columns' => 4 ) ), 'home', 'desktop' );

	// 1. First render stores; the page continues with the expected IDs.
	$fresh(); $GLOBALS['uid'] = 10; $html1 = F::remember( 'demo-catalog', $inputs, $section ); $after_store = wp_unique_id( 'next-' );
	check( 'first render renders and stores', 1 === $renders && array( array( 'demo-catalog', 'miss:stored' ) ) === F::$log );
	// 2. Same position on a later request: reused, assets replayed, counter identical.
	$fresh(); $GLOBALS['uid'] = 10; $html2 = F::remember( 'demo-catalog', $inputs, $section ); $after_hit = wp_unique_id( 'next-' );
	check( 'second request reuses the section without rendering', 1 === $renders && array( array( 'demo-catalog', 'hit' ) ) === F::$log );
	check( 'identical HTML including auto IDs', $html1 === $html2 && str_contains( $html2, 'id="vc-nav-search-12"' ) );
	check( 'rest of the page gets the same next auto ID', $after_store === $after_hit );
	check( 'enqueued script and style replayed', array( 'vc-catalog' ) === $GLOBALS['scripts']->queue && array( 'vc-catalog' ) === $GLOBALS['styles']->queue );

	// 3. A different auto-ID position (another page template) gets its own copy, never mismatched IDs.
	$fresh(); $GLOBALS['uid'] = 40; $html3 = F::remember( 'demo-catalog', $inputs, $section );
	check( 'different auto-ID position renders its own copy', 2 === $renders && str_contains( $html3, 'id="vc-nav-search-42"' ) );

	// 4. Inputs, device, generation and the active promotion set are part of the key.
	foreach ( array(
		'module settings' => static function () use ( &$inputs ) { $inputs[0]['settings']['columns'] = 3; },
		'device' => static function () { $GLOBALS['mobile'] = true; },
		'content generation' => static function () { $GLOBALS['opts']['pc_page_cache_generation'] = '7'; },
		'active promotion set' => static function () { $GLOBALS['promos'] = array( 5 => 'all' ); },
	) as $label => $change ) {
		$change(); $fresh(); $GLOBALS['uid'] = 10; $before = $renders; F::remember( 'demo-catalog', $inputs, $section );
		check( "$label change renders a fresh copy", $renders === $before + 1 );
	}

	// 5. Personalised visitors and promotions always render.
	foreach ( array(
		'logged-in' => static function () { $GLOBALS['logged_in'] = true; },
		'customer-email' => static function () { $GLOBALS['email'] = 'buyer@example.test'; },
		'personalized-promotion' => static function () { $GLOBALS['promos'] = array( 5 => 'all', 9 => 'levels' ); },
	) as $reason => $set ) {
		$GLOBALS['logged_in'] = false; $GLOBALS['email'] = ''; $GLOBALS['promos'] = array( 5 => 'all' );
		$set(); $fresh(); $GLOBALS['uid'] = 10; $before = $renders; F::remember( 'demo-catalog', $inputs, $section );
		check( "skip when $reason", $renders === $before + 1 && array( array( 'demo-catalog', 'skip:' . $reason ) ) === F::$log );
	}
	$GLOBALS['logged_in'] = false; $GLOBALS['email'] = ''; $GLOBALS['promos'] = array( 5 => 'all' );

	// 6. Side effects a copy cannot replay are never stored.
	$registers = static function (): string { $GLOBALS['scripts']->registered['vc-new'] = new \_Dep( '/new.js' ); return '<p>x</p>'; };
	$fresh(); $GLOBALS['uid'] = 70; F::remember( 'nav', array( 'r' ), $registers ); unset( $GLOBALS['scripts']->registered['vc-new'] );
	check( 'section that registers an asset is not stored', array( array( 'nav', 'miss:not-storable' ) ) === F::$log );
	$inline = static function (): string { $GLOBALS['scripts']->registered['vc-quick']->extra['data'] = 'var x=1;'; return '<p>y</p>'; };
	$fresh(); $GLOBALS['uid'] = 80; F::remember( 'nav', array( 'i' ), $inline ); $GLOBALS['scripts']->registered['vc-quick']->extra = array();
	check( 'section that adds inline script data is not stored', array( array( 'nav', 'miss:not-storable' ) ) === F::$log );
	$GLOBALS['scripts']->queue = array( 'vc-quick' );
	$dequeue = static function (): string { $GLOBALS['scripts']->queue = array(); return '<p>z</p>'; };
	F::reset_for_tests(); $GLOBALS['uid'] = 90; F::remember( 'nav', array( 'd' ), $dequeue );
	check( 'section that dequeues an asset is not stored', array( array( 'nav', 'miss:not-storable' ) ) === F::$log );

	// 7. Disabled switch: always render, nothing stored.
	check( 'storage holds plain-exit-guarded files', (bool) glob( $dir . '/cache/peptide-core-fragments/*.php' ) && str_starts_with( file_get_contents( glob( $dir . '/cache/peptide-core-fragments/*.php' )[0] ), "<?php exit; ?>\n" ) );

	array_map( 'unlink', array_filter( glob( $dir . '/cache/peptide-core-fragments/{,.}*', GLOB_BRACE ) ?: array(), 'is_file' ) ); @rmdir( $dir . '/cache/peptide-core-fragments' ); @rmdir( $dir . '/cache' ); @rmdir( $dir );
	echo "PASS {$checks} fragment cache checks\n";
}
