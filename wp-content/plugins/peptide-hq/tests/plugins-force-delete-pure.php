<?php
/**
 * 插件库一键强制删除：还装在店铺上时先排队卸载，卸干净后才真的删。
 * 纯逻辑（内存里的表 + 临时队列目录）：php -n -d extension=mbstring -d extension=json plugins-force-delete-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function add_action( ...$a ): void {}
	$GLOBALS['pc_options'] = array();
	function get_option( string $k, $d = false ) { return $GLOBALS['pc_options'][ $k ] ?? $d; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['pc_options'][ $k ] = $v; return true; }
	function delete_option( string $k ): bool { unset( $GLOBALS['pc_options'][ $k ] ); return true; }
	function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
	function wp_generate_uuid4(): string { return sprintf( '%08x-%04x-%04x-%04x-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffffffffffff ) ); }
	function get_current_user_id(): int { return 1; }
	function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }

	/** 只认这几条本测试用到的语句。 */
	final class Memory_Plugins_Db {
		public string $prefix = 'wp_';
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $t = array( 'wp_pchq_plugins' => array(), 'wp_pchq_site_plugins' => array() );
		public int $seq = 0;
		public function prepare( string $sql, ...$a ): string {
			return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$a ) { $v = array_shift( $a ); return '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'"; }, $sql );
		}
		private function table( string $sql ): string { return preg_match( '/FROM (\w+)/', $sql, $m ) ? $m[1] : ''; }
		private function match( string $sql ): array {
			$rows = $this->t[ $this->table( $sql ) ] ?? array();
			if ( preg_match( "/slug = '([^']*)'/", $sql, $m ) ) { $rows = array_filter( $rows, static fn( $r ) => $r['slug'] === $m[1] ); }
			if ( preg_match( '/site_id = (\d+)/', $sql, $m ) ) { $rows = array_filter( $rows, static fn( $r ) => (int) $r['site_id'] === (int) $m[1] ); }
			if ( str_contains( $sql, "state <> 'removed'" ) ) { $rows = array_filter( $rows, static fn( $r ) => 'removed' !== $r['state'] ); }
			return array_values( $rows );
		}
		public function get_results( string $sql, $fmt = null ): array { return $this->match( $sql ); }
		public function get_var( string $sql ) { return str_contains( $sql, 'COUNT(*)' ) ? count( $this->match( $sql ) ) : ( $this->match( $sql )[0] ?? null ); }
		public function get_col( string $sql ): array { preg_match( '/SELECT (\w+) FROM/', $sql, $m ); return array_column( $this->match( $sql ), $m[1] ); }
		public function get_row( string $sql, $fmt = null ) { $r = $this->match( $sql ); if ( str_contains( $sql, 'ORDER BY id DESC' ) ) { usort( $r, static fn( $a, $b ) => $b['id'] <=> $a['id'] ); } return $r[0] ?? null; }
		public function insert( string $t, array $row ): void { $row['id'] = ++$this->seq; $this->t[ $t ][ $row['id'] ] = $row; }
		public function update( string $t, array $f, array $w ): int { foreach ( $this->t[ $t ] as $id => $r ) { $ok = true; foreach ( $w as $k => $v ) { $ok = $ok && (string) $r[ $k ] === (string) $v; } if ( $ok ) { $this->t[ $t ][ $id ] = array_replace( $r, $f ); return 1; } } return 0; }
		public function delete( string $t, array $w ): int { $n = 0; foreach ( $this->t[ $t ] as $id => $r ) { $ok = true; foreach ( $w as $k => $v ) { $ok = $ok && (string) $r[ $k ] === (string) $v; } if ( $ok ) { unset( $this->t[ $t ][ $id ] ); ++$n; } } return $n; }
	}
	$GLOBALS['wpdb'] = new Memory_Plugins_Db();

	$queue = sys_get_temp_dir() . '/pchq-force-delete-' . getmypid();
	@mkdir( $queue . '/plugins/requests', 0700, true );
	@mkdir( $queue . '/plugins/packages', 0700, true );
	define( 'PEPTIDE_HQ_PROVISION_QUEUE', $queue );
}

namespace PeptideHq {
	// 替身：只提供 Store_Plugins 用到的那几个方法。
	final class Db { public static function table( string $n ): string { return 'wp_pchq_' . $n; } }
	final class Sites {
		/** 店铺 1、2 正常；3 已停用（Site_Lifecycle 说了不算活跃）；4 根本不存在。 */
		public static function get( int $id ): ?array {
			$map = array(
				1 => array( 'id' => 1, 'uuid' => '11111111-1111-1111-1111-111111111111', 'status' => 'live' ),
				2 => array( 'id' => 2, 'uuid' => '22222222-2222-2222-2222-222222222222', 'status' => 'live' ),
				3 => array( 'id' => 3, 'uuid' => '33333333-3333-3333-3333-333333333333', 'status' => 'retired' ),
			);
			return $map[ $id ] ?? null;
		}
	}
	final class Site_Lifecycle { public static function active( int $id ): bool { return $id < 3; } }
}

namespace {
	require dirname( __DIR__ ) . '/includes/class-store-plugins.php';
}

