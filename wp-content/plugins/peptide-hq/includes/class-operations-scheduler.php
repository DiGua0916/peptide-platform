<?php
namespace PeptideHq;
defined( 'ABSPATH' ) || exit;

/** Local opt-in scheduler. Production uses an administrator-configured system cron. */
final class Operations_Scheduler {
	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'heartbeat' ) );
		add_action( Backup_Schedules::CRON, array( self::class, 'heartbeat' ), 1 );
	}

	public static function heartbeat(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI || ! class_exists( '\PeptideOps\Storage' ) ) { return; }
		if ( get_transient( 'pchq_local_scheduler_checked' ) ) { return; }
		set_transient( 'pchq_local_scheduler_checked', 1, 90 );
		self::ensure();
	}

	public static function ensure() {
		try { return self::ensure_local(); } catch ( \Throwable $error ) { return new \WP_Error( 'pchq_scheduler_config', '本机定时执行器的私有配置不可用。' ); }
	}

	private static function ensure_local() {
		if ( ! class_exists( '\PeptideOps\Storage' ) ) { return new \WP_Error( 'pchq_scheduler_agent', '本机定时执行器尚未安装。' ); }
		$cfg = \PeptideOps\Storage::config();
		if ( true !== ( $cfg['local_scheduler']['enabled'] ?? false ) ) { return array( 'enabled' => false, 'running' => false, 'mode' => 'external_cron' ); }
		$tools = (array) ( $cfg['tools'] ?? array() );
		foreach ( array( 'python', 'php', 'wp_cli' ) as $name ) { if ( empty( $tools[ $name ] ) || ! is_file( $tools[ $name ] ) ) { return new \WP_Error( 'pchq_scheduler_tools', '本机定时执行器缺少受控工具。' ); } }
		$runner = PEPTIDE_OPS_DIR . '/tools/schedule-runner.py'; $tick = PEPTIDE_OPS_DIR . '/tools/tick-schedules.php';
		if ( ! is_file( $runner ) || ! is_file( $tick ) ) { return new \WP_Error( 'pchq_scheduler_files', '本机定时执行器文件不完整。' ); }
		$interval = max( 60, min( 300, (int) ( $cfg['local_scheduler']['interval_seconds'] ?? 120 ) ) );
		\PeptideOps\Storage::mkdir( 'scheduler' );
		$fields = array( 'wp_root' => str_replace( '\\', '/', (string) realpath( ABSPATH ) ), 'config' => \PeptideOps\Storage::path( 'config.json' ), 'python_binary' => $tools['python'], 'php_binary' => $tools['php'], 'php_ini' => $tools['php_ini'] ?? '', 'wp_cli' => $tools['wp_cli'], 'runner' => $runner, 'tick' => $tick, 'interval_seconds' => $interval, 'private_root' => \PeptideOps\Storage::root() );
		$lock = fopen( \PeptideOps\Storage::path( 'scheduler/state.lock' ), 'c+' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) { if ( is_resource( $lock ) ) { fclose( $lock ); } return new \WP_Error( 'pchq_scheduler_lock', '本机定时执行器状态暂时繁忙。' ); }
		try { $state = array_merge( (array) \PeptideOps\Storage::read_json( 'scheduler/runtime.json', array() ), $fields ); \PeptideOps\Storage::write_json( 'scheduler/runtime.json', $state ); } finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
		return self::call( 'ensure', $state );
	}

	public static function status() {
		try { return self::status_local(); } catch ( \Throwable $error ) { return new \WP_Error( 'pchq_scheduler_config', '本机定时执行器的私有配置不可用。' ); }
	}

	private static function status_local() {
		if ( ! class_exists( '\PeptideOps\Storage' ) ) { return array( 'enabled' => false, 'running' => false ); }
		$cfg = \PeptideOps\Storage::config(); $state = \PeptideOps\Storage::read_json( 'scheduler/runtime.json', array() );
		if ( ! $state ) { return array( 'enabled' => true === ( $cfg['local_scheduler']['enabled'] ?? false ), 'running' => false ); }
		return self::call( 'status', $state );
	}

	public static function stop() {
		try { return self::stop_local(); } catch ( \Throwable $error ) { return new \WP_Error( 'pchq_scheduler_config', '本机定时执行器的私有配置不可用。' ); }
	}

	private static function stop_local() {
		if ( ! class_exists( '\PeptideOps\Storage' ) ) { return array( 'stopped' => true ); }
		$state = \PeptideOps\Storage::read_json( 'scheduler/runtime.json', array() );
		return $state ? self::call( 'stop', $state ) : array( 'stopped' => true );
	}

	private static function call( string $action, array $state ) {
		$r = \PeptideOps\Storage::run( array( $state['python_binary'], $state['runner'], $action, \PeptideOps\Storage::path( 'scheduler/runtime.json' ) ), array( 'timeout' => 15 ) );
		$data = json_decode( $r['stdout'], true );
		if ( 0 !== (int) $r['code'] || ! is_array( $data ) || isset( $data['error'] ) ) { return new \WP_Error( 'pchq_scheduler_process', '本机定时执行器未能确认运行状态，请检查私有执行记录。' ); }
		return $data;
	}
}
