<?php
namespace PeptideOps;
defined( 'ABSPATH' ) || exit;

/** Server-owned private storage and argv-only process execution. */
final class Storage {
	public static function within( string $path, string $root ): bool {
		$normalize = static fn( $p ) => rtrim( str_replace( '\\', '/', $p ), '/' );
		$p = $normalize( $path ); $r = $normalize( $root );
		if ( '\\' === DIRECTORY_SEPARATOR ) { $p = strtolower( $p ); $r = strtolower( $r ); }
		return $p === $r || str_starts_with( $p, $r . '/' );
	}

	public static function root(): string {
		if ( ! defined( 'PEPTIDE_OPS_PRIVATE_DIR' ) || ! is_string( PEPTIDE_OPS_PRIVATE_DIR ) || '' === PEPTIDE_OPS_PRIVATE_DIR ) {
			throw new \RuntimeException( '请先配置站点公开目录之外的运维存储目录。' );
		}
		$dir = rtrim( PEPTIDE_OPS_PRIVATE_DIR, '/\\' );
		if ( ! preg_match( '~^(?:[A-Za-z]:[\\\\/]|/)~', $dir ) || self::within( $dir, ABSPATH ) ) { throw new \RuntimeException( '运维存储目录必须位于站点公开目录之外。' ); }
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { throw new \RuntimeException( '无法创建私有运维目录。' ); }
		$real = realpath( $dir ); $web = realpath( ABSPATH );
		if ( ! $real || ( $web && self::within( $real, $web ) ) || ! is_writable( $real ) ) { throw new \RuntimeException( '私有运维目录不可用。' ); }
		@chmod( $real, 0700 );
		$doc = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
		if ( $doc && self::within( $real, $doc ) ) { throw new \RuntimeException( '运维存储不能位于Web公开目录。' ); }
		return str_replace( '\\', '/', $real );
	}