namespace {
	use PeptideHq\Store_Plugins;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
	function requests(): array { return glob( PEPTIDE_HQ_PROVISION_QUEUE . '/plugins/requests/*.json' ) ?: array(); }
	function seed(): void {
		$GLOBALS['wpdb']->t = array( 'wp_pchq_plugins' => array(), 'wp_pchq_site_plugins' => array() );
		$GLOBALS['wpdb']->seq = 0;
		$GLOBALS['pc_options'] = array();
		foreach ( requests() as $f ) { unlink( $f ); }
		$GLOBALS['wpdb']->insert( 'wp_pchq_plugins', array( 'slug' => 'demo-plugin', 'name' => 'Demo', 'version' => '1.0', 'sha256' => str_repeat( 'a', 64 ), 'size' => 10, 'auto_new' => 0 ) );
	}
	function assign( int $site, string $state ): void {
		$GLOBALS['wpdb']->insert( 'wp_pchq_site_plugins', array( 'site_id' => $site, 'slug' => 'demo-plugin', 'plugin_id' => 1, 'state' => $state, 'operation_id' => '', 'error' => '', 'installed_version' => '1.0', 'updated' => time() ) );
	}

	// 1. 没装在任何店铺上：照旧直接删掉，不排队。
	seed();
	assign( 1, 'removed' );
	check( '干净时直接删除', true === Store_Plugins::delete( 'demo-plugin' ) );
	check( '干净时不排队', 0 === count( requests() ) );
	check( '干净时库里清空', 0 === count( $GLOBALS['wpdb']->t['wp_pchq_plugins'] ) );
	check( '干净时没有待删除标记', array() === Store_Plugins::pending() );

	// 2. 还装着且没有 force：维持原来的拒绝，什么都不动。
	seed();
	assign( 1, 'active' );
	$res = Store_Plugins::delete( 'demo-plugin' );
	check( '不带 force 仍然拒绝', is_wp_error( $res ) && 'pchq_plugin_in_use' === $res->get_error_code() );
	check( '拒绝时不排队', 0 === count( requests() ) );
	check( '拒绝时库里还在', 1 === count( $GLOBALS['wpdb']->t['wp_pchq_plugins'] ) );
	check( '拒绝时没有待删除标记', array() === Store_Plugins::pending() );

	// 3. force：活跃的店铺排队卸载，停用 / 不存在的店铺直接去掉记录，插件先留在库里。
	seed();
	assign( 1, 'active' );
	assign( 2, 'failed' );
	assign( 3, 'active' );  // 已停用的店铺，排不进队
	assign( 4, 'active' );  // 根本不存在的店铺
	assign( 1, 'removed' ); // 已经卸干净的记录不该被重复处理
	$res = Store_Plugins::delete( 'demo-plugin', true );
	check( 'force 返回排队结果', is_array( $res ) );
	check( 'force 排了 2 家店铺', 2 === $res['queued'] );
	check( 'force 去掉 2 家没法卸的记录', 2 === $res['dropped'] );
	check( 'force 写了 2 个任务文件', 2 === count( requests() ) );
	check( 'force 后插件还在库里', 1 === count( $GLOBALS['wpdb']->t['wp_pchq_plugins'] ) );
	check( 'force 后标成待删除', array( 'demo-plugin' ) === Store_Plugins::pending() );
	$job = json_decode( file_get_contents( requests()[0] ), true );
	check( '任务是卸载', 'uninstall' === $job['action'] );
	check( '任务带着插件标识', 'demo-plugin' === $job['plugin'] );
	check( '卸载任务不带包指纹', '' === $job['package'] );
	$states = array();
	foreach ( $GLOBALS['wpdb']->t['wp_pchq_site_plugins'] as $r ) { $states[ (int) $r['site_id'] ][] = $r['state']; }
	check( '活跃店铺转成卸载中', in_array( 'removing', $states[1] ?? array(), true ) );
	check( '本来就 removed 的记录没被重新排队', in_array( 'removed', $states[1] ?? array(), true ) );
	check( '失败的记录也转成卸载中', array( 'removing' ) === ( $states[2] ?? array() ) );
	check( '停用店铺的记录已去掉', ! isset( $states[3] ) );
	check( '不存在店铺的记录已去掉', ! isset( $states[4] ) );

	// 4. 还没卸完：finish_pending 不能提前删掉。
	Store_Plugins::finish_pending();
	check( '没卸完不提前删', 1 === count( $GLOBALS['wpdb']->t['wp_pchq_plugins'] ) );
	check( '没卸完还留着待删除标记', array( 'demo-plugin' ) === Store_Plugins::pending() );

	// 5. 全部卸干净后：finish_pending 真的删掉，标记一起清掉。
	foreach ( $GLOBALS['wpdb']->t['wp_pchq_site_plugins'] as $id => $r ) {
		$GLOBALS['wpdb']->t['wp_pchq_site_plugins'][ $id ]['state'] = 'removed';
	}
	Store_Plugins::finish_pending();
	check( '卸干净后库里删掉了', 0 === count( $GLOBALS['wpdb']->t['wp_pchq_plugins'] ) );
	check( '卸干净后分配记录也清了', 0 === count( $GLOBALS['wpdb']->t['wp_pchq_site_plugins'] ) );
	check( '卸干净后清掉待删除标记', array() === Store_Plugins::pending() );

	// 6. 库里已经没有的标记，自己消失，不会卡住。
	$GLOBALS['pc_options'][ Store_Plugins::PENDING ] = array( 'ghost-plugin' );
	Store_Plugins::finish_pending();
	check( '找不到的待删除标记自动清掉', array() === Store_Plugins::pending() );

	foreach ( requests() as $f ) { unlink( $f ); }
	@rmdir( PEPTIDE_HQ_PROVISION_QUEUE . '/plugins/requests' );
	@rmdir( PEPTIDE_HQ_PROVISION_QUEUE . '/plugins/packages' );
	@rmdir( PEPTIDE_HQ_PROVISION_QUEUE . '/plugins' );
	@rmdir( PEPTIDE_HQ_PROVISION_QUEUE );
	echo "OK plugins-force-delete-pure ({$checks} checks)\n";
}
