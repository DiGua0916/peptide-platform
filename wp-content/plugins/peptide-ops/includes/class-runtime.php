<?php
namespace PeptideOps;
defined( 'ABSPATH' ) || exit;

/** Stable task transport. Installed as an MU loader so a store rollback keeps its worker. */
final class Runtime {
	public const NS = 'peptide-ops/v1';
	private const ACTIONS = array( 'backup.create', 'backup.restore', 'backup.delete', 'backup.export', 'release.apply', 'release.rollback', 'site.provision' );

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'send_headers', static function (): void {
			try { header( 'X-VialCove-Site: ' . Storage::site_uuid() ); } catch ( \Throwable $e ) { /* Unconfigured agent remains inert. */ }
		} );
	}

	private static function key(): string {
		if ( defined( 'PEPTIDE_OPS_SERVER_KEY' ) && 64 === strlen( (string) PEPTIDE_OPS_SERVER_KEY ) ) { return (string) PEPTIDE_OPS_SERVER_KEY; }
		$s = (array) get_option( 'peptide_core_hq', array() );
		return 64 === strlen( (string) ( $s['key'] ?? '' ) ) ? $s['key'] : '';
	}

	public static function verify( \WP_REST_Request $request ) {
		try {
			$key = self::key(); $time = (int) $request->get_header( 'x_pc_hq_time' );
			if ( '' === $key || abs( time() - $time ) > 300 ) { return new \WP_Error( 'pc_ops_auth', '运维请求认证失败。', array( 'status' => 401 ) ); }
			$params = $request->get_query_params(); unset( $params['rest_route'] ); ksort( $params );
			$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
			$expected = hash_hmac( 'sha256', strtoupper( $request->get_method() ) . "\n" . $request->get_route() . "\n" . $query . "\n" . $time . "\n" . hash( 'sha256', (string) $request->get_body() ), $key );
			$signature = strtolower( (string) $request->get_header( 'x_pc_hq_sign' ) );
			if ( 64 !== strlen( $signature ) || ! hash_equals( $expected, $signature ) ) { return new \WP_Error( 'pc_ops_auth', '运维请求认证失败。', array( 'status' => 401 ) ); }
			$binding = defined( 'PEPTIDE_OPS_HQ_URL' ) ? (string) PEPTIDE_OPS_HQ_URL : ( defined( 'PEPTIDE_HQ_URL' ) ? (string) PEPTIDE_HQ_URL : (string) ( ( (array) get_option( 'peptide_core_hq', array() ) )['hq_url'] ?? '' ) );
			if ( '' !== $binding && untrailingslashit( $binding ) !== untrailingslashit( (string) $request->get_header( 'x_pc_hq_url' ) ) ) { return new \WP_Error( 'pc_ops_binding', '管理后台绑定不匹配。', array( 'status' => 403 ) ); }
			Storage::mkdir( 'nonces' );
			self::clean_nonces();
			$handle = @fopen( Storage::path( 'nonces/' . $signature . '.nonce' ), 'x' );
			if ( ! $handle ) { return new \WP_Error( 'pc_ops_replay', '此请求已执行。', array( 'status' => 409 ) ); }
			fwrite( $handle, (string) time() ); fclose( $handle );
			return true;
		} catch ( \Throwable $e ) { self::diagnostic( 'auth', $e ); return new \WP_Error( 'pc_ops_unconfigured', '运维代理的私有存储尚未配置。', array( 'status' => 503 ) ); }
	}

	public static function routes(): void {
		$auth = array( self::class, 'verify' );
		foreach ( array(
			'/status' => array( 'GET', 'status' ), '/inventory' => array( 'GET', 'inventory' ),
			'/jobs' => array( 'POST', 'submit_route' ), '/jobs/(?P<id>[a-f0-9-]{36})' => array( 'GET', 'job_route' ),
			'/jobs/(?P<id>[a-f0-9-]{36})/retry' => array( 'POST', 'retry_route' ),
			'/packages' => array( 'POST', 'upload' ), '/download-ticket' => array( 'POST', 'ticket' ),
		) as $route => $handler ) {
			register_rest_route( self::NS, $route, array( 'methods' => $handler[0], 'callback' => array( self::class, $handler[1] ), 'permission_callback' => $auth ) );
		}
		register_rest_route( self::NS, '/download', array( 'methods' => 'GET', 'callback' => array( self::class, 'download' ), 'permission_callback' => '__return_true' ) );
	}

	public static function status(): array {
		return array( 'version' => PEPTIDE_OPS_VERSION, 'site_uuid' => Storage::site_uuid(), 'site_url' => home_url( '/' ), 'worker_ready' => self::worker_ready(), 'actions' => self::ACTIONS );
	}

	public static function inventory(): array {
		return array( 'status' => self::status(), 'backups' => class_exists( Backups::class ) ? Backups::listing() : array(), 'releases' => class_exists( Releases::class ) ? Releases::inventory() : array(), 'release_history' => class_exists( Releases::class ) && method_exists( Releases::class, 'history' ) ? Releases::history() : array(), 'profiles' => class_exists( Provisioner::class ) ? Provisioner::profiles() : array() );
	}

	public static function worker_ready(): bool {
		$c = Storage::config();
		foreach ( array( 'php', 'python', 'wp_cli' ) as $name ) { if ( empty( $c['tools'][$name] ) || ! is_file( $c['tools'][$name] ) ) { return false; } }
		return function_exists( 'proc_open' );
	}

	public static function valid_id( string $id ): bool { return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id ); }

	/** Local PHP callers may provide an internal dispatcher; REST never accepts internal tasks. */
	public static function submit( string $action, array $input, string $id = '', bool $internal = false ) {
		if ( ! in_array( $action, self::ACTIONS, true ) && ! ( $internal && 'hq.operation' === $action ) ) { return new \WP_Error( 'pc_ops_action', '不支持的运维任务。' ); }
		if ( ! self::worker_ready() ) { return new \WP_Error( 'pc_ops_worker', '请先配置服务器后台执行器。' ); }
		$id = '' === $id ? wp_generate_uuid4() : strtolower( $id );
		if ( ! self::valid_id( $id ) ) { return new \WP_Error( 'pc_ops_id', '无效的任务编号。' ); }
		$input['operation_id'] = $id;
		$hash = hash( 'sha256', (string) wp_json_encode( array( $action, $input ) ) );
		Storage::mkdir( 'jobs' );
		$lock = fopen( Storage::path( 'jobs/submit.lock' ), 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) { return new \WP_Error( 'pc_ops_lock', '任务队列暂时忙，请重试。' ); }
		try {
			$old = Storage::read_json( 'jobs/' . $id . '.json' );
			if ( $old ) { return $old['input_hash'] === $hash ? self::public_job( $old ) : new \WP_Error( 'pc_ops_conflict', '同一个任务编号已用于其他操作。' ); }
			$job = array( 'id' => $id, 'action' => $action, 'input' => $input, 'input_hash' => $hash, 'status' => 'queued', 'progress' => 0, 'phase' => 'queued', 'message' => '等待后台执行。', 'created' => time(), 'updated' => time(), 'attempts' => 0, 'log' => array() );
			Storage::write_json( 'jobs/' . $id . '.json', $job );
		} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
		$launch = self::launch( $id );
		if ( is_wp_error( $launch ) ) { $job['status'] = 'failed'; $job['message'] = $launch->get_error_message(); Storage::write_json( 'jobs/' . $id . '.json', $job ); }
		return self::public_job( $job );
	}

	private static function launch( string $id ) {
		$c = Storage::config(); $args = array( $c['tools']['php'] );
		if ( ! empty( $c['tools']['php_ini'] ) ) { $args[] = '-c'; $args[] = $c['tools']['php_ini']; }
		$args = array_merge( $args, array( $c['tools']['wp_cli'], '--path=' . untrailingslashit( ABSPATH ), '--no-color', 'eval-file', PEPTIDE_OPS_DIR . '/tools/run-job.php', $id ) );
		$env = array( 'WP_CLI_CACHE_DIR' => Storage::mkdir( 'cli-cache' ) );
		$env['PATH'] = dirname( $c['tools']['php'] ) . PATH_SEPARATOR . (string) getenv( 'PATH' );
		return Storage::spawn( $args, array( 'cwd' => ABSPATH, 'env' => $env, 'stdout_file' => Storage::path( 'jobs/' . $id . '.stdout' ), 'stderr_file' => Storage::path( 'jobs/' . $id . '.stderr' ) ) );
	}

	public static function work( string $id ): void {
		if ( ! self::valid_id( $id ) ) { throw new \RuntimeException( 'Invalid operation id.' ); }
		Storage::mkdir( 'jobs' );
		$job_lock = fopen( Storage::path( 'jobs/' . $id . '.run.lock' ), 'c' );
		if ( ! $job_lock || ! flock( $job_lock, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $job_lock ) ) { fclose( $job_lock ); } return; }
		$job = Storage::read_json( 'jobs/' . $id . '.json' );
		if ( ! $job || ! in_array( $job['status'] ?? '', array( 'queued', 'waiting' ), true ) ) { flock( $job_lock, LOCK_UN ); fclose( $job_lock ); return; }
		$lock = fopen( Storage::path( 'jobs/operation.lock' ), 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			try { $job['status'] = 'waiting'; $job['message'] = '已排队，当前运维任务结束后自动执行。'; $job['updated'] = time(); Storage::write_json( 'jobs/' . $id . '.json', $job ); }
			finally { if ( is_resource( $lock ) ) { fclose( $lock ); } flock( $job_lock, LOCK_UN ); fclose( $job_lock ); }
			// The old worker may have scanned while this job lock was still held.
			self::wake_if_idle(); return;
		}
		$progress = static function ( int $percent, string $phase, string $message ) use ( &$job, $id ): void {
			$job['progress'] = max( 0, min( 99, $percent ) ); $job['phase'] = sanitize_key( $phase ); $job['message'] = mb_substr( wp_strip_all_tags( $message ), 0, 500 ); $job['updated'] = time();
			$job['log'][] = array( 'time' => time(), 'progress' => $job['progress'], 'phase' => $job['phase'], 'message' => $job['message'] );
			$job['log'] = array_slice( $job['log'], -100 ); Storage::write_json( 'jobs/' . $id . '.json', $job );
		};
		try {
			$job['status'] = 'running'; $job['started'] = time(); $job['updated'] = time(); ++$job['attempts']; Storage::write_json( 'jobs/' . $id . '.json', $job );
			$input = $job['input'];
			switch ( $job['action'] ) {
				case 'backup.create': $result = Backups::create( $input, $progress ); break;
				case 'backup.export': $result = Backups::export( (string) ( $input['backup_id'] ?? '' ) ); break;
				case 'backup.restore': $result = Backups::restore( (string) ( $input['backup_id'] ?? '' ), $input, $progress ); break;
				case 'backup.delete': $result = Backups::delete( (string) ( $input['backup_id'] ?? '' ) ); $result = true === $result ? array( 'deleted' => true ) : $result; break;
				case 'release.apply': $result = Releases::apply( $input, $progress ); break;
				case 'release.rollback': $result = Releases::rollback( $input, $progress ); break;
				case 'site.provision': $result = Provisioner::create( $input, $progress ); break;
				default: $result = apply_filters( 'peptide_ops_execute_internal', null, $job['action'], $input, $progress );
			}
			if ( is_wp_error( $result ) ) {
				$job['status'] = 'failed'; $job['error_code'] = $result->get_error_code(); $job['message'] = $result->get_error_message(); $job['result'] = self::redact( (array) $result->get_error_data() );
			} elseif ( is_array( $result ) ) { $job['status'] = 'succeeded'; $job['progress'] = 100; $job['phase'] = 'complete'; $job['message'] = '任务已完成。'; $job['result'] = $result;
			} else { $job['status'] = 'failed'; $job['message'] = '任务处理器没有返回有效结果。'; }
		} catch ( \Throwable $e ) { self::diagnostic( 'work-' . $id, $e ); $job['status'] = 'failed'; $job['message'] = '运维任务执行失败，请查看环境预检和任务诊断。'; $job['error_code'] = 'pc_ops_exception'; }
		finally {
			try { $job['updated'] = time(); $job['finished'] = time(); Storage::write_json( 'jobs/' . $id . '.json', $job ); }
			catch ( \Throwable $e ) { self::diagnostic( 'finish-' . $id, $e ); }
			finally { flock( $lock, LOCK_UN ); fclose( $lock ); flock( $job_lock, LOCK_UN ); fclose( $job_lock ); }
			self::launch_next();
		}
	}

	/** Launch after releasing the global lock; a per-job lock prevents double workers from rerunning it. */
	private static function wake_if_idle(): void {
		$h = fopen( Storage::path( 'jobs/operation.lock' ), 'c' ); if ( ! $h || ! flock( $h, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $h ) ) { fclose( $h ); } return; }
		flock( $h, LOCK_UN ); fclose( $h ); self::launch_next();
	}

	private static function launch_next(): void {
		try {
			$pending = array();
			foreach ( glob( Storage::path( 'jobs/*.json' ) ) ?: array() as $file ) { $j = json_decode( (string) file_get_contents( $file ), true ); if ( is_array( $j ) && self::valid_id( (string) ( $j['id'] ?? '' ) ) && in_array( $j['status'] ?? '', array( 'queued', 'waiting' ), true ) ) { $pending[] = $j; } }
			usort( $pending, static fn( $a, $b ) => ( $a['created'] <=> $b['created'] ) ?: strcmp( $a['id'], $b['id'] ) );
			foreach ( $pending as $job ) {
				$started = self::launch( $job['id'] ); if ( ! is_wp_error( $started ) ) { return; }
				$h = fopen( Storage::path( 'jobs/' . $job['id'] . '.run.lock' ), 'c' ); if ( ! $h || ! flock( $h, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $h ) ) { fclose( $h ); } continue; }
				try { $current = Storage::read_json( 'jobs/' . $job['id'] . '.json' ); if ( in_array( $current['status'] ?? '', array( 'queued', 'waiting' ), true ) ) { $current['status'] = 'failed'; $current['message'] = '无法启动后台执行器，请检查配置后重试。'; $current['updated'] = time(); Storage::write_json( 'jobs/' . $job['id'] . '.json', $current ); } }
				finally { flock( $h, LOCK_UN ); fclose( $h ); }
			}
		} catch ( \Throwable $e ) { self::diagnostic( 'queue', $e ); }
	}

	private static function diagnostic( string $context, \Throwable $error ): void {
		try { Storage::mkdir( 'diagnostics' ); $id = sanitize_key( $context ) . '-' . time() . '-' . bin2hex( random_bytes( 3 ) ); Storage::write_json( 'diagnostics/' . $id . '.json', array( 'time' => time(), 'context' => $context, 'exception' => get_class( $error ), 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine(), 'trace' => $error->getTraceAsString() ) ); }
		catch ( \Throwable $ignored ) { /* Never move private exception text into a public response or log. */ }
	}

	private static function clean_nonces(): void {
		$h = fopen( Storage::path( 'nonces/cleanup.lock' ), 'c' ); if ( ! $h || ! flock( $h, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $h ) ) { fclose( $h ); } return; }
		try { $stamp = Storage::path( 'nonces/cleanup.time' ); if ( is_file( $stamp ) && filemtime( $stamp ) > time() - 60 ) { return; } foreach ( glob( Storage::path( 'nonces/*.nonce' ) ) ?: array() as $file ) { if ( ! is_link( $file ) && is_file( $file ) && filemtime( $file ) < time() - 610 ) { unlink( $file ); } } file_put_contents( $stamp, (string) time() ); }
		finally { flock( $h, LOCK_UN ); fclose( $h ); }
	}

	public static function redact( array $data ): array {
		$out = array(); foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/password|secret|token|credential|key_enc|(?:^|_)path$|(?:^|_)root$|(?:^|_)command$/i', $key ) ) { continue; }
			$out[$key] = is_array( $value ) ? self::redact( $value ) : $value;
		} return $out;
	}

	public static function public_job( array $job ): array { unset( $job['input'], $job['input_hash'] ); return self::redact( $job ); }
	public static function jobs(): array { $out = array(); foreach ( glob( Storage::path( 'jobs/*.json' ) ) ?: array() as $file ) { $j = json_decode( (string) file_get_contents( $file ), true ); if ( is_array( $j ) && isset( $j['id'] ) ) { $out[] = self::public_job( self::recover_lost( $j ) ); } } usort( $out, static fn( $a, $b ) => $b['created'] <=> $a['created'] ); return array_slice( $out, 0, 100 ); }
	/** Detect a dead worker without restarting destructive operations. A held OS lock always wins. */
	private static function recover_lost( array $job ): array {
		if ( 'running' !== ( $job['status'] ?? '' ) || (int) ( $job['updated'] ?? time() ) > time() - 120 || ! self::valid_id( $job['id'] ?? '' ) ) { return $job; }
		$h = fopen( Storage::path( 'jobs/' . $job['id'] . '.run.lock' ), 'c' );
		if ( ! $h || ! flock( $h, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $h ) ) { fclose( $h ); } return $job; }
		try {
			$current = Storage::read_json( 'jobs/' . $job['id'] . '.json' );
			if ( 'running' === ( $current['status'] ?? '' ) && (int) $current['updated'] <= time() - 120 ) {
				$current['status'] = 'failed'; $current['error_code'] = 'pc_ops_worker_lost'; $current['updated'] = time();
				$current['message'] = '执行进程已中断。请检查原任务及保留恢复点。';
				$current['result'] = array( 'manual_recovery_required' => ! in_array( $current['action'], array( 'hq.operation', 'backup.create', 'backup.delete', 'backup.export' ), true ) );
				Storage::write_json( 'jobs/' . $job['id'] . '.json', $current ); return $current;
			}
			return $current ?: $job;
		} finally { flock( $h, LOCK_UN ); fclose( $h ); }
	}
	public static function submit_route( \WP_REST_Request $r ) { $p = (array) $r->get_json_params(); return self::submit( (string) ( $p['action'] ?? '' ), (array) ( $p['input'] ?? array() ), (string) ( $p['operation_id'] ?? '' ) ); }
	public static function job_route( \WP_REST_Request $r ) { $j = Storage::read_json( 'jobs/' . $r['id'] . '.json' ); return $j ? self::public_job( self::recover_lost( $j ) ) : new \WP_Error( 'pc_ops_missing', '未找到任务。', array( 'status' => 404 ) ); }
	public static function retry( string $id ) {
		if ( ! self::valid_id( $id ) ) { return new \WP_Error( 'pc_ops_id', '无效任务。' ); }
		$j = Storage::read_json( 'jobs/' . $id . '.json' );
		if ( ! $j || ! in_array( $j['status'], array( 'failed', 'waiting' ), true ) ) { return new \WP_Error( 'pc_ops_retry', '此任务目前不能重试。' ); }
		if ( ! empty( $j['result']['manual_recovery_required'] ) ) { return new \WP_Error( 'pc_ops_manual', '此任务需要先检查恢复点，不能自动重跑。' ); }
		$j['status'] = 'queued'; $j['message'] = '已请求重试。'; Storage::write_json( 'jobs/' . $id . '.json', $j ); $start = self::launch( $id ); return is_wp_error( $start ) ? $start : self::public_job( $j );
	}
	public static function retry_route( \WP_REST_Request $r ) { return self::retry( (string) $r['id'] ); }

	/** Chunked upload avoids request/memory limits for administrator-selected code archives. */
	public static function upload( \WP_REST_Request $r ) {
		$p = (array) $r->get_json_params(); $id = strtolower( (string) ( $p['sha256'] ?? '' ) );
		$index = (int) ( $p['index'] ?? -1 ); $chunks = (int) ( $p['chunks'] ?? 0 ); $bytes = (int) ( $p['bytes'] ?? 0 );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) || $chunks < 1 || $chunks > 512 || $index < 0 || $index >= $chunks || $bytes < 1 || $bytes > 256 * 1024 * 1024 || $chunks !== (int) ceil( $bytes / 524288 ) || strlen( (string) ( $p['data'] ?? '' ) ) > 800000 ) { return new \WP_Error( 'pc_ops_upload', '升级包分片无效。', array( 'status' => 400 ) ); }
		$chunk = base64_decode( (string) ( $p['data'] ?? '' ), true ); if ( false === $chunk || strlen( $chunk ) !== min( 524288, $bytes - $index * 524288 ) ) { return new \WP_Error( 'pc_ops_upload', '升级包分片长度与清单不符。' ); }
		Storage::mkdir( 'packages' );
		$lock = fopen( Storage::path( 'packages/' . $id . '.lock' ), 'c' ); if ( ! $lock || ! flock( $lock, LOCK_EX ) ) { return new \WP_Error( 'pc_ops_upload_busy', '升级包正在写入。' ); }
		try {
			$part = Storage::path( 'packages/' . $id . '.part' ); $final = Storage::path( 'packages/' . $id . '.zip' );
			if ( is_file( $final ) ) {
				if ( filesize( $final ) !== $bytes || ! hash_equals( $id, (string) hash_file( 'sha256', $final ) ) ) { self::reset_upload( $id, true ); return new \WP_Error( 'pc_ops_upload_hash', '已有升级包校验失败，已清理，请从首片重新上传。' ); }
				return array( 'package_id' => $id, 'next' => $chunks, 'complete' => true );
			}
			$meta = Storage::read_json( 'packages/' . $id . '.json', array( 'sha256' => $id, 'name' => sanitize_file_name( $p['name'] ?? 'package.zip' ), 'bytes' => $bytes, 'chunks' => $chunks, 'next' => 0, 'hashes' => array(), 'complete' => false ) );
			if ( ! is_array( $meta ) || ( $meta['bytes'] ?? null ) !== $bytes || ( $meta['chunks'] ?? null ) !== $chunks || ( $meta['sha256'] ?? '' ) !== $id ) { return new \WP_Error( 'pc_ops_upload_conflict', '上传清单不一致。' ); }
			if ( ! is_int( $meta['next'] ?? null ) || $meta['next'] < 0 || $meta['next'] >= $chunks || ! is_array( $meta['hashes'] ?? null ) || count( $meta['hashes'] ) !== $meta['next'] || ! empty( $meta['complete'] ) ) { self::reset_upload( $id ); return new \WP_Error( 'pc_ops_upload_state', '上传状态不完整，已清理，请从首片重新上传。' ); }
			clearstatcache( true, $part ); $received = is_file( $part ) ? filesize( $part ) : 0;
			if ( $received !== $meta['next'] * 524288 ) { self::reset_upload( $id ); return new \WP_Error( 'pc_ops_upload_state', '暂存文件长度不一致，已清理，请重新上传。' ); }
			$hash = hash( 'sha256', $chunk );
			if ( $index < $meta['next'] ) { return ( $meta['hashes'][$index] ?? '' ) === $hash ? array( 'package_id' => $id, 'next' => $meta['next'], 'complete' => $meta['complete'] ) : new \WP_Error( 'pc_ops_upload_conflict', '重复分片内容不一致。' ); }
			if ( $index !== $meta['next'] ) { return new \WP_Error( 'pc_ops_upload_order', '升级包分片顺序不正确。' ); }
			if ( strlen( $chunk ) !== file_put_contents( $part, $chunk, FILE_APPEND | LOCK_EX ) ) { self::reset_upload( $id ); return new \WP_Error( 'pc_ops_upload_write', '升级包写入不完整，已清理，请重新上传。' ); }
			$meta['hashes'][] = $hash; ++$meta['next'];
			if ( $meta['next'] === $chunks ) {
				clearstatcache( true, $part );
				if ( filesize( $part ) !== $bytes || ! hash_equals( $id, (string) hash_file( 'sha256', $part ) ) ) { self::reset_upload( $id ); return new \WP_Error( 'pc_ops_upload_hash', '升级包完整性校验失败，已清理，请重新上传。' ); }
				if ( ! rename( $part, $final ) ) { self::reset_upload( $id ); return new \WP_Error( 'pc_ops_upload_write', '升级包无法完成保存，请重新上传。' ); } $meta['complete'] = true;
			}
			Storage::write_json( 'packages/' . $id . '.json', $meta );
			return array( 'package_id' => $id, 'next' => $meta['next'], 'complete' => $meta['complete'] );
		} catch ( \Throwable $e ) { self::diagnostic( 'upload-' . $id, $e ); try { self::reset_upload( $id ); } catch ( \Throwable $ignored ) {} return new \WP_Error( 'pc_ops_upload_write', '升级包暂存失败，请检查私有存储后重新上传。' ); }
		finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
	}
	private static function reset_upload( string $id, bool $final = false ): void { foreach ( $final ? array( '.part', '.json', '.zip' ) : array( '.part', '.json' ) as $suffix ) { $file = Storage::path( 'packages/' . $id . $suffix ); if ( is_file( $file ) && ! unlink( $file ) ) { throw new \RuntimeException( 'Unable to clean incomplete private upload.' ); } } }

	public static function ticket( \WP_REST_Request $r ) {
		$p = (array) $r->get_json_params(); $id = (string) ( $p['backup_id'] ?? '' );
		if ( ! self::valid_id( $id ) ) { return new \WP_Error( 'pc_ops_backup', '无效备份。' ); }
		$dir = Storage::path( 'backups/' . $id );
		if ( ! is_file( $dir . '/manifest.json' ) || ! is_file( $dir . '/files.zip' ) || ! is_file( $dir . '/database.sql' ) ) { return new \WP_Error( 'pc_ops_backup', '备份不存在或尚未完成。' ); }
		$manifest = Storage::read_json( 'backups/' . $id . '/manifest.json', array() );
		if ( 'complete' !== ( $manifest['state'] ?? '' ) || Storage::site_uuid() !== ( $manifest['site_uuid'] ?? '' ) ) { return new \WP_Error( 'pc_ops_backup', '备份归属或完成标记不匹配。' ); }
		Storage::mkdir( 'exports' ); $export = Storage::path( 'exports/' . $id . '.zip' );
		$meta = Storage::read_json( 'exports/' . $id . '.json', array() );
		if ( ! is_file( $export ) || empty( $meta['complete'] ) || ( $meta['backup_id'] ?? '' ) !== $id || (int) ( $meta['bytes'] ?? 0 ) !== filesize( $export ) ) {
			$hash = substr( hash( 'sha256', 'backup-export:' . $id ), 0, 32 ); $job_id = substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-5' . substr( $hash, 13, 3 ) . '-a' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20 );
			$job = self::submit( 'backup.export', array( 'backup_id' => $id ), $job_id );
			if ( ! is_wp_error( $job ) && 'failed' === ( $job['status'] ?? '' ) ) { self::retry( $job_id ); }
			return new \WP_Error( 'pc_ops_export_preparing', '完整下载包正在后台准备，请稍后再点下载。', array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) ); Storage::mkdir( 'tickets' ); Storage::write_json( 'tickets/' . hash( 'sha256', $token ) . '.json', array( 'id' => $id, 'expires' => time() + 120 ) );
		return array( 'url' => add_query_arg( array( 'rest_route' => '/' . self::NS . '/download', 'ticket' => $token ), home_url( '/' ) ), 'expires_in' => 120 );
	}

	public static function download( \WP_REST_Request $r ) {
		$token = (string) $r->get_param( 'ticket' ); if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return new \WP_Error( 'pc_ops_ticket', '下载链接已失效。', array( 'status' => 403 ) ); }
		$path = Storage::path( 'tickets/' . hash( 'sha256', $token ) . '.json' );
		$data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		if ( ! $data || $data['expires'] < time() || ! self::valid_id( $data['id'] ) || ! @rename( $path, $path . '.used' ) ) { return new \WP_Error( 'pc_ops_ticket', '下载链接已失效。', array( 'status' => 403 ) ); }
		$file = Storage::path( 'exports/' . $data['id'] . '.zip' );
		if ( ! is_file( $file ) ) { return new \WP_Error( 'pc_ops_export', '下载文件不存在。', array( 'status' => 404 ) ); }
		nocache_headers(); header( 'Content-Type: application/zip' ); header( 'Content-Length: ' . filesize( $file ) ); header( 'Content-Disposition: attachment; filename="vialcove-backup-' . $data['id'] . '.zip"' ); header( 'X-Content-Type-Options: nosniff' ); readfile( $file ); exit;
	}
}
