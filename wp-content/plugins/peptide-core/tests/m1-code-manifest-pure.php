<?php
/**
 * M1-6：组件清单哈希。店铺端（PHP）和发布脚本（tools/deployment/manifest.py）必须算出同一个值。
 * 纯逻辑：php -n m1-code-manifest-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function add_action( ...$a ): void {}
	function apply_filters( string $hook, $value ) { return $value; }
	require dirname( __DIR__ ) . '/includes/class-hq-connector.php';
}
namespace {
	use PeptideCore\Hq_Connector;
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	$root = sys_get_temp_dir() . '/pc-m1-manifest-' . getmypid();
	$put  = static function ( string $rel, string $data ) use ( $root ): void { @mkdir( dirname( $root . '/' . $rel ), 0700, true ); file_put_contents( $root . '/' . $rel, $data ); };
	$wipe = static function ( string $dir ) use ( &$wipe ): void { foreach ( is_dir( $dir ) ? scandir( $dir ) : array() as $n ) { if ( '.' === $n || '..' === $n ) { continue; } is_dir( "$dir/$n" ) ? $wipe( "$dir/$n" ) : unlink( "$dir/$n" ); } @rmdir( $dir ); };
	$wipe( $root );
	$put( 'b.php', 'alpha' );
	$put( 'a.txt', "beta\n" );
	$put( 'sub/z.js', 'x' );
	$put( 'sub/tests/t.php', 'skip' );   // tests 目录不进发布包
	$put( 'Vendor/v.php', 'skip' );      // 目录名不分大小写
	$put( 'sub/中文.css', '中' );

	// 这个值是用 tools/deployment/manifest.py dir <同样的目录> 算出来的；两边算法改动时一起更新。
	$expected = '25fc4e6c2261511b5301f4a895bf0bac45e8f6aa1cbae41d7fe24d34fc0e0632';
	$m = Hq_Connector::manifest_hash( $root );
	check( 'four packaged files counted', 4 === $m['files'] );
	check( 'hash equals the Python implementation', $expected === $m['hash'] );
	check( 'trailing slash makes no difference', $m === Hq_Connector::manifest_hash( $root . '/' ) );

	$put( 'sub/tests/more.php', 'ignored' );
	check( 'files in skipped directories do not change the hash', $expected === Hq_Connector::manifest_hash( $root )['hash'] );
	$put( 'b.php', 'alphA' );
	check( 'one changed byte changes the hash', $expected !== Hq_Connector::manifest_hash( $root )['hash'] );
	$put( 'b.php', 'alpha' );
	check( 'restoring the file restores the hash', $expected === Hq_Connector::manifest_hash( $root )['hash'] );
	$put( 'b.php.bak', 'alpha' );
	check( 'a stray backup file is drift', $expected !== Hq_Connector::manifest_hash( $root )['hash'] && 5 === Hq_Connector::manifest_hash( $root )['files'] );
	unlink( $root . '/b.php.bak' );
	unlink( $root . '/a.txt' );
	check( 'a missing file is drift', $expected !== Hq_Connector::manifest_hash( $root )['hash'] );
	check( 'missing directory gives an empty result', array( 'files' => 0, 'hash' => '' ) === Hq_Connector::manifest_hash( $root . '/nope' ) );
	$wipe( $root );

	echo "m1-code-manifest-pure: {$checks} checks passed\n";
}
