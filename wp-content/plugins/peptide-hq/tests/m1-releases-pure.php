<?php
/** M1-6：发布记录、发布锁、code_drift 巡检。纯逻辑（内存里的表）：php -n -d extension=mbstring m1-releases-pure.php */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } }
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function add_action( ...$a ): void {}
	function add_filter( ...$a ): void {}
	$GLOBALS['pc_options'] = array( 'pchq_m1_db' => '1' );
	function get_option( string $k, $d = false ) { return $GLOBALS['pc_options'][ $k ] ?? $d; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['pc_options'][ $k ] = $v; return true; }
	function delete_option( string $k ): bool { unset( $GLOBALS['pc_options'][ $k ] ); return true; }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function sanitize_textarea_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	final class Memory_Releases_Db {
		public string $prefix = 'wp_'; public array $rows = array(); public int $insert_id = 0;
		public function get_charset_collate(): string { return ''; }
		public function prepare( string $sql, ...$args ): string { return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$args ) { $v = array_shift( $args ); return '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'"; }, $sql ); }
		public function insert( string $t, array $row ): void { $row['id'] = ++$this->insert_id; $this->rows[ $row['id'] ] = $row + array( 'finished' => 0 ); }
		public function update( string $t, array $fields, array $where ) { if ( ! isset( $this->rows[ $where['id'] ] ) ) { return false; } $this->rows[ $where['id'] ] = array_replace( $this->rows[ $where['id'] ], $fields ); return 1; }
		public function get_row( string $sql, $fmt ) {
			if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) { return $this->rows[ (int) $m[1] ] ?? null; }
			$done = array_filter( $this->rows, static fn( $r ) => 'done' === $r['status'] );
			usort( $done, static fn( $a, $b ) => array( $b['finished'], $b['id'] ) <=> array( $a['finished'], $a['id'] ) );
			return $done[0] ?? null;
		}
		public function get_results( string $sql, $fmt ): array { return array_reverse( array_values( $this->rows ) ); }
	}
	$GLOBALS['wpdb'] = new Memory_Releases_Db();
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-releases.php';
}
namespace {
	use PeptideHq\Releases;
	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }
	$h = static fn( string $c ): string => str_repeat( $c, 64 );
	$manifest = array( 'components' => array( 'peptide-core' => array( 'version' => '0.33.0', 'hash' => $h( 'a' ), 'files' => 554 ), 'peptide-site' => array( 'version' => '0.1.0', 'hash' => $h( 'b' ), 'files' => 406 ), 'bad name' => array( 'hash' => $h( 'c' ) ), 'short' => array( 'hash' => 'abc' ) ) );

	// 1. 清单清洗
	$clean = Releases::clean_manifest( $manifest );
	check( 'only well-formed components kept', array( 'peptide-core', 'peptide-site' ) === array_keys( $clean ) && 554 === $clean['peptide-core']['files'] );

	// 2. 发布锁：同一时间只有一个
	check( 'no lock at first', null === Releases::lock() );
	$id = Releases::start( array( 'label' => '2026.11.13-1', 'commit' => 'ABC1234xyz', 'modules' => 'M1,M4', 'operator' => 'F1', 'manifest' => $manifest ) );
	check( 'release started', 1 === $id && 1 === Releases::lock()['id'] && 'abc1234' === Releases::get( 1 )['git_commit'] );
	$second = Releases::start( array( 'label' => 'x', 'manifest' => $manifest ) );
	check( 'second release refused while the first is running', is_wp_error( $second ) && 'pchq_release_locked' === $second->get_error_code() );
	check( 'empty manifest refused', is_wp_error( Releases::start( array( 'manifest' => array() ) ) ) );

	// 3. 各副本结果、结束、释放锁
	check( 'copy results recorded', Releases::site_result( 1, 's0006/web', 'ok' ) && Releases::site_result( 1, 's0006/admin', 'rolled_back', 'prod-check failed' ) );
	check( 'unknown state stored as failed', Releases::site_result( 1, 's0007/web', 'whatever' ) && 'failed' === Releases::get( 1 )['results']['s0007/web']['state'] );
	check( 'no finished release yet', null === Releases::latest_done() );
	check( 'bad final status refused', false === Releases::finish( 1, 'running' ) );
	check( 'finish releases the lock', Releases::finish( 1, 'done' ) && null === Releases::lock() && 'done' === Releases::latest_done()['status'] );
	check( 'results frozen after finish', false === Releases::site_result( 1, 's0008/web', 'ok' ) );
	$fin = Releases::latest_done()['finished'];

	// 4. 过期的锁不算数
	update_option( Releases::LOCK, array( 'id' => 9, 'by' => 'x', 'at' => time() - 3 * 3600 ) );
	check( 'stale lock ignored', null === Releases::lock() );
	delete_option( Releases::LOCK );

	// 5. 代码核对
	$copy = static fn( string $core, string $site, int $at, string $path ) => array( 'path' => $path, 'at' => $at, 'components' => array( 'peptide-core' => array( 'version' => '0.33.0', 'hash' => $core ), 'peptide-site' => array( 'version' => '0.1.0', 'hash' => $site ), 'peptide-ops' => array( 'version' => '0.2.0', 'hash' => $h( 'f' ) ) ) );
	$status = array( 'code' => array( 'this' => 'adm', 'copies' => array( 'adm' => $copy( $h( 'a' ), $h( 'b' ), $fin - 9999, '/srv/admin' ), 'web' => $copy( $h( 'a' ), $h( 'b' ), $fin + 60, '/srv/web' ) ) ) );
	$r = Releases::compare( $status, $clean, $fin );
	check( 'all copies match', $r['peptide-core']['ok'] && $r['peptide-site']['ok'] && 2 === $r['peptide-core']['checked'] );
	check( 'components not in the release are not judged', ! isset( $r['peptide-ops'] ) );
	$status['code']['copies']['web'] = $copy( $h( 'e' ), $h( 'b' ), $fin + 60, '/srv/web' );
	$r = Releases::compare( $status, $clean, $fin );
	check( 'front copy drift found, theme still fine', ! $r['peptide-core']['ok'] && '/srv/web' === $r['peptide-core']['copies'][0]['path'] && $r['peptide-site']['ok'] );
	$status['code']['copies']['web']['at'] = $fin - 60;
	check( 'copy not refreshed since the release is not judged', Releases::compare( $status, $clean, $fin )['peptide-core']['ok'] && 1 === Releases::compare( $status, $clean, $fin )['peptide-core']['checked'] );
	$status['code']['copies']['web']['at'] = $fin + 60;

	$out = Releases::drift_check( array(), 3, $status, time() );
	$core = array_values( array_filter( $out, static fn( $c ) => 'peptide-core' === $c['ref'] ) )[0];
	check( 'drift check reports per component', 2 === count( $out ) && 'code_drift' === $core['type'] && false === $core['ok'] && str_contains( $core['message'], '/srv/web' ) && str_contains( $core['message'], '2026.11.13-1' ) );
	check( 'old store without code report: not applicable', array() === Releases::drift_check( array(), 3, array( 'versions' => array() ), time() ) );
	update_option( Releases::LOCK, array( 'id' => 2, 'by' => 'F1', 'at' => time() ) );
	check( 'no drift alerts while a release is running', array() === Releases::drift_check( array(), 3, $status, time() ) );
	delete_option( Releases::LOCK );
	$GLOBALS['wpdb']->rows = array();
	check( 'no finished release: not applicable', array() === Releases::drift_check( array(), 3, $status, time() ) );
	check( 'alert label registered', '代码版本不一致' === Releases::alert_types( array() )['code_drift'] );

	echo "m1-releases-pure: {$checks} checks passed\n";
}