	public static function path( string $relative ): string {
		if ( '' === $relative ) { return self::root(); }
		if ( str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) || str_contains( $relative, ':' ) || str_starts_with( $relative, '/' ) || preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $relative ) ) { throw new \RuntimeException( '无效的私有文件标识。' ); }
		$path = self::root() . '/' . $relative;
		$cursor = $path;
		while ( ! file_exists( $cursor ) && dirname( $cursor ) !== $cursor ) { $cursor = dirname( $cursor ); }
		$real = realpath( $cursor );
		if ( ! $real || ! self::within( $real, self::root() ) ) { throw new \RuntimeException( '私有文件路径越界。' ); }
		return $path;
	}

	public static function mkdir( string $relative ): string {
		$path = self::path( $relative );
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) { throw new \RuntimeException( '无法创建任务目录。' ); }
		@chmod( $path, 0700 );
		if ( ! self::within( (string) realpath( $path ), self::root() ) ) { throw new \RuntimeException( '任务目录越界。' ); }
		return $path;
	}

	public static function read_json( string $relative, $default = array() ) {
		$path = self::path( $relative );
		if ( ! is_file( $path ) ) { return $default; }
		$value = json_decode( (string) file_get_contents( $path ), true );
		return JSON_ERROR_NONE === json_last_error() ? $value : $default;
	}

	public static function write_json( string $relative, array $data ): void {
		$path = self::path( $relative ); self::mkdir( dirname( $relative ) === '.' ? '' : dirname( $relative ) );
		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( false === $json || false === file_put_contents( $tmp, $json, LOCK_EX ) ) { throw new \RuntimeException( '写入任务记录失败。' ); }
		@chmod( $tmp, 0600 );
		if ( ! rename( $tmp, $path ) ) { @unlink( $tmp ); throw new \RuntimeException( '保存任务记录失败。' ); }
	}

	public static function config(): array { return (array) self::read_json( 'config.json', array() ); }

	public static function site_uuid(): string {
		$existing = (array) get_option( 'peptide_core_hq', array() );
		$id = (string) ( $existing['uuid'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9-]{32,36}$/Di', $id ) ) {
			$state = self::read_json( 'site.json' ); $id = (string) ( $state['uuid'] ?? '' );
			if ( ! preg_match( '/^[a-f0-9-]{32,36}$/Di', $id ) ) { $id = wp_generate_uuid4(); self::write_json( 'site.json', array( 'uuid' => $id ) ); }
		}
		return $id;
	}

	/** No shell is used. Credentials belong in private input/defaults files or environment. */
	public static function run( array $args, array $opts = array() ): array {
		if ( ! $args || ! is_string( $args[0] ) || '' === $args[0] ) { return array( 'code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => false ); }
		foreach ( $args as $arg ) { if ( ! is_scalar( $arg ) || str_contains( (string) $arg, "\0" ) ) { throw new \RuntimeException( '无效的进程参数。' ); } }
		$descriptors = array(
			0 => isset( $opts['stdin_file'] ) ? array( 'file', $opts['stdin_file'], 'rb' ) : array( 'pipe', 'r' ),
			1 => isset( $opts['stdout_file'] ) ? array( 'file', $opts['stdout_file'], 'wb' ) : array( 'pipe', 'w' ),
			2 => isset( $opts['stderr_file'] ) ? array( 'file', $opts['stderr_file'], 'wb' ) : array( 'pipe', 'w' ),
		);
		$env = isset( $opts['env'] ) ? array_merge( (array) getenv(), $opts['env'] ) : null;
		$pipes = array();
		$process = proc_open( array_map( 'strval', $args ), $descriptors, $pipes, $opts['cwd'] ?? null, $env, array( 'bypass_shell' => true ) );
		if ( ! is_resource( $process ) ) { return array( 'code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => false ); }
		if ( isset( $pipes[0] ) ) { if ( isset( $opts['stdin'] ) ) { fwrite( $pipes[0], (string) $opts['stdin'] ); } fclose( $pipes[0] ); }
		foreach ( array( 1, 2 ) as $i ) { if ( isset( $pipes[$i] ) ) { stream_set_blocking( $pipes[$i], false ); } }
		$started = microtime( true ); $timeout = max( 1, min( 3600, (int) ( $opts['timeout'] ?? 300 ) ) );
		$stdout = ''; $stderr = ''; $timed_out = false; $code = -1;
		do {
			foreach ( array( 1, 2 ) as $i ) { if ( isset( $pipes[$i] ) ) { $chunk = stream_get_contents( $pipes[$i] ); if ( 1 === $i ) { $stdout .= $chunk; $stdout = substr( $stdout, -8 * 1024 * 1024 ); } else { $stderr .= $chunk; $stderr = substr( $stderr, -65536 ); } } }
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) { $code = (int) $status['exitcode']; break; }
			if ( microtime( true ) - $started > $timeout ) { $timed_out = true; proc_terminate( $process ); break; }
			usleep( 20000 );
		} while ( true );
		foreach ( array( 1, 2 ) as $i ) { if ( isset( $pipes[$i] ) ) { $chunk = stream_get_contents( $pipes[$i] ); if ( 1 === $i ) { $stdout .= $chunk; } else { $stderr .= $chunk; } fclose( $pipes[$i] ); } }
		$closed = proc_close( $process ); if ( $code < 0 && $closed >= 0 ) { $code = $closed; }
		return array( 'code' => $timed_out ? -2 : $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timed_out );
	}

	public static function spawn( array $args, array $opts = array() ) {
		$cfg = self::config(); $python = (string) ( $cfg['tools']['python'] ?? '' );
		if ( ! is_file( $python ) ) { return new \WP_Error( 'pc_ops_python', '后台执行器尚未配置。' ); }
		$id = bin2hex( random_bytes( 12 ) ); self::mkdir( 'launch' );
		$relative = 'launch/' . $id . '.json';
		self::write_json( $relative, array( 'args' => $args, 'cwd' => $opts['cwd'] ?? null, 'env' => $opts['env'] ?? array(), 'stdout_file' => $opts['stdout_file'] ?? self::path( 'launch/' . $id . '.log' ), 'stderr_file' => $opts['stderr_file'] ?? self::path( 'launch/' . $id . '.err' ) ) );
		$result = self::run( array( $python, PEPTIDE_OPS_DIR . '/tools/background.py', self::path( $relative ) ), array( 'timeout' => 15 ) );
		@unlink( self::path( $relative ) );
		$data = json_decode( $result['stdout'], true );
		return 0 === $result['code'] && ! empty( $data['pid'] ) ? $data : new \WP_Error( 'pc_ops_spawn', '无法启动后台任务，请检查执行环境。' );
	}
}
