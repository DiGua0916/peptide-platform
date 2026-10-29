<?php
namespace PeptideHq;
use PeptideOps\Runtime;
use PeptideOps\Storage;
defined( 'ABSPATH' ) || exit;

/** HQ orchestration. Web requests only enqueue; detached workers contact the stores. */
final class Operations {
	public static function register_hooks(): void {
		add_filter( 'peptide_ops_execute_internal', array( self::class, 'execute' ), 10, 4 );
		Backup_Schedules::register_hooks();
		Operations_Scheduler::register_hooks();
	}
	public static function ready(): bool { try { return class_exists( Runtime::class ) && Runtime::worker_ready(); } catch ( \Throwable $e ) { return false; } }
	public static function target( int $site ) {
		if ( $site > 0 ) {
			$s = Sites::get( $site ); $key = Sites::key( $site );
			return $s && 64 === strlen( $key ) ? array( 'url' => $s['url'], 'key' => $key, 'label' => $s['name'], 'uuid' => $s['uuid'] ) : new \WP_Error( 'pchq_ops_site', '店铺不存在或连接尚未确认。' );
		}
		if ( 0 === $site && defined( 'PEPTIDE_OPS_SERVER_KEY' ) ) { return array( 'url' => home_url( '/' ), 'key' => PEPTIDE_OPS_SERVER_KEY, 'label' => '本机部署代理', 'uuid' => Storage::site_uuid() ); }
		$agents = (array) get_option( 'pchq_ops_agents', array() ); $s = $agents[ abs( $site ) ] ?? null;
		return $s ? array( 'url' => $s['url'], 'key' => Crypto::decrypt( $s['key_enc'] ), 'label' => $s['label'], 'uuid' => $s['uuid'] ?? '' ) : new \WP_Error( 'pchq_ops_agent', '部署代理尚未配置。' );
	}
	public static function agents(): array {
		$out = array( 0 => array( 'label' => '本机部署代理', 'url' => home_url( '/' ) ) );
		foreach ( (array) get_option( 'pchq_ops_agents', array() ) as $id => $a ) { $out[ -(int) $id ] = array( 'label' => $a['label'], 'url' => $a['url'] ); }
		return $out;
	}
	public static function save_agent( array $in ) {
		$url = untrailingslashit( esc_url_raw( $in['url'] ?? '' ) ); $u = wp_parse_url( $url );
		if ( ! $u || 'https' !== ( $u['scheme'] ?? '' ) || empty( $u['host'] ) || ! empty( $u['user'] ) || ! empty( $u['pass'] ) || ! empty( $u['query'] ) || ! empty( $u['fragment'] ) ) { return new \WP_Error( 'pchq_ops_url', '正式代理地址请填写完整 HTTPS 地址，不含账号或查询参数。' ); }
		$id = absint( $in['id'] ?? 0 ); $all = (array) get_option( 'pchq_ops_agents', array() );
		$key = trim( (string) ( $in['key'] ?? '' ) ); if ( '' === $key && isset( $all[$id] ) ) { $key = Crypto::decrypt( $all[$id]['key_enc'] ); }
		if ( ! preg_match( '/^[a-f0-9]{64}$/Di', $key ) ) { return new \WP_Error( 'pchq_ops_key', '需要服务器代理的连接密钥。' ); }
		$check = Client::request_raw( $url, $key, 'GET', '/peptide-ops/v1/status' );
		if ( is_wp_error( $check ) || empty( $check['worker_ready'] ) ) { return new \WP_Error( 'pchq_ops_agent', '代理认证或执行器预检未通过，请先在服务器配置代理及管理后台绑定。' ); }
		if ( ! $id ) { $id = $all ? max( array_map( 'intval', array_keys( $all ) ) ) + 1 : 1; }
		$all[$id] = array( 'label' => sanitize_text_field( $in['label'] ?? '部署服务器' ), 'url' => $url, 'key_enc' => Crypto::encrypt( $key ), 'uuid' => $check['site_uuid'] );
		update_option( 'pchq_ops_agents', $all, false ); return true;
	}
	public static function request( int $site, string $method, string $route, ?array $body = null ) {
		$t = self::target( $site ); return is_wp_error( $t ) ? $t : Client::request_raw( $t['url'], $t['key'], $method, '/peptide-ops/v1' . $route, array(), $body );
	}
	public static function inventory( int $site ) { return 0 === $site ? Runtime::inventory() : self::request( $site, 'GET', '/inventory' ); }
	public static function enqueue( int $site, string $action, array $input, string $id = '' ) {
		if ( ! self::ready() ) { return new \WP_Error( 'pchq_ops_disabled', '管理后台执行器尚未配置。' ); }
		if ( ! in_array( $action, array( 'backup.create', 'backup.restore', 'backup.delete', 'release.apply', 'release.rollback', 'site.provision' ), true ) || ( 'site.provision' === $action && $site > 0 ) || ( 'site.provision' !== $action && $site <= 0 ) ) { return new \WP_Error( 'pchq_ops_scope', '此任务的目标类型不匹配。' ); }
		$t = self::target( $site ); if ( is_wp_error( $t ) ) { return $t; }
		$j = Runtime::submit( 'hq.operation', array( 'target' => $site, 'target_uuid' => $t['uuid'], 'target_url' => $t['url'], 'label' => $t['label'], 'task' => $action, 'data' => $input ), $id, true );
		if ( ! is_wp_error( $j ) && 'scheduled' === ( $input['reason'] ?? '' ) && 'failed' === $j['status'] && empty( $j['result']['manual_recovery_required'] ) ) { $j = Runtime::retry( $j['id'] ); }
		return $j;
	}
	public static function jobs(): array {
		if ( ! self::ready() ) { return array(); }
		try { $out = Runtime::jobs(); } catch ( \Throwable $e ) { return array(); }
		foreach ( $out as &$job ) {
			$raw = Storage::read_json( 'jobs/' . $job['id'] . '.json' );
			$job['label'] = $raw['input']['label'] ?? '';
			$job['task'] = $raw['input']['task'] ?? $job['action'];
			if ( 'scheduled' === ( $raw['input']['data']['reason'] ?? '' ) ) { Backup_Schedules::record_result( (int) $raw['input']['target'], $job['id'], $job ); }
		}
		return $out;
	}
	public static function package_upload( array $file ) {
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] < 1 || (int) $file['size'] > 256 * 1024 * 1024 || 'zip' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) { return new \WP_Error( 'pchq_ops_package', '请选择不超过256MB的插件或主题ZIP。' ); }
		$id = hash_file( 'sha256', $file['tmp_name'] ); Storage::mkdir( 'packages' );
		if ( ! move_uploaded_file( $file['tmp_name'], Storage::path( 'packages/' . $id . '.zip' ) ) ) { return new \WP_Error( 'pchq_ops_package', '无法保存升级包。' ); }
		$inspect = \PeptideOps\Releases::inspect( $id, false );
		if ( is_wp_error( $inspect ) ) { @unlink( Storage::path( 'packages/' . $id . '.zip' ) ); return $inspect; }
		Storage::write_json( 'packages/' . $id . '.json', array( 'name' => sanitize_file_name( $file['name'] ), 'sha256' => $id, 'bytes' => (int) $file['size'], 'complete' => true, 'inspect' => $inspect ) );
		return array( 'id' => $id, 'inspect' => $inspect );
	}
	private static function transfer( int $site, string $id, callable $progress ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $id ) ) { return new \WP_Error( 'pchq_ops_package', '无效升级包。' ); }
		$path = Storage::path( 'packages/' . $id . '.zip' );
		if ( ! is_file( $path ) || ! hash_equals( $id, hash_file( 'sha256', $path ) ) ) { return new \WP_Error( 'pchq_ops_package', '升级包不存在或完整性校验失败。' ); }
		$size = filesize( $path ); $chunks = (int) ceil( $size / 524288 ); $fh = fopen( $path, 'rb' );
		try { for ( $i = 0; $i < $chunks; ++$i ) {
			$r = self::request( $site, 'POST', '/packages', array( 'sha256' => $id, 'index' => $i, 'chunks' => $chunks, 'bytes' => $size, 'name' => 'release.zip', 'data' => base64_encode( fread( $fh, 524288 ) ) ) );
			if ( is_wp_error( $r ) ) { return $r; } $progress( 5 + (int) ( 20 * ( $i + 1 ) / $chunks ), 'upload', '传送并校验升级包。' );
		} } finally { fclose( $fh ); }
		return true;
	}
	public static function execute( $old, string $action, array $in, callable $progress ) {
		if ( 'hq.operation' !== $action ) { return $old; }
		$site = (int) $in['target']; $id = $in['operation_id']; $t = self::target( $site );
		if ( is_wp_error( $t ) ) { return $t; }
		if ( $t['uuid'] !== $in['target_uuid'] || $t['url'] !== $in['target_url'] ) { return new \WP_Error( 'pchq_ops_target_changed', '排队后目标连接发生变化，请核对后重新提交。' ); }
		// A local deployment task runs in this CLI, not through a second manager job holding the same lock.
		if ( 0 === $site && 'site.provision' === $in['task'] ) { $data = $in['data']; $data['operation_id'] = $id; return self::verify_provision( \PeptideOps\Provisioner::create( $data, $progress ) ); }
		$progress( 2, 'preflight', '连接目标并检查运维代理。' );
		$status = self::request( $site, 'GET', '/status' );
		if ( is_wp_error( $status ) || empty( $status['worker_ready'] ) ) { return is_wp_error( $status ) ? $status : new \WP_Error( 'pchq_ops_worker', '目标后台执行器未准备好。' ); }
		if ( ( $status['site_uuid'] ?? '' ) !== $in['target_uuid'] ) { return new \WP_Error( 'pchq_ops_uuid', '目标站点身份不匹配，已停止操作。' ); }
		if ( 'release.apply' === $in['task'] ) { $r = self::transfer( $site, (string) ( $in['data']['package_id'] ?? '' ), $progress ); if ( is_wp_error( $r ) ) { return $r; } }
		$job = self::request( $site, 'POST', '/jobs', array( 'action' => $in['task'], 'input' => $in['data'], 'operation_id' => $id ) );
		if ( is_wp_error( $job ) ) { return $job; }
		if ( 'failed' === ( $job['status'] ?? '' ) && empty( $job['result']['manual_recovery_required'] ) ) { $job = self::request( $site, 'POST', '/jobs/' . $id . '/retry', array() ); if ( is_wp_error( $job ) ) { return $job; } }
		$until = time() + 2400;
		do {
			if ( ! is_wp_error( $job ) ) {
				if ( 'succeeded' === ( $job['status'] ?? '' ) ) { $result = (array) ( $job['result'] ?? array() ); $retention = self::retention( $site, $in, $progress ); if ( $retention ) { $result['retention'] = $retention; if ( ! empty( $retention['warning'] ) ) { $result['warnings'] = array_merge( $result['warnings'] ?? array(), array( $retention['warning'] ) ); } } return 'site.provision' === $in['task'] ? self::verify_provision( $result ) : $result; }
				if ( 'failed' === ( $job['status'] ?? '' ) ) { return new \WP_Error( $job['error_code'] ?? 'pchq_ops_remote', $job['message'] ?? '目标任务失败。', $job['result'] ?? array() ); }
				$progress( 25 + (int) ( .7 * (int) ( $job['progress'] ?? 0 ) ), $job['phase'] ?? 'remote', $job['message'] ?? '目标正在执行。' );
			} elseif ( 'pchq_offline' !== $job->get_error_code() ) { return $job; }
			sleep( 2 ); $job = self::request( $site, 'GET', '/jobs/' . $id );
		} while ( time() < $until );
		return new \WP_Error( 'pchq_ops_timeout', '等待目标超时，远端任务可能仍在执行。重试会查询原任务，不会新建重复操作。', array( 'remote_operation_id' => $id ) );
	}
	private static function verify_provision( $result ) {
		if ( is_wp_error( $result ) ) { return $result; }
		foreach ( Sites::all() as $site ) {
			if ( $site['uuid'] !== ( $result['uuid'] ?? '' ) ) { continue; }
			$status = Client::status( $site['id'] );
			if ( ! is_wp_error( $status ) && ( $status['uuid'] ?? '' ) === $site['uuid'] ) { $result['hq_connection_verified'] = true; $result['site_id'] = $site['id']; return $result; }
		}
		return new \WP_Error( 'pchq_ops_registration', '新店已创建，但管理后台的回连核验未通过。请核对原任务和连接，不要重复建店。', array( 'uuid' => $result['uuid'] ?? '', 'url' => $result['url'] ?? '' ) );
	}
	private static function retention( int $site, array $in, callable $progress ): array {
		if ( 'backup.create' !== $in['task'] || 'scheduled' !== ( $in['data']['reason'] ?? '' ) ) { return array(); }
		$list = self::inventory( $site ); if ( is_wp_error( $list ) ) { return array( 'applied' => false, 'warning' => '本次备份已完成，旧备份列表暂不可读，尚未清理过期定时备份。' ); }
		$items = array_values( array_filter( $list['backups'] ?? array(), static fn( $b ) => 'scheduled' === ( $b['reason'] ?? '' ) && empty( $b['protected'] ) ) );
		usort( $items, static fn( $a, $b ) => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );
		$removed = 0; $failed = 0;
		foreach ( array_slice( $items, max( 2, (int) ( $in['data']['retention'] ?? 7 ) ) ) as $b ) {
			$h = substr( hash( 'sha256', 'retention:' . $in['operation_id'] . ':' . $b['id'] ), 0, 32 );
			$delete_id = substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-5' . substr( $h, 13, 3 ) . '-a' . substr( $h, 17, 3 ) . '-' . substr( $h, 20 );
			$progress( 97, 'retention', '备份已完成，正在核对旧定时备份清理结果。' );
			$r = self::request( $site, 'POST', '/jobs', array( 'action' => 'backup.delete', 'input' => array( 'backup_id' => $b['id'] ), 'operation_id' => $delete_id ) );
			$until = time() + 180;
			while ( ! is_wp_error( $r ) && in_array( $r['status'] ?? '', array( 'queued', 'waiting', 'running' ), true ) && time() < $until ) { sleep( 1 ); $r = self::request( $site, 'GET', '/jobs/' . $delete_id ); }
			if ( ! is_wp_error( $r ) && 'succeeded' === ( $r['status'] ?? '' ) ) { ++$removed; } else { ++$failed; }
		}
		return array( 'applied' => 0 === $failed, 'removed' => $removed, 'failed' => $failed, 'warning' => $failed ? '本次备份已完成，部分旧定时备份未清理；请查看店铺记录或保留恢复点占用情况。' : '' );
	}
}
