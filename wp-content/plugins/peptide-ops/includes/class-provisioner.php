<?php
/** Controlled creation of an empty, independently owned WordPress store. */
namespace PeptideOps;

defined( 'ABSPATH' ) || exit;

final class Provisioner {
	private const MARKER = '.peptide-ops-owner.json';
	private const RESUMABLE = array( 'prepared', 'database', 'files', 'installed', 'configured', 'served', 'registered', 'complete' );
	private const STORE_PLUGINS = array( 'woocommerce', 'peptide-core', 'peptide-panapay' );

	/** Public profile metadata. Secrets, paths and commands stay on the deployment agent. */
	public static function profiles(): array {
		$out = array();
		foreach ( self::raw_profiles() as $id => $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$out[] = array( 'id' => (string) $id, 'label' => (string) ( $p['label'] ?? $id ), 'driver' => (string) ( $p['driver'] ?? '' ), 'enabled' => ! empty( $p['enabled'] ), 'environment' => (string) ( $p['environment'] ?? 'production' ) );
		}
		return $out;
	}

	public static function preflight( array $input ): array|\WP_Error {
		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) { return $plan; }
		return array( 'ready' => true, 'profile_id' => $plan['profile_id'], 'slug' => $plan['slug'], 'title' => $plan['title'], 'driver' => $plan['profile']['driver'], 'environment' => $plan['profile']['environment'] ?? 'production', 'independent_database_user' => ! empty( $plan['profile']['db']['create_user'] ), 'url' => $plan['url'], 'url_assigned_on_start' => '' === $plan['url'], 'will_copy_customer_data' => false, 'will_send_email' => false, 'requires_external_dns' => 'webserver' === $plan['profile']['driver'] );
	}

	/** The caller supplies an operation UUID and a progress callback; it cannot choose commands or roots. */
	public static function create( array $input, callable $progress ): array|\WP_Error {
		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$id = $plan['operation_id'];
		$rel = 'provision/' . $id;
		Storage::mkdir( $rel );
		$lock = fopen( Storage::path( $rel . '/operation.lock' ), 'c+' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $lock ) ) { fclose( $lock ); } return self::error( 'busy', '该建站任务正在执行，请查看原任务。' ); }
		$state = Storage::read_json( $rel . '/state.json', array() );
		try {
			if ( $state && ( ( $state['profile_id'] ?? '' ) !== $plan['profile_id'] || ( $state['slug'] ?? '' ) !== $plan['slug'] ) ) { return self::error( 'operation_conflict', '任务编号已经绑定其他站点。' ); }
			if ( 'complete' === ( $state['phase'] ?? '' ) ) {
				$health = self::health( (string) $state['url'], (string) $state['uuid'], 4 );
				return is_wp_error( $health ) ? self::error( 'existing_unhealthy', '原任务已经完成，但站点当前健康检查未通过，请检查该站点，不会重复建库。' ) : (array) $state['result'];
			}
			if ( $state && ! in_array( $state['phase'] ?? '', self::RESUMABLE, true ) ) { return self::error( 'failed_operation', '原任务已失败，请核对清理结果后用新的任务编号重试。' ); }
			if ( ! $state ) {
				$private = self::join( $plan['profile']['private_root'], $plan['slug'] );
				$uuid = wp_generate_uuid4();
				$state = array( 'operation_id' => $id, 'profile_id' => $plan['profile_id'], 'profile_hash' => $plan['profile_hash'], 'request_hash' => $plan['request_hash'], 'slug' => $plan['slug'], 'root' => $plan['target'], 'private' => $private, 'url' => $plan['url'], 'uuid' => $uuid, 'phase' => 'prepared', 'created' => time(), 'db_name' => self::database_name( $plan ), 'db_user' => '', 'db_created' => false, 'user_created' => false, 'vhost_started' => false );
				Storage::write_json( $rel . '/state.json', $state );
				self::mkdir_owned( $state['root'], $plan['profile']['managed_root'], $id );
				self::mkdir_owned( $private, $plan['profile']['private_root'], $id );
				Storage::write_json( $rel . '/state.json', $state );
			}
			self::assert_owner( $state['root'], $id ); self::assert_owner( $state['private'], $id );
			$credentials = Storage::read_json( $rel . '/credentials.json', array() );
			if ( ! $credentials ) {
				$credentials = array( 'admin_login' => $plan['admin_login'], 'admin_email' => $plan['admin_email'], 'admin_password' => bin2hex( random_bytes( 24 ) ), 'db_password' => bin2hex( random_bytes( 32 ) ) );
				Storage::write_json( $rel . '/credentials.json', $credentials );
				@chmod( Storage::path( $rel . '/credentials.json' ), 0600 );
			}
			$p = $plan['profile'];
			if ( 'php-server' === $p['driver'] && empty( $state['port'] ) ) {
				$requested = '' === $state['url'] ? 0 : (int) wp_parse_url( $state['url'], PHP_URL_PORT );
				$port = self::reserve_port( $p, $id, $requested );
				if ( is_wp_error( $port ) ) { throw new \RuntimeException( $port->get_error_code() ); }
				$state['port'] = $port; $state['url'] = 'http://127.0.0.1:' . $port;
				Storage::write_json( $rel . '/state.json', $state );
			}
			if ( self::before( $state, 'database' ) ) {
				$progress( 10, 'database', '创建独立数据库和站点数据库账号。' );
				self::create_database( $p, $state, $credentials, $rel );
				$state['phase'] = 'database'; Storage::write_json( $rel . '/state.json', $state );
			}
			if ( self::before( $state, 'files' ) ) {
				$progress( 25, 'files', '安装受控的 WordPress、WooCommerce 和店铺源码。' );
				self::copy_wordpress( $p, $state );
				self::write_config( $p, $state, $credentials );
				$state['phase'] = 'files'; Storage::write_json( $rel . '/state.json', $state );
			}
			if ( self::before( $state, 'installed' ) ) {
				$progress( 45, 'install', '安装空站点，不复制原站用户、商品和订单。' );
				self::wp( $p, $state, array( 'core', 'install', '--url=' . $state['url'], '--title=' . $plan['title'], '--admin_user=' . $credentials['admin_login'], '--admin_email=' . $credentials['admin_email'], '--skip-email', '--quiet' ), $rel );
				$state['phase'] = 'installed'; Storage::write_json( $rel . '/state.json', $state );
			}
			if ( self::before( $state, 'configured' ) ) {
				$progress( 60, 'configure', '启用店铺组件并配置独立登录及管理后台接入。' );
				self::activate_store_plugins( $p, $state, $rel );
				self::wp( $p, $state, array( 'theme', 'activate', 'peptide-site', '--quiet' ), $rel );
				self::configure_empty_store( $p, $state, $credentials, $rel );
				$state['phase'] = 'configured'; Storage::write_json( $rel . '/state.json', $state );
			}
			if ( self::before( $state, 'served' ) ) {
				$progress( 75, 'server', '接通站点运行环境并核对网址。' );
				self::start_server( $p, $state, $rel );
				$state['phase'] = 'served'; Storage::write_json( $rel . '/state.json', $state );
			}
			$health = self::health( $state['url'], $state['uuid'], 25 );
			if ( is_wp_error( $health ) ) { throw new \RuntimeException( 'health_failed' ); }
			$progress( 88, 'register', '确认站点身份并接入管理后台。' );
			self::register_store( $p, $state, $rel );
			$state['phase'] = 'registered'; Storage::write_json( $rel . '/state.json', $state );
			$result = array( 'operation_id' => $id, 'profile_id' => $plan['profile_id'], 'slug' => $plan['slug'], 'url' => $state['url'], 'uuid' => $state['uuid'], 'admin_login' => $credentials['admin_login'], 'environment' => $p['environment'] ?? 'production', 'health_verified' => true, 'hq_registration_verified' => true, 'empty_store' => true, 'credentials_private' => true, 'external_dns_managed' => false, 'tls_provisioned' => false );
			$state['result'] = $result; $state['phase'] = 'complete'; $state['completed'] = time();
			Storage::write_json( $rel . '/state.json', $state );
			$progress( 100, 'complete', '新店已运行并接入管理后台，可通过管理后台登录。' );
			return $result;
		} catch ( \Throwable $e ) {
			$cleanup = $state ? self::cleanup_owned( $plan['profile'], $state, $rel ) : array();
			$state['phase'] = 'failed'; $state['failed'] = time(); $state['cleanup'] = $cleanup;
			$state['error_code'] = preg_match( '/^[a-z_]{2,80}$/D', $e->getMessage() ) ? $e->getMessage() : 'provision_failed';
			Storage::write_json( $rel . '/state.json', $state );
			return new \WP_Error( 'pc_ops_provision_failed', '建站未完成，已尝试回收本任务创建的资源。未完成清理的资源将保留，请查看任务阶段及清理状态。', array( 'status' => 500, 'phase' => $state['error_code'], 'cleanup_complete' => ! in_array( false, $cleanup, true ) ) );
		} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
	}

	/** Only isolated CLI fixtures explicitly tagged test_only may be removed after success. */
	public static function teardown_test( string $id ): array|\WP_Error {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! self::valid_id( $id ) ) { return self::error( 'forbidden', '仅隔离命令行验收可回收测试站点。' ); }
		$rel = 'provision/' . $id; $state = Storage::read_json( $rel . '/state.json', array() );
		$p = self::raw_profiles()[ $state['profile_id'] ?? '' ] ?? array();
		if ( ! $state || empty( $p['test_only'] ) || 'local' !== ( $p['environment'] ?? '' ) ) { return self::error( 'not_test', '此站点不是可回收的隔离测试站点。' ); }
		$result = self::cleanup_owned( $p, $state, $rel );
		$state['phase'] = 'test_removed'; $state['cleanup'] = $result; Storage::write_json( $rel . '/state.json', $state );
		return $result;
	}

	private static function raw_profiles(): array { $v = Storage::read_json( 'profiles.json', array() ); return isset( $v['profiles'] ) && is_array( $v['profiles'] ) ? $v['profiles'] : array(); }
	private static function error( string $code, string $message ): \WP_Error { return new \WP_Error( 'pc_ops_provision_' . $code, $message, array( 'status' => 400 ) ); }
	private static function valid_id( string $id ): bool { return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id ); }
	private static function join( string $a, string $b ): string { return rtrim( str_replace( '\\', '/', $a ), '/' ) . '/' . $b; }
	private static function within( string $path, string $root ): bool { $p = rtrim( str_replace( '\\', '/', $path ), '/' ); $r = rtrim( str_replace( '\\', '/', $root ), '/' ); if ( DIRECTORY_SEPARATOR === '\\' ) { $p = strtolower( $p ); $r = strtolower( $r ); } return $p !== $r && str_starts_with( $p, $r . '/' ); }
	private static function before( array $state, string $phase ): bool { return array_search( $state['phase'], self::RESUMABLE, true ) < array_search( $phase, self::RESUMABLE, true ); }

	private static function plan( array $in ): array|\WP_Error {
		$id = strtolower( (string) ( $in['operation_id'] ?? '' ) );
		$profile_id = (string) ( $in['profile_id'] ?? $in['profile'] ?? '' );
		$p = self::raw_profiles()[ $profile_id ] ?? null;
		if ( ! self::valid_id( $id ) ) { return self::error( 'id', '建站任务需要有效的唯一任务编号。' ); }
		if ( ! is_array( $p ) || empty( $p['enabled'] ) ) { return self::error( 'profile', '部署环境未配置或尚未启用。' ); }
		if ( ! in_array( $p['driver'] ?? '', array( 'php-server', 'webserver' ), true ) ) { return self::error( 'driver', '部署环境的运行方式不受支持。' ); }
		$slug = strtolower( (string) ( $in['slug'] ?? '' ) );
		if ( ! preg_match( '/^[a-z][a-z0-9-]{2,47}$/D', $slug ) || preg_match( '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/Di', $slug ) ) { return self::error( 'slug', '站点标识使用3至48位小写英文、数字和短横线，并以英文开头。' ); }
		$title = trim( sanitize_text_field( (string) ( $in['title'] ?? $in['name'] ?? '' ) ) );
		$login = (string) ( $in['admin_login'] ?? 'store_admin' ); $email = (string) ( $in['admin_email'] ?? $p['default_admin_email'] ?? '' );
		if ( '' === $title || mb_strlen( $title ) > 120 || ! preg_match( '/^[A-Za-z][A-Za-z0-9_.-]{2,47}$/D', $login ) || ! is_email( $email ) ) { return self::error( 'identity', '请填写站点名称、合法管理员账号和邮箱。' ); }
		foreach ( array( 'managed_root', 'private_root', 'wp_source', 'theme_source', 'ops_source', 'php_binary', 'wp_cli' ) as $field ) {
			if ( empty( $p[ $field ] ) || ! is_string( $p[ $field ] ) || ! self::absolute( $p[ $field ] ) || str_contains( $p[ $field ], "\0" ) ) { return self::error( 'profile_path', '部署环境路径未完整配置。' ); }
		}
		foreach ( array( 'managed_root', 'private_root', 'wp_source', 'theme_source', 'ops_source' ) as $field ) { if ( ! is_dir( $p[ $field ] ) || ! realpath( $p[ $field ] ) ) { return self::error( 'source', '部署环境的站点根目录或源码目录不存在。' ); } $p[ $field ] = str_replace( '\\', '/', realpath( $p[ $field ] ) ); }
		if ( ! is_writable( $p['managed_root'] ) || ! is_writable( $p['private_root'] ) || ! is_file( $p['php_binary'] ) || ! is_file( $p['wp_cli'] ) || ! is_file( self::join( $p['wp_source'], 'wp-load.php' ) ) || ! is_file( self::join( $p['ops_source'], 'peptide-ops.php' ) ) || ! is_file( self::join( $p['theme_source'], 'style.css' ) ) || ! empty( $p['php_ini'] ) && ! is_file( $p['php_ini'] ) ) { return self::error( 'dependencies', '部署环境缺少可执行工具、WordPress源码或写入权限。' ); }
		foreach ( array( 'woocommerce', 'peptide-core' ) as $slug_key ) { if ( empty( $p['plugin_sources'][ $slug_key ] ) || ! is_dir( $p['plugin_sources'][ $slug_key ] ) ) { return self::error( 'plugin_source', '部署环境缺少必要店铺插件源码。' ); } }
		$p = self::payment_source( $p );
		if ( is_wp_error( $p ) ) { return $p; }
		if ( empty( $p['db']['host'] ) || empty( $p['db']['user'] ) || ! preg_match( '/^[a-z][a-z0-9_]{1,30}$/D', (string) ( $p['db']['name_prefix'] ?? '' ) ) ) { return self::error( 'database_profile', '部署环境的数据库管理连接或数据库前缀未配置。' ); }
		$p['db']['create_user'] = $p['db']['create_user'] ?? true;
		if ( empty( $p['db']['create_user'] ) && 'local' !== ( $p['environment'] ?? '' ) ) { return self::error( 'database_isolation', '正式部署必须为新店创建独立数据库账号。' ); }
		if ( ! function_exists( 'mysqli_init' ) || ! function_exists( 'proc_open' ) ) { return self::error( 'dependencies', '部署代理需要MySQLi及受控CLI进程执行能力。' ); }
		if ( empty( $p['hq_url'] ) || ! filter_var( $p['hq_url'], FILTER_VALIDATE_URL ) || ! preg_match( '/^[a-f0-9]{64}$/Di', (string) ( $p['hq_secret'] ?? '' ) ) ) { return self::error( 'hq', '部署环境尚未配置管理后台的自动接入信息。' ); }
		$target = self::join( $p['managed_root'], $slug ); $private = self::join( $p['private_root'], $slug );
		if ( ! self::within( $target, $p['managed_root'] ) || ! self::within( $private, $p['private_root'] ) || self::within( $private, self::join( $target, 'public' ) ) || $private === self::join( $target, 'public' ) || self::within( $p['private_root'], $p['wp_source'] ) || $p['private_root'] === $p['wp_source'] || Storage::within( $p['private_root'], ABSPATH ) ) { return self::error( 'target', '部署目标或私有目录不在受控位置。' ); }
		$state = Storage::read_json( 'provision/' . $id . '/state.json', array() );
		$profile_hash = hash( 'sha256', wp_json_encode( $p ) );
		if ( $state && ( ( $state['profile_hash'] ?? '' ) !== $profile_hash || ( $state['root'] ?? '' ) !== $target || ( $state['private'] ?? '' ) !== $private ) ) { return self::error( 'profile_changed', '原任务的受控环境配置已经改变，请核对后建立新任务。' ); }
		foreach ( array( $target, $private ) as $dir ) {
			if ( file_exists( $dir ) && ( ! $state || ! self::owned( $dir, $id ) || ( $state['profile_id'] ?? '' ) !== $profile_id || ( $state['slug'] ?? '' ) !== $slug ) ) { return self::error( 'exists', '目标站点或私有目录已经存在，不会覆盖。' ); }
		}
		$url = trim( (string) ( $in['url'] ?? '' ) );
		if ( '' === $url && ! empty( $in['domain'] ) && 'webserver' === $p['driver'] ) { $url = 'https://' . trim( (string) $in['domain'] ); }
		if ( 'php-server' === $p['driver'] ) {
			if ( 'local' !== ( $p['environment'] ?? '' ) || empty( $p['python_binary'] ) || ! is_file( $p['python_binary'] ) || ! self::ports( $p ) ) { return self::error( 'local_runner', '本机部署环境需要Python、允许端口池和local环境标识。' ); }
			if ( '' !== $url ) {
				$u = wp_parse_url( $url );
				if ( 'http' !== ( $u['scheme'] ?? '' ) || '127.0.0.1' !== ( $u['host'] ?? '' ) || ! in_array( (int) ( $u['port'] ?? 0 ), self::ports( $p ), true ) || ! empty( $u['path'] ) && '/' !== $u['path'] || isset( $u['query'] ) || isset( $u['user'] ) || isset( $u['pass'] ) || isset( $u['fragment'] ) ) { return self::error( 'local_url', '本机站点网址只能使用受控端口池中的127.0.0.1地址。' ); }
				$url = rtrim( $url, '/' );
			}
		} else {
			if ( ! self::allowed_web_url( $url, $p ) ) { return self::error( 'domain', '站点域名必须属于部署环境允许的域名，正式站点使用HTTPS。' ); }
			if ( empty( $p['webroot_ready'] ) && empty( $p['vhost_command'] ) ) { return self::error( 'webserver', '该服务器未配置站点映射或虚拟主机执行器，不能直接部署。' ); }
			if ( ! empty( $p['vhost_command'] ) && empty( $p['vhost_rollback_command'] ) ) { return self::error( 'vhost_rollback', '虚拟主机执行器需要配置对应回收命令。' ); }
			$url = rtrim( $url, '/' );
		}
		$request_hash = hash( 'sha256', wp_json_encode( array( $profile_id, $slug, $title, $login, $email, $url ) ) );
		if ( $state && ( $state['request_hash'] ?? '' ) !== $request_hash ) { return self::error( 'operation_conflict', '同一个任务编号不能修改站点名称、网址或管理员信息。' ); }
		return array( 'operation_id' => $id, 'profile_id' => $profile_id, 'profile' => $p, 'profile_hash' => $profile_hash, 'request_hash' => $request_hash, 'slug' => $slug, 'title' => $title, 'admin_login' => $login, 'admin_email' => $email, 'target' => $target, 'url' => $url );
	}

	/** Resolve code only, never a merchant option or the HQ runtime configuration. */
	private static function payment_source( array $p ): array|\WP_Error {
		$sources = $p['plugin_sources'] ?? array();
		if ( ! is_array( $sources ) ) { return self::error( 'payment_source', '部署环境缺少有效的支付连接组件源码。' ); }
		$explicit = array_key_exists( 'peptide-panapay', $sources );
		$candidates = $explicit ? array( $sources['peptide-panapay'] ) : array();
		if ( ! $explicit ) {
			if ( defined( 'WP_PLUGIN_DIR' ) ) { $candidates[] = self::join( WP_PLUGIN_DIR, 'peptide-panapay' ); }
			if ( is_string( $sources['peptide-core'] ?? null ) ) { $candidates[] = self::join( dirname( $sources['peptide-core'] ), 'peptide-panapay' ); }
		}
		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || ! self::absolute( $candidate ) || str_contains( $candidate, "\0" ) ) { continue; }
			$actual = realpath( $candidate );
			if ( false === $actual || ! is_dir( $actual ) || ! is_readable( $actual ) ) { continue; }
			$entry = self::join( $actual, 'peptide-panapay.php' );
			if ( ! is_file( $entry ) || ! is_readable( $entry ) || is_link( $entry ) ) { continue; }
			$header = get_file_data( $entry, array( 'name' => 'Plugin Name' ), 'plugin' );
			if ( '' === trim( (string) ( $header['name'] ?? '' ) ) ) { continue; }
			$p['plugin_sources']['peptide-panapay'] = str_replace( '\\', '/', $actual );
			return $p;
		}
		return self::error( 'payment_source', '部署环境缺少有效的支付连接组件。请安装 peptide-panapay，或在部署环境 plugin_sources 中配置其完整源码目录。' );
	}

	private static function absolute( string $p ): bool { return str_starts_with( $p, '/' ) || 1 === preg_match( '/^[A-Za-z]:[\\\\\/]/', $p ); }
	private static function allowed_web_url( string $url, array $p ): bool {
		$u = wp_parse_url( $url ); if ( ! $u || empty( $u['host'] ) || ! in_array( $u['scheme'] ?? '', array( 'https', 'http' ), true ) || 'https' !== $u['scheme'] && empty( $p['allow_http'] ) || isset( $u['port'] ) || isset( $u['user'] ) || isset( $u['pass'] ) || isset( $u['query'] ) || isset( $u['fragment'] ) || ! empty( $u['path'] ) && '/' !== $u['path'] ) { return false; }
		$host = strtolower( $u['host'] ); if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host ) || str_contains( $host, '..' ) ) { return false; }
		foreach ( (array) ( $p['allowed_domains'] ?? array() ) as $allowed ) { $allowed = strtolower( (string) $allowed ); if ( $host === $allowed || str_starts_with( $allowed, '*.' ) && self::host_below( $host, substr( $allowed, 2 ) ) ) { return true; } }
		return false;
	}
	private static function host_below( string $host, string $domain ): bool { return $host !== $domain && str_ends_with( $host, '.' . $domain ); }
	private static function ports( array $p ): array { return array_values( array_unique( array_filter( array_map( 'intval', (array) ( $p['ports'] ?? array() ) ), static fn( $n ) => $n >= 1024 && $n <= 65535 ) ) ); }
	private static function database_name( array $plan ): string { return $plan['profile']['db']['name_prefix'] . substr( hash( 'sha256', $plan['operation_id'] ), 0, 18 ); }
	private static function mkdir_owned( string $dir, string $base, string $id ): void {
		if ( ! self::within( $dir, $base ) || is_link( $dir ) ) { throw new \RuntimeException( 'target_ownership' ); }
		if ( file_exists( $dir ) ) { self::assert_owner( $dir, $id ); return; }
		if ( ! mkdir( $dir, 0700 ) ) { throw new \RuntimeException( 'target_create' ); }
		if ( false === file_put_contents( self::join( $dir, self::MARKER ), wp_json_encode( array( 'operation_id' => $id, 'root' => str_replace( '\\', '/', realpath( $dir ) ) ) ), LOCK_EX ) ) { throw new \RuntimeException( 'ownership_write' ); }
	}
	private static function owned( string $dir, string $id ): bool {
		if ( ! is_dir( $dir ) || is_link( $dir ) || ! is_file( self::join( $dir, self::MARKER ) ) ) { return false; }
		// Windows junctions are not consistently reported by is_link(). Owned roots must resolve exactly.
		$actual = str_replace( '\\', '/', (string) realpath( $dir ) ); $expected = rtrim( str_replace( '\\', '/', $dir ), '/' );
		if ( DIRECTORY_SEPARATOR === '\\' ? strtolower( $actual ) !== strtolower( $expected ) : $actual !== $expected ) { return false; }
		$m = json_decode( (string) file_get_contents( self::join( $dir, self::MARKER ) ), true );
		return is_array( $m ) && hash_equals( $id, (string) ( $m['operation_id'] ?? '' ) ) && ( DIRECTORY_SEPARATOR === '\\' ? strtolower( (string) ( $m['root'] ?? '' ) ) === strtolower( $actual ) : ( $m['root'] ?? '' ) === $actual );
	}
	private static function assert_owner( string $dir, string $id ): void { if ( ! self::owned( $dir, $id ) ) { throw new \RuntimeException( 'target_ownership' ); } }

	private static function connect( array $p ): \mysqli {
		$db = $p['db']; $host = (string) $db['host']; $port = (int) ( $db['port'] ?? 3306 );
		if ( preg_match( '/^([^:]+):(\d+)$/D', $host, $m ) ) { $host = $m[1]; $port = (int) $m[2]; }
		$c = mysqli_init();
		if ( ! $c ) { throw new \RuntimeException( 'database_connect' ); }
		$c->options( MYSQLI_OPT_CONNECT_TIMEOUT, 8 );
		if ( ! @$c->real_connect( $host, (string) $db['user'], (string) ( $db['password'] ?? '' ), null, $port ) ) { throw new \RuntimeException( 'database_connect' ); }
		$c->set_charset( 'utf8mb4' ); return $c;
	}
	private static function create_database( array $p, array &$state, array $credentials, string $rel ): void {
		$c = self::connect( $p ); $name = $state['db_name'];
		if ( ! preg_match( '/^[a-z][a-z0-9_]{2,60}$/D', $name ) ) { throw new \RuntimeException( 'database_name' ); }
		try {
			$exists = $c->query( "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" . $c->real_escape_string( $name ) . "'" );
			if ( ! $exists || $exists->num_rows && empty( $state['db_created'] ) ) { throw new \RuntimeException( 'database_exists' ); }
			if ( ! $exists->num_rows ) {
				if ( ! $c->query( 'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ) ) { throw new \RuntimeException( 'database_create' ); }
				$state['db_created'] = true;
				Storage::write_json( $rel . '/state.json', $state );
			}
			$tables = $c->query( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . $c->real_escape_string( $name ) . "'" );
			if ( $tables && 0 === $tables->num_rows ) {
				if ( ! $c->query( 'CREATE TABLE `' . $name . '`.`_peptide_ops_owner` (operation_id CHAR(36) PRIMARY KEY) ENGINE=InnoDB' ) || ! $c->query( "INSERT INTO `" . $name . "`.`_peptide_ops_owner` VALUES ('" . $c->real_escape_string( $state['operation_id'] ) . "')" ) ) { throw new \RuntimeException( 'database_owner' ); }
			} else {
				$owner = $c->query( 'SELECT operation_id FROM `' . $name . '`.`_peptide_ops_owner`' );
				if ( ! $owner || $owner->num_rows !== 1 || ! hash_equals( $state['operation_id'], (string) $owner->fetch_row()[0] ) ) { throw new \RuntimeException( 'database_owner' ); }
			}
			if ( ! empty( $p['db']['create_user'] ) ) {
				$user = 'vc_' . substr( hash( 'sha256', $state['operation_id'] ), 0, 20 ); $account_host = (string) ( $p['db']['account_host'] ?? 'localhost' );
				if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,100}$/D', $account_host ) ) { throw new \RuntimeException( 'database_account_host' ); }
				$account = "'" . $user . "'@'" . $c->real_escape_string( $account_host ) . "'";
				if ( empty( $state['user_created'] ) ) {
					if ( ! $c->query( "CREATE USER " . $account . " IDENTIFIED BY '" . $c->real_escape_string( $credentials['db_password'] ) . "'" ) ) { throw new \RuntimeException( 'database_user_create' ); }
					$state['db_user'] = $user; $state['db_account_host'] = $account_host; $state['user_created'] = true;
					Storage::write_json( $rel . '/state.json', $state );
				} elseif ( $state['db_user'] !== $user || ( $state['db_account_host'] ?? '' ) !== $account_host ) { throw new \RuntimeException( 'database_user_owner' ); }
				if ( ! $c->query( 'GRANT ALL PRIVILEGES ON `' . $name . '`.* TO ' . $account ) ) { throw new \RuntimeException( 'database_grant' ); }
			} else { $state['db_user'] = (string) $p['db']['user']; }
			Storage::write_json( $rel . '/state.json', $state );
		} finally { $c->close(); }
	}

	private static function copy_wordpress( array $p, array $state ): void {
		$public = self::join( $state['root'], 'public' ); if ( ! is_dir( $public ) && ! mkdir( $public, 0755 ) ) { throw new \RuntimeException( 'public_create' ); }
		$core_files = array( 'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php', 'wp-admin', 'wp-includes' );
		foreach ( $core_files as $name ) { $source = self::join( $p['wp_source'], $name ); if ( file_exists( $source ) ) { self::copy_tree( $source, self::join( $public, $name ), $p['wp_source'] ); } }
		foreach ( $p['plugin_sources'] as $slug => $source ) {
			if ( ! in_array( $slug, self::STORE_PLUGINS, true ) ) { continue; }
			self::copy_tree( $source, self::join( $public, 'wp-content/plugins/' . $slug ), (string) realpath( $source ) );
		}
		self::copy_tree( $p['theme_source'], self::join( $public, 'wp-content/themes/peptide-site' ), (string) realpath( $p['theme_source'] ) );
		self::copy_tree( $p['ops_source'], self::join( $public, 'wp-content/plugins/peptide-ops' ), (string) realpath( $p['ops_source'] ) );
		$mu = self::join( $public, 'wp-content/mu-plugins' ); if ( ! is_dir( $mu ) ) { mkdir( $mu, 0755, true ); }
		file_put_contents( self::join( $mu, 'vialcove-operations.php' ), "<?php\ndefined('ABSPATH') || exit;\nrequire_once WP_CONTENT_DIR . '/plugins/peptide-ops/peptide-ops.php';\n" );
	}
	private static function copy_tree( string $source, string $target, string $source_root, array $visited = array() ): void {
		$actual = realpath( $source ); $base = realpath( $source_root );
		if ( false === $actual || false === $base || $actual !== $base && ! self::within( $actual, $base ) || is_link( $source ) ) { throw new \RuntimeException( 'source_link_rejected' ); }
		if ( is_dir( $source ) ) {
			if ( in_array( $actual, $visited, true ) ) { throw new \RuntimeException( 'source_cycle' ); }
			$visited[] = $actual;
			if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) { throw new \RuntimeException( 'copy_directory' ); }
			foreach ( new \DirectoryIterator( $source ) as $item ) { if ( $item->isDot() || in_array( $item->getFilename(), array( '.git', 'node_modules', 'tests', '__pycache__', 'debug.log', '.DS_Store' ), true ) || preg_match( '/\.py[co]$/i', $item->getFilename() ) ) { continue; } self::copy_tree( $item->getPathname(), self::join( $target, $item->getFilename() ), $base, $visited ); }
		} elseif ( is_file( $source ) ) {
			if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0755, true ) ) { throw new \RuntimeException( 'copy_directory' ); }
			if ( ! copy( $source, $target ) ) { throw new \RuntimeException( 'copy_file' ); }
		}
	}
	private static function write_config( array $p, array $state, array $credentials ): void {
		$dbhost = (string) $p['db']['host']; if ( ! str_contains( $dbhost, ':' ) && ! empty( $p['db']['port'] ) ) { $dbhost .= ':' . (int) $p['db']['port']; }
		$constants = array( 'DB_NAME' => $state['db_name'], 'DB_USER' => $state['db_user'], 'DB_PASSWORD' => ! empty( $p['db']['create_user'] ) ? $credentials['db_password'] : (string) ( $p['db']['password'] ?? '' ), 'DB_HOST' => $dbhost, 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_HOME' => $state['url'], 'WP_SITEURL' => $state['url'], 'WP_DEBUG' => false, 'WP_DEBUG_DISPLAY' => false, 'WP_ENVIRONMENT_TYPE' => (string) ( $p['environment'] ?? 'production' ), 'DISALLOW_FILE_EDIT' => true, 'PEPTIDE_CORE_PRIVATE_DIR' => self::join( $state['private'], 'peptide-core' ), 'PEPTIDE_OPS_PRIVATE_DIR' => self::join( $state['private'], 'peptide-ops' ), 'PEPTIDE_HQ_URL' => rtrim( (string) $p['hq_url'], '/' ) . '/', 'PEPTIDE_HQ_SECRET' => $p['hq_secret'], 'PEPTIDE_OPS_SITE_UUID' => $state['uuid'] );
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) { $constants[ $key ] = bin2hex( random_bytes( 32 ) ); }
		$config = "<?php\n// Created by the private VialCove deployment agent.\n";
		foreach ( $constants as $key => $value ) { $config .= 'define(' . var_export( $key, true ) . ', ' . var_export( $value, true ) . ");\n"; }
		$config .= "\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$path = self::join( $state['root'], 'public/wp-config.php' );
		if ( false === file_put_contents( $path, $config, LOCK_EX ) ) { throw new \RuntimeException( 'config_write' ); }
		@chmod( $path, 0600 );
		$ops_private = self::join( $state['private'], 'peptide-ops' );
		if ( ! is_dir( $ops_private ) && ! mkdir( $ops_private, 0700 ) ) { throw new \RuntimeException( 'private_create' ); }
		$tools = (array) ( Storage::config()['tools'] ?? array() );
		$tools['php'] = $p['php_binary']; $tools['wp_cli'] = $p['wp_cli'];
		if ( ! empty( $p['python_binary'] ) ) { $tools['python'] = $p['python_binary']; }
		if ( ! empty( $p['php_ini'] ) ) { $tools['php_ini'] = $p['php_ini']; }
		$config_path = self::join( $ops_private, 'config.json' );
		if ( false === file_put_contents( $config_path, wp_json_encode( array( 'tools' => $tools ) ), LOCK_EX ) ) { throw new \RuntimeException( 'private_config_write' ); }
		@chmod( $config_path, 0600 );
	}
	private static function wp( array $p, array $state, array $args, string $rel ): void {
		$command = array( $p['php_binary'] ); if ( ! empty( $p['php_ini'] ) ) { $command[] = '-c'; $command[] = $p['php_ini']; }
		$command[] = $p['wp_cli']; $command[] = '--path=' . self::join( $state['root'], 'public' ); $command[] = '--no-color';
		$result = Storage::run( array_merge( $command, $args ), array( 'cwd' => $state['root'], 'timeout' => 180, 'env' => array( 'WP_CLI_CACHE_DIR' => Storage::path( 'cli-cache' ), 'PEPTIDE_OPS_PROVISIONING' => '1' ) ) );
		if ( ! is_array( $result ) || ! empty( $result['timed_out'] ) || 0 !== (int) ( $result['code'] ?? 1 ) ) { throw new \RuntimeException( 'wordpress_command' ); }
	}
	private static function activate_store_plugins( array $p, array $state, string $rel ): void {
		foreach ( self::STORE_PLUGINS as $slug ) { self::wp( $p, $state, array( 'plugin', 'activate', $slug, '--quiet' ), $rel ); }
	}
	private static function configure_empty_store( array $p, array $state, array $credentials, string $rel ): void {
		$helper = Storage::path( $rel . '/configure-site.php' );
		$code = '<?php defined("ABSPATH") || exit; ';
		$code .= 'add_filter("pre_wp_mail", static fn() => false, PHP_INT_MAX); ';
		$code .= '$u=get_user_by("login",' . var_export( $credentials['admin_login'], true ) . ');if(!$u){throw new RuntimeException("admin_missing");}wp_set_password(' . var_export( $credentials['admin_password'], true ) . ',$u->ID); ';
		$code .= '$hq=(array)get_option("peptide_core_hq",array());$hq["uuid"]=' . var_export( $state['uuid'], true ) . ';update_option("peptide_core_hq",$hq,false); ';
		$code .= 'update_option("blog_public",0); update_option("woocommerce_coming_soon","yes"); update_option("woocommerce_currency","USD"); update_option("woocommerce_default_country","US"); update_option("woocommerce_allowed_countries","specific"); update_option("woocommerce_specific_allowed_countries",array("US")); update_option("pc_email_campaigns_enabled",false,false); ';
		$code .= '$s=PeptideCore\\Settings::all();$s["brand_name"]=get_bloginfo("name");$s["demo_mode"]=false;$s["demo_notice"]="";update_option("peptide_core_settings",$s,false); ';
		$code .= 'foreach(WC()->payment_gateways()->payment_gateways()as$gateway){$v=(array)get_option("woocommerce_".$gateway->id."_settings",array());$v["enabled"]="no";update_option("woocommerce_".$gateway->id."_settings",$v,false);} ';
		$code .= 'foreach(WC()->mailer()->get_emails()as$email){$v=(array)get_option("woocommerce_".$email->id."_settings",array());$v["enabled"]="no";update_option("woocommerce_".$email->id."_settings",$v,false);} ';
		$code .= 'if(function_exists("wc_create_pages")){wc_create_pages();} update_option("permalink_structure","/%postname%/"); flush_rewrite_rules(false); ';
		$code .= '$template=PeptideCore\\Templates::default_id();if(!$template){throw new RuntimeException("template_missing");}PeptideCore\\Templates::update_info($template,get_bloginfo("name"),"");$home=PeptideCore\\Templates::document_id($template,"home");$mods=PeptideCore\\Modules\\Layout::clean_modules(array(array("block"=>"vialcove/product-collection","attrs"=>array("settings"=>array("title"=>get_bloginfo("name"),"source"=>"catalog","limit"=>8,"emptyText"=>"Products are being prepared. Please check back soon.")))));if(!$home||!$mods){throw new RuntimeException("home_missing");}wp_update_post(array("ID"=>$home,"post_content"=>wp_slash(PeptideCore\\Modules\\Layout::content_from_modules($mods))));PeptideCore\\Modules\\Layout::publish($home); ';
		$code .= 'foreach(array(1,2)as$id){$post=get_post($id);if($post&&in_array($post->post_name,array("hello-world","sample-page"),true)){wp_delete_post($id,true);}} ';
		$code .= 'global $wpdb; if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (\'product\',\'product_variation\',\'pc_batch\',\'pc_report\')")){throw new RuntimeException("not_empty");} echo "Configured empty store.\\n";';
		file_put_contents( $helper, $code, LOCK_EX ); @chmod( $helper, 0600 );
		try { self::wp( $p, $state, array( 'eval-file', $helper ), $rel ); } finally { if ( is_file( $helper ) ) { unlink( $helper ); } }
		$mu = self::join( $state['root'], 'public/wp-content/mu-plugins/vialcove-site-health.php' );
		$health_code = '<?php defined("ABSPATH") || exit; add_action("rest_api_init",static function(){register_rest_route("peptide-ops/v1","/site-identity",array("methods"=>"GET","permission_callback"=>"__return_true","callback"=>static function(){return new WP_REST_Response(array("uuid"=>PEPTIDE_OPS_SITE_UUID,"store_ready"=>class_exists("WooCommerce")&&defined("PEPTIDE_CORE_VERSION")&&defined("PEPTIDE_PANAPAY_FILE")),200,array("Cache-Control"=>"no-store"));}));});';
		file_put_contents( $mu, $health_code, LOCK_EX );
	}

	private static function reserve_port( array $p, string $id, int $requested = 0 ): int|\WP_Error {
		Storage::mkdir( 'provision-ports' );
		foreach ( $requested ? array( $requested ) : self::ports( $p ) as $port ) {
			$rel = 'provision-ports/' . $port . '.json'; $existing = Storage::read_json( $rel, array() );
			if ( $existing && ( $existing['operation_id'] ?? '' ) !== $id ) { continue; }
			$lock = fopen( Storage::path( 'provision-ports/' . $port . '.lock' ), 'c+' );
			if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $lock ) ) { fclose( $lock ); } continue; }
			try {
				$again = Storage::read_json( $rel, array() ); if ( $again && ( $again['operation_id'] ?? '' ) !== $id ) { continue; }
				$socket = @stream_socket_server( 'tcp://127.0.0.1:' . $port, $errno, $message ); if ( ! $socket ) { continue; } fclose( $socket );
				Storage::write_json( $rel, array( 'operation_id' => $id, 'created' => time() ) ); return $port;
			} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
		}
		return self::error( 'ports_busy', '本机部署端口池已用完或端口正被占用。' );
	}
	private static function start_server( array $p, array &$state, string $rel ): void {
		if ( 'php-server' === $p['driver'] ) {
			$server = Storage::read_json( $rel . '/server.json', array() );
			if ( ! $server ) { $server = array( 'operation_id' => $state['operation_id'], 'root' => $state['root'], 'public' => self::join( $state['root'], 'public' ), 'php_binary' => $p['php_binary'], 'php_ini' => $p['php_ini'] ?? '', 'port' => $state['port'], 'log_dir' => Storage::path( $rel ), 'router' => self::join( $state['root'], 'public/wp-content/plugins/peptide-ops/tools/site-router.php' ) ); Storage::write_json( $rel . '/server.json', $server ); }
			$r = Storage::run( array( $p['python_binary'], self::tool( 'site-server.py' ), 'start', Storage::path( $rel . '/server.json' ) ), array( 'timeout' => 15 ) );
			if ( ! is_array( $r ) || 0 !== (int) ( $r['code'] ?? 1 ) ) { throw new \RuntimeException( 'server_start' ); }
			$meta = json_decode( (string) $r['stdout'], true ); if ( ! is_array( $meta ) || empty( $meta['pid'] ) ) { throw new \RuntimeException( 'server_state' ); }
			$state['server_pid'] = (int) $meta['pid'];
		} elseif ( ! empty( $p['vhost_command'] ) ) {
			$state['vhost_started'] = true;
			Storage::write_json( $rel . '/state.json', $state );
			$r = Storage::run( self::vhost_args( $p['vhost_command'], $state ), array( 'timeout' => 60 ) );
			if ( ! is_array( $r ) || 0 !== (int) ( $r['code'] ?? 1 ) ) { throw new \RuntimeException( 'vhost_start' ); }
		}
	}
	private static function tool( string $name ): string { return dirname( __DIR__ ) . '/tools/' . $name; }
	private static function vhost_args( $args, array $state ): array {
		if ( ! is_array( $args ) || ! $args || ! is_string( $args[0] ) || ! self::absolute( $args[0] ) || ! is_file( $args[0] ) ) { throw new \RuntimeException( 'vhost_command' ); }
		$replace = array( '{root}' => self::join( $state['root'], 'public' ), '{host}' => wp_parse_url( $state['url'], PHP_URL_HOST ), '{slug}' => $state['slug'], '{url}' => $state['url'] );
		return array_map( static function ( $arg ) use ( $replace ) { if ( ! is_scalar( $arg ) || str_contains( (string) $arg, "\0" ) ) { throw new \RuntimeException( 'vhost_argument' ); } return strtr( (string) $arg, $replace ); }, $args );
	}
	private static function health( string $url, string $uuid, int $timeout ): array|\WP_Error {
		$until = microtime( true ) + $timeout;
		do {
			$r = wp_remote_get( add_query_arg( 'rest_route', '/peptide-ops/v1/site-identity', rtrim( $url, '/' ) . '/' ), array( 'timeout' => 4, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
			if ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ) { $data = json_decode( wp_remote_retrieve_body( $r ), true ); if ( is_array( $data ) && hash_equals( $uuid, (string) ( $data['uuid'] ?? '' ) ) && ! empty( $data['store_ready'] ) ) { return $data; } }
			usleep( 300000 );
		} while ( microtime( true ) < $until );
		return self::error( 'health', '站点网址没有返回本任务的新站身份，尚未完成部署。' );
	}
	private static function register_store( array $p, array $state, string $rel ): void {
		$helper = Storage::path( $rel . '/register-site.php' );
		$code = '<?php defined("ABSPATH") || exit; $r=PeptideCore\\Hq_Connector::register(); if(is_wp_error($r)){throw new RuntimeException("registration_failed");} $s=PeptideCore\\Hq_Connector::settings();if(empty($s["registered"])||!hash_equals(' . var_export( $state['uuid'], true ) . ',(string)$s["uuid"])){throw new RuntimeException("registration_unverified");}echo "Registered.\\n";';
		file_put_contents( $helper, $code, LOCK_EX ); @chmod( $helper, 0600 );
		try { self::wp( $p, $state, array( 'eval-file', $helper ), $rel ); } finally { if ( is_file( $helper ) ) { unlink( $helper ); } }
	}

	/** Cleanup is confined to ownership markers, deterministic DB names and the exact local server PID. */
	private static function cleanup_owned( array $p, array $state, string $rel ): array {
		$result = array( 'server' => true, 'database' => true, 'database_user' => true, 'root' => true, 'private' => true );
		if ( is_file( Storage::path( $rel . '/server.json' ) ) && 'php-server' === $p['driver'] ) {
			try { $r = Storage::run( array( $p['python_binary'], self::tool( 'site-server.py' ), 'stop', Storage::path( $rel . '/server.json' ) ), array( 'timeout' => 15 ) ); $result['server'] = is_array( $r ) && 0 === (int) ( $r['code'] ?? 1 ); } catch ( \Throwable $e ) { $result['server'] = false; }
		}
		if ( ! empty( $state['vhost_started'] ) ) { try { $r = Storage::run( self::vhost_args( $p['vhost_rollback_command'] ?? array(), $state ), array( 'timeout' => 60 ) ); $result['server'] = is_array( $r ) && 0 === (int) ( $r['code'] ?? 1 ); } catch ( \Throwable $e ) { $result['server'] = false; } }
		if ( ! $result['server'] ) { $result['database'] = false; $result['database_user'] = false; $result['root'] = false; $result['private'] = false; return $result; }
		try {
			if ( ! empty( $state['db_created'] ) || ! empty( $state['user_created'] ) ) {
				$c = self::connect( $p );
				try {
					$expected = (string) $p['db']['name_prefix'] . substr( hash( 'sha256', $state['operation_id'] ), 0, 18 );
					if ( ! hash_equals( $expected, (string) $state['db_name'] ) || ! preg_match( '/^[a-z][a-z0-9_]{2,60}$/D', $expected ) ) { throw new \RuntimeException( 'database_cleanup_owner' ); }
					if ( ! empty( $state['db_created'] ) ) { $result['database'] = (bool) $c->query( 'DROP DATABASE IF EXISTS `' . $expected . '`' ); }
					if ( ! empty( $state['user_created'] ) ) { $user = 'vc_' . substr( hash( 'sha256', $state['operation_id'] ), 0, 20 ); if ( $user !== $state['db_user'] ) { throw new \RuntimeException( 'database_cleanup_owner' ); } $result['database_user'] = (bool) $c->query( "DROP USER IF EXISTS '" . $user . "'@'" . $c->real_escape_string( (string) $state['db_account_host'] ) . "'" ); }
				} finally { $c->close(); }
			}
		} catch ( \Throwable $e ) { $result['database'] = false; $result['database_user'] = false; }
		foreach ( array( 'root' => 'managed_root', 'private' => 'private_root' ) as $kind => $base ) {
			if ( empty( $state[ $kind ] ) || ! file_exists( $state[ $kind ] ) ) { continue; }
			try { if ( ! self::within( $state[ $kind ], $p[ $base ] ) ) { throw new \RuntimeException( 'cleanup_owner' ); } self::assert_owner( $state[ $kind ], $state['operation_id'] ); self::remove_tree( $state[ $kind ], $state[ $kind ] ); } catch ( \Throwable $e ) { $result[ $kind ] = false; }
		}
		if ( ! empty( $state['port'] ) && $result['server'] ) { $r = Storage::read_json( 'provision-ports/' . (int) $state['port'] . '.json', array() ); if ( ( $r['operation_id'] ?? '' ) === $state['operation_id'] ) { unlink( Storage::path( 'provision-ports/' . (int) $state['port'] . '.json' ) ); } }
		if ( is_file( Storage::path( $rel . '/credentials.json' ) ) ) { unlink( Storage::path( $rel . '/credentials.json' ) ); }
		return $result;
	}
	private static function remove_tree( string $path, string $root ): void {
		$actual = realpath( $path ); $base = realpath( $root );
		if ( false === $actual || false === $base || $actual !== $base && ! self::within( $actual, $base ) || is_link( $path ) ) { throw new \RuntimeException( 'cleanup_path' ); }
		if ( is_dir( $path ) ) { foreach ( new \DirectoryIterator( $path ) as $item ) { if ( ! $item->isDot() ) { self::remove_tree( $item->getPathname(), $root ); } } if ( ! rmdir( $path ) ) { throw new \RuntimeException( 'cleanup_directory' ); } }
		elseif ( ! unlink( $path ) ) { throw new \RuntimeException( 'cleanup_file' ); }
	}
}
