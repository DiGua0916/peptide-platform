<?php
/** Managed, file-level plugin/theme releases with private rollback points. */
namespace PeptideOps;

defined( 'ABSPATH' ) || exit;

final class Releases {

	private const PROTECTED = array( 'peptide-ops', 'peptide-ops-agent' );

	public static function inventory(): array {
		self::wp_files(); $items = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( $file ); $valid = self::target( 'plugin', $slug );
			$items[] = array( 'type' => 'plugin', 'slug' => $slug, 'entry' => $file, 'name' => (string) $data['Name'], 'version' => (string) $data['Version'], 'active' => is_plugin_active( $file ), 'eligible' => ! is_wp_error( $valid ), 'reason' => is_wp_error( $valid ) ? $valid->get_error_message() : '' );
		}
		foreach ( wp_get_themes() as $slug => $theme ) {
			$valid = self::target( 'theme', $slug );
			$items[] = array( 'type' => 'theme', 'slug' => $slug, 'entry' => $slug . '/style.css', 'name' => (string) $theme->get( 'Name' ), 'version' => (string) $theme->get( 'Version' ), 'active' => get_stylesheet() === $slug || get_template() === $slug, 'eligible' => ! is_wp_error( $valid ), 'reason' => is_wp_error( $valid ) ? $valid->get_error_message() : '' );
		}
		return $items;
	}

	/** Only an uploaded, content-addressed private package can be inspected. */
	public static function inspect( string $package_id, bool $check_runtime = true ) {
		try {
			if ( ! preg_match( '/^[a-f0-9]{64}$/D', $package_id ) ) { return self::error( 'package_id', '升级包标识无效。' ); }
			$file = Storage::path( 'packages/' . $package_id . '.zip' );
			if ( ! is_file( $file ) || ! hash_equals( $package_id, (string) hash_file( 'sha256', $file ) ) ) { return self::error( 'package_hash', '升级包不存在或校验值不一致。' ); }
			$info = Archive::inspect( $file, array( 'require_single_root' => true ) );
			if ( is_wp_error( $info ) ) { return $info; }
			$slug = (string) $info['top_level'][0];
			if ( ! self::slug( $slug ) || in_array( $slug, self::PROTECTED, true ) ) { return self::error( 'protected', '此目录不允许通过普通升级入口替换。' ); }
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $file, \ZipArchive::RDONLY ) ) { return self::error( 'package_open', '无法读取升级包。' ); }
			$candidates = array();
			try {
				foreach ( $info['entries'] as $entry ) {
					if ( $entry['directory'] || $entry['ignored'] ) { continue; }
					$name = $entry['name'];
					if ( $name === $slug . '/style.css' ) {
						$data = self::headers( (string) $zip->getFromName( $entry['source_name'], 8192 ), true );
						if ( '' !== $data['name'] ) { $candidates[] = array_merge( $data, array( 'type' => 'theme', 'main' => 'style.css' ) ); }
					} elseif ( preg_match( '~^' . preg_quote( $slug, '~' ) . '/[^/]+\.php$~i', $name ) ) {
						$data = self::headers( (string) $zip->getFromName( $entry['source_name'], 8192 ), false );
						if ( '' !== $data['name'] ) { $candidates[] = array_merge( $data, array( 'type' => 'plugin', 'main' => basename( $name ) ) ); }
					}
				}
			} finally { $zip->close(); }
			if ( 1 !== count( $candidates ) ) { return self::error( 'package_header', '升级包必须包含且只包含一个可识别的插件或主题主入口。' ); }
			$data = $candidates[0];
			if ( ! preg_match( '/^[A-Za-z0-9._+\-]{1,80}$/D', $data['version'] ) ) { return self::error( 'package_version', '升级包需要有效的版本号。' ); }
			foreach ( array( 'requires_wp', 'requires_php' ) as $field ) { if ( '' !== $data[ $field ] && ! preg_match( '/^\d+(?:\.\d+){0,3}$/D', $data[ $field ] ) ) { return self::error( 'requirements', '升级包的运行环境版本声明无效。' ); } }
			if ( '' !== $data['template'] && ! self::slug( $data['template'] ) ) { return self::error( 'theme_parent', '升级包的父主题声明无效。' ); }
			foreach ( array_filter( array_map( 'trim', explode( ',', $data['requires_plugins'] ) ) ) as $dependency ) { if ( ! self::slug( $dependency ) ) { return self::error( 'plugin_dependency', '插件依赖声明无效。' ); } }
			if ( $check_runtime ) {
				$compatible = self::compatible( $data ); if ( is_wp_error( $compatible ) ) { return $compatible; }
				$target = self::target( $data['type'], $slug ); if ( is_wp_error( $target ) ) { return $target; }
				$installed = self::installed( $data['type'], $slug );
				if ( ! $installed || $installed['main'] !== $data['main'] ) { return self::error( 'package_entry', '目标未安装或主入口发生变化，不能按普通升级替换。' ); }
				$data['installed_version'] = $installed['version']; $data['update_available'] = version_compare( $data['version'], $installed['version'], '>' );
			}
			return array_merge( $data, array( 'package_id' => $package_id, 'slug' => $slug, 'files' => $info['files'], 'bytes' => $info['bytes'], 'sha256' => $info['sha256'], 'file_rollback_only' => true, 'database_note' => '回滚点恢复所选插件或主题文件。插件自行执行的数据迁移需单独使用整站备份恢复。' ) );
		} catch ( \Throwable $e ) { return self::error( 'package_read', '升级包检查失败，请检查私有存储和执行环境。' ); }
	}

	/** Public summaries never expose storage locations, credentials or file contents. */
	public static function history(): array {
		try {
			$rows = array(); $dir = Storage::path( 'releases' );
			foreach ( is_dir( $dir ) ? (array) glob( $dir . '/*/manifest.json' ) : array() as $file ) {
				$id = basename( dirname( $file ) ); if ( ! self::uuid( $id ) ) { continue; }
				$record = self::record( $id ); if ( is_wp_error( $record ) ) { continue; }
				$rows[] = self::public_record( $record );
			}
			usort( $rows, static fn( $a, $b ) => $b['created'] <=> $a['created'] );
			return $rows;
		} catch ( \Throwable $e ) { return array(); }
	}

	public static function apply( array $input, callable $progress ) {
		$lock = null; $record = null; $target = null; $swapped = false; $old = ''; $new = ''; $maintenance = null; $stage_owned = false; $new_owned = false;
		try {
			$id = (string) ( $input['operation_id'] ?? '' );
			if ( ! self::uuid( $id ) ) { return self::error( 'operation', '升级任务标识无效。' ); }
			$lock = self::lock(); if ( is_wp_error( $lock ) ) { return $lock; }
			$package = self::inspect( (string) ( $input['package_id'] ?? '' ) ); if ( is_wp_error( $package ) ) { return $package; }
			$type = (string) ( $input['type'] ?? '' ); $slug = (string) ( $input['slug'] ?? '' );
			if ( $package['type'] !== $type || $package['slug'] !== $slug ) { return self::error( 'package_target', '升级包类型和目录必须与选中的已安装目标一致。' ); }
			$target = self::target( $type, $slug ); if ( is_wp_error( $target ) ) { return $target; }
			$installed = self::installed( $type, $slug );
			if ( ! $installed || ( 'plugin' === $type && $installed['main'] !== $package['main'] ) ) { return self::error( 'package_entry', '主入口发生变化，不能按普通版本升级替换。' ); }
			if ( is_file( Storage::path( 'releases/' . $id . '/manifest.json' ) ) ) {
				$existing = self::record( $id );
				if ( is_wp_error( $existing ) ) { return $existing; }
				if ( $existing['package_id'] !== $package['package_id'] || $existing['type'] !== $type || $existing['slug'] !== $slug ) { return self::error( 'operation_conflict', '同一任务标识已用于其他升级。' ); }
				return 'applied' === $existing['status'] ? self::public_record( $existing ) : self::error( 'release_incomplete', '上次升级未完整结束，请先检查任务并使用已保留的回滚点。', array( 'rollback_id' => $id, 'manual_recovery_required' => true ) );
			}
			if ( ! version_compare( $package['version'], $installed['version'], '>' ) ) { return self::error( 'package_version', '升级包版本必须高于当前版本；降级请使用已验证的回滚点。' ); }
			$progress( 8, 'preflight', '正在检查目标、升级包和运行环境。' );
			$tools = self::tools(); if ( is_wp_error( $tools ) ) { return $tools; }
			$space = disk_free_space( $target['root'] );
			if ( false !== $space && $space < $package['bytes'] * 3 + 33554432 ) { return self::error( 'release_space', '目标磁盘剩余空间不足，已停止升级。' ); }
			Storage::mkdir( 'release-stage' );
			$stage = Storage::path( 'release-stage/' . $id );
			if ( file_exists( $stage ) ) { return self::error( 'release_stage', '该任务存在未清理的暂存目录，请先检查未完成任务。' ); }
			$stage_owned = true;
			$extracted = Archive::extract( Storage::path( 'packages/' . $package['package_id'] . '.zip' ), $stage, array( 'require_single_root' => true ) );
			if ( is_wp_error( $extracted ) ) { return $extracted; }
			$source = $stage . '/' . $slug;
			$lint = self::lint( $source, $tools, $progress ); if ( is_wp_error( $lint ) ) { return $lint; }
			$baseline = self::health( $tools ); if ( is_wp_error( $baseline ) ) { return self::error( 'baseline_health', '当前站点未通过启动检查，请先排除现有故障再升级。' ); }
			if ( ! class_exists( Backups::class ) ) { return self::error( 'backup_missing', '整站备份服务未就绪，不能开始升级。' ); }
			$progress( 28, 'backup', '正在创建升级前整站备份。' );
			$backup = Backups::create( array( 'operation_id' => self::derived_id( $id, 'backup' ), 'reason' => 'pre_upgrade', 'label' => '升级前自动备份' ), static function ( $percent, $phase, $message ) use ( $progress ) { $progress( 28 + (int) ( min( 100, max( 0, $percent ) ) * .25 ), 'backup', $message ); } );
			if ( is_wp_error( $backup ) ) { return $backup; }
			Storage::mkdir( 'releases/' . $id );
			$snapshot = Archive::create_tree( $target['path'], Storage::path( 'releases/' . $id . '/files.zip' ), $slug, array(), array( 'allow_sensitive' => true, 'ignore_junk' => false ) );
			if ( is_wp_error( $snapshot ) ) { return $snapshot; }
			$record = array( 'id' => $id, 'site_uuid' => Storage::site_uuid(), 'type' => $type, 'slug' => $slug, 'name' => $installed['name'], 'main' => $installed['main'], 'from_version' => $installed['version'], 'to_version' => $package['version'], 'package_id' => $package['package_id'], 'created' => time(), 'status' => 'prepared', 'backup_id' => (string) ( $backup['id'] ?? '' ), 'snapshot_sha256' => $snapshot['sha256'], 'snapshot_manifest' => $snapshot['manifest'], 'active' => $installed['active'] );
			self::save( $record );
			$progress( 62, 'stage', '升级前回滚点已保存，正在准备新版本。' );
			$new = $target['root'] . '/.pc-ops-new-' . $id; $old = $target['root'] . '/.pc-ops-old-' . $id;
			if ( file_exists( $new ) || file_exists( $old ) ) { return self::error( 'release_stage', '目标旁边存在未完成的版本交换，请先恢复。', array( 'rollback_id' => $id ) ); }
			$new_owned = true;
			self::copy_tree( $source, $new );
			$maintenance = self::maintenance( $id ); if ( is_wp_error( $maintenance ) ) { return $maintenance; }
			if ( self::tree_manifest( $target['path'] ) !== $record['snapshot_manifest'] ) { throw new \RuntimeException( 'target_changed' ); }
			$record['status'] = 'swapping'; self::save( $record );
			if ( ! rename( $target['path'], $old ) ) { throw new \RuntimeException( 'old_rename' ); }
			if ( ! rename( $new, $target['path'] ) ) { rename( $old, $target['path'] ); throw new \RuntimeException( 'new_rename' ); }
			$swapped = true; $record['status'] = 'checking'; self::save( $record );
			self::invalidate( $target['path'] ); self::end_maintenance( $maintenance ); $maintenance = null;
			$progress( 82, 'health', '新版本文件已替换，正在检查站点启动状态。' );
			$health = self::health( $tools );
			if ( is_wp_error( $health ) ) { throw new \RuntimeException( 'health_failed' ); }
			$installed_after = self::installed( $type, $slug );
			if ( ! $installed_after || $installed_after['version'] !== $package['version'] ) { throw new \RuntimeException( 'version_failed' ); }
			$record['status'] = 'applied'; $record['finished'] = time(); self::save( $record );
			self::remove_tree( $old, $target['root'] ); $old = '';
			$progress( 100, 'complete', '升级成功，旧版本文件和整站备份均已保留。' );
			return self::public_record( $record );
		} catch ( \Throwable $e ) {
			$recovered = false;
			if ( is_array( $target ) && '' !== $old && is_dir( $old ) ) {
				try { if ( is_dir( $target['path'] ) ) { self::remove_tree( $target['path'], $target['root'], true ); } $recovered = rename( $old, $target['path'] ); if ( $recovered ) { $old = ''; self::invalidate( $target['path'] ); } } catch ( \Throwable $ignored ) {}
			}
			if ( is_array( $record ) ) { $record['status'] = $recovered ? 'failed_reverted' : ( $swapped || ( '' !== $old && is_dir( $old ) ) ? 'recovery_required' : 'failed_preflight' ); $record['finished'] = time(); try { self::save( $record ); } catch ( \Throwable $ignored ) {} }
			return self::error( 'release_failed', $recovered ? '升级检查失败，已恢复原版本文件。插件自行进行的数据迁移请核对升级前整站备份。' : '升级未完成，已保留回滚点，请检查任务后恢复。', array( 'rollback_id' => $record['id'] ?? '', 'files_reverted' => $recovered, 'manual_recovery_required' => ! $recovered && ( $swapped || ( '' !== $old && is_dir( $old ) ) ) ) );
		} finally {
			self::end_maintenance( $maintenance );
			if ( $new_owned && '' !== $new && is_dir( $new ) && is_array( $target ) ) { try { self::remove_tree( $new, $target['root'] ); } catch ( \Throwable $ignored ) {} }
			if ( $stage_owned && isset( $stage ) && is_dir( $stage ) ) { try { self::remove_tree( $stage, Storage::root() ); } catch ( \Throwable $ignored ) {} }
			self::unlock( $lock );
		}
	}

	public static function rollback( array $input, callable $progress ) {
		$lock = null; $maintenance = null; $target = null; $previous = ''; $new = ''; $swapped = false; $stage_owned = false; $new_owned = false;
		try {
			$id = (string) ( $input['operation_id'] ?? '' ); $point = (string) ( $input['rollback_id'] ?? '' );
			if ( ! self::uuid( $id ) || ! self::uuid( $point ) || $id === $point ) { return self::error( 'rollback_id', '回滚任务或回滚点标识无效。' ); }
			$lock = self::lock(); if ( is_wp_error( $lock ) ) { return $lock; }
			$record = self::record( $point ); if ( is_wp_error( $record ) ) { return $record; }
			$existing = Storage::read_json( 'rollback-events/' . $id . '.json', null );
			if ( is_array( $existing ) ) {
				if ( ( $existing['point_id'] ?? '' ) !== $point || ( $existing['site_uuid'] ?? '' ) !== Storage::site_uuid() ) { return self::error( 'operation_conflict', '同一任务标识已用于其他回滚。' ); }
				if ( 'rolled_back' === ( $existing['status'] ?? '' ) ) { return array_merge( self::public_record( $record ), array( 'rollback_operation_id' => $id, 'rollback_backup_id' => $existing['backup_id'] ?? '', 'rolled_back' => true ) ); }
				return self::error( 'rollback_incomplete', '该回滚任务已执行但未完整结束，请检查任务记录。', array( 'manual_recovery_required' => true ) );
			}
			$target = self::target( $record['type'], $record['slug'], true ); if ( is_wp_error( $target ) ) { return $target; }
			$file = Storage::path( 'releases/' . $point . '/files.zip' );
			if ( ! is_file( $file ) || ! hash_equals( $record['snapshot_sha256'], (string) hash_file( 'sha256', $file ) ) ) { return self::error( 'rollback_hash', '回滚点文件校验失败，不能使用。' ); }
			$tools = self::tools(); if ( is_wp_error( $tools ) ) { return $tools; }
			$progress( 8, 'preflight', '正在校验回滚点与站点身份。' );
			Storage::mkdir( 'release-stage' ); $stage = Storage::path( 'release-stage/' . $id );
			if ( file_exists( $stage ) ) { return self::error( 'release_stage', '回滚任务暂存目录已存在，请先检查。' ); }
			$stage_owned = true;
			$extracted = Archive::extract( $file, $stage, array( 'require_single_root' => true, 'allow_sensitive' => true, 'ignore_junk' => false ) ); if ( is_wp_error( $extracted ) ) { return $extracted; }
			$source = $stage . '/' . $record['slug'];
			if ( self::tree_manifest( $source ) !== $record['snapshot_manifest'] ) { return self::error( 'rollback_hash', '回滚点中的文件清单与原记录不一致。' ); }
			if ( is_dir( $target['path'] ) && self::tree_manifest( $target['path'] ) === $record['snapshot_manifest'] ) { return array_merge( self::public_record( $record ), array( 'already_restored' => true, 'rollback_operation_id' => $id ) ); }
			if ( ! class_exists( Backups::class ) ) { return self::error( 'backup_missing', '整站备份服务未就绪，不能开始回滚。' ); }
			$progress( 25, 'backup', '正在创建回滚前整站备份。' );
			$backup = Backups::create( array( 'operation_id' => self::derived_id( $id, 'backup' ), 'reason' => 'rollback', 'label' => '回滚前自动备份' ), static function ( $percent, $phase, $message ) use ( $progress ) { $progress( 25 + (int) ( min( 100, max( 0, $percent ) ) * .25 ), 'backup', $message ); } );
			if ( is_wp_error( $backup ) ) { return $backup; }
			Storage::mkdir( 'rollback-events' ); $event = array( 'id' => $id, 'point_id' => $point, 'site_uuid' => Storage::site_uuid(), 'created' => time(), 'status' => 'prepared', 'backup_id' => $backup['id'] ?? '' ); Storage::write_json( 'rollback-events/' . $id . '.json', $event );
			$new = $target['root'] . '/.pc-ops-new-' . $id; $previous = $target['root'] . '/.pc-ops-old-' . $id;
			if ( file_exists( $new ) || file_exists( $previous ) ) { return self::error( 'release_stage', '回滚交换目录已存在，已停止操作。' ); }
			$new_owned = true;
			self::copy_tree( $source, $new ); $maintenance = self::maintenance( $id ); if ( is_wp_error( $maintenance ) ) { return $maintenance; }
			if ( is_dir( $target['path'] ) && ! rename( $target['path'], $previous ) ) { throw new \RuntimeException( 'old_rename' ); }
			if ( ! rename( $new, $target['path'] ) ) { if ( is_dir( $previous ) ) { rename( $previous, $target['path'] ); } throw new \RuntimeException( 'new_rename' ); }
			$swapped = true; self::invalidate( $target['path'] ); self::end_maintenance( $maintenance ); $maintenance = null;
			$progress( 80, 'health', '已恢复回滚点文件，正在检查站点启动状态。' );
			$health = self::health( $tools ); if ( is_wp_error( $health ) ) { throw new \RuntimeException( 'health_failed' ); }
			$event['status'] = 'rolled_back'; $event['finished'] = time(); Storage::write_json( 'rollback-events/' . $id . '.json', $event );
			$record['last_rollback'] = $id; $record['restored_at'] = time(); self::save( $record );
			if ( is_dir( $previous ) ) { self::remove_tree( $previous, $target['root'] ); $previous = ''; }
			$progress( 100, 'complete', '所选插件或主题文件已回滚，回滚前整站备份已保留。' );
			return array_merge( self::public_record( $record ), array( 'rollback_operation_id' => $id, 'rollback_backup_id' => $backup['id'] ?? '', 'rolled_back' => true ) );
		} catch ( \Throwable $e ) {
			$recovered = false;
			if ( is_array( $target ) && '' !== $previous && is_dir( $previous ) ) {
				try { if ( is_dir( $target['path'] ) ) { self::remove_tree( $target['path'], $target['root'], true ); } $recovered = rename( $previous, $target['path'] ); if ( $recovered ) { $previous = ''; self::invalidate( $target['path'] ); } } catch ( \Throwable $ignored ) {}
			}
			if ( isset( $event ) ) { $event['status'] = $recovered ? 'failed_reverted' : 'recovery_required'; try { Storage::write_json( 'rollback-events/' . $id . '.json', $event ); } catch ( \Throwable $ignored ) {} }
			return self::error( 'rollback_failed', $recovered ? '回滚检查失败，已恢复回滚前文件。' : '回滚未完整结束，请检查已保留的备份和任务记录。', array( 'files_reverted' => $recovered, 'rollback_id' => $point ?? '', 'manual_recovery_required' => ! $recovered && ( $swapped || ( '' !== $previous && is_dir( $previous ) ) ) ) );
		} finally {
			self::end_maintenance( $maintenance );
			if ( $new_owned && '' !== $new && is_dir( $new ) && is_array( $target ) ) { try { self::remove_tree( $new, $target['root'] ); } catch ( \Throwable $ignored ) {} }
			if ( $stage_owned && isset( $stage ) && is_dir( $stage ) ) { try { self::remove_tree( $stage, Storage::root() ); } catch ( \Throwable $ignored ) {} }
			self::unlock( $lock );
		}
	}

	private static function wp_files(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
	}

	private static function installed( string $type, string $slug ): ?array {
		self::wp_files();
		if ( 'plugin' === $type ) {
			foreach ( get_plugins() as $file => $data ) { if ( dirname( $file ) === $slug ) { return array( 'name' => $data['Name'], 'version' => $data['Version'], 'main' => basename( $file ), 'active' => is_plugin_active( $file ) ); } }
		} else {
			$theme = wp_get_theme( $slug ); if ( $theme->exists() ) { return array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'main' => 'style.css', 'active' => get_stylesheet() === $slug || get_template() === $slug ); }
		}
		return null;
	}

	private static function target( string $type, string $slug, bool $allow_missing = false ) {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! self::slug( $slug ) || in_array( $slug, self::PROTECTED, true ) ) { return self::error( 'target', '升级目标无效或属于受保护的运维代理。' ); }
		$base = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root( $slug ); $root = realpath( $base );
		if ( ! $root || ! is_dir( $root ) || ! is_writable( $root ) ) { return self::error( 'target_root', '插件或主题目录不可写。' ); }
		$root = str_replace( '\\', '/', $root ); $path = $root . '/' . $slug;
		if ( is_link( $path ) || ( file_exists( $path ) && ( ! is_dir( $path ) || ! self::same_path( (string) realpath( $path ), $path ) ) ) ) { return self::error( 'target_link', '此目标是开发链接或目录联接，普通升级已禁用，避免覆盖开发工作树。' ); }
		if ( ! $allow_missing && ! is_dir( $path ) ) { return self::error( 'target_missing', '目标尚未安装，不能使用版本升级入口。' ); }
		if ( ! Storage::within( $path, $root ) ) { return self::error( 'target', '升级目标目录越界。' ); }
		if ( defined( 'PEPTIDE_OPS_DIR' ) && file_exists( $path ) && self::same_path( (string) realpath( $path ), (string) realpath( PEPTIDE_OPS_DIR ) ) ) { return self::error( 'protected', '运维代理不能通过此入口替换。' ); }
		return array( 'root' => $root, 'path' => $path, 'slug' => $slug, 'type' => $type );
	}

	private static function headers( string $source, bool $theme ): array {
		$headers = array( 'name' => $theme ? 'Theme Name' : 'Plugin Name', 'version' => 'Version', 'requires_wp' => 'Requires at least', 'requires_php' => 'Requires PHP', 'requires_plugins' => 'Requires Plugins', 'template' => 'Template' ); $out = array();
		foreach ( $headers as $key => $label ) { $out[ $key ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $source, $match ) ? trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) ) : ''; }
		return $out;
	}

	private static function compatible( array $data ) {
		foreach ( array( 'requires_wp', 'requires_php' ) as $key ) { if ( '' !== $data[ $key ] && ! preg_match( '/^\d+(?:\.\d+){0,3}$/D', $data[ $key ] ) ) { return self::error( 'requirements', '升级包的运行环境版本声明无效。' ); } }
		global $wp_version;
		if ( '' !== $data['requires_php'] && version_compare( PHP_VERSION, $data['requires_php'], '<' ) ) { return self::error( 'php_version', '当前PHP版本不满足升级包要求。' ); }
		if ( '' !== $data['requires_wp'] && version_compare( (string) $wp_version, $data['requires_wp'], '<' ) ) { return self::error( 'wp_version', '当前WordPress版本不满足升级包要求。' ); }
		self::wp_files();
		if ( '' !== $data['template'] && ( ! self::slug( $data['template'] ) || ! wp_get_theme( $data['template'] )->exists() ) ) { return self::error( 'theme_parent', '升级包依赖的父主题无效或未安装。' ); }
		if ( '' !== $data['requires_plugins'] ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', $data['requires_plugins'] ) ) ) as $dependency ) {
				if ( ! self::slug( $dependency ) ) { return self::error( 'plugin_dependency', '插件依赖声明无效。' ); }
				$found = false; foreach ( get_plugins() as $file => $unused ) { if ( dirname( $file ) === $dependency && is_plugin_active( $file ) ) { $found = true; break; } }
				if ( ! $found ) { return self::error( 'plugin_dependency', '升级包依赖的插件未安装或未启用：' . $dependency ); }
			}
		}
		return true;
	}

	private static function tools() {
		$tools = (array) ( Storage::config()['tools'] ?? array() );
		if ( empty( $tools['php'] ) || ! is_file( $tools['php'] ) || ! function_exists( 'proc_open' ) ) { return self::error( 'php_tool', 'PHP命令行检查器未配置，不能安全替换版本。' ); }
		return $tools;
	}

	private static function lint( string $root, array $tools, callable $progress ) {
		$files = self::files( $root ); $php = array_values( array_filter( $files, static fn( $file ) => (bool) preg_match( '/\.(?:php\d*|phtml)$/i', $file ) ) );
		if ( count( $php ) > 10000 ) { return self::error( 'lint_count', '升级包PHP文件过多，需人工检查后再发布。' ); }
		foreach ( $php as $index => $relative ) {
			$result = Storage::run( array( $tools['php'], '-n', '-l', $root . '/' . $relative ), array( 'timeout' => 20 ) );
			if ( 0 !== $result['code'] ) { return self::error( 'lint', '升级包存在PHP语法或兼容性错误，未替换当前版本。' ); }
			if ( 0 === $index % 100 ) { $progress( 12 + (int) ( ( $index + 1 ) / max( 1, count( $php ) ) * 12 ), 'lint', '正在检查升级包PHP语法。' ); }
		}
		return true;
	}

	private static function health( array $tools ) {
		$args = array( $tools['php'] ); if ( ! empty( $tools['php_ini'] ) ) { $args[] = '-c'; $args[] = $tools['php_ini']; }
		$code = 'try { define("WP_USE_THEMES",false); require ' . var_export( ABSPATH . 'wp-load.php', true ) . '; if(!function_exists("get_option")){exit(21);} echo "\nPC_OPS_BOOT_OK\n"; } catch (Throwable $e) { exit(22); }';
		$args[] = '-r'; $args[] = $code;
		$result = Storage::run( $args, array( 'cwd' => ABSPATH, 'timeout' => 75 ) );
		if ( 0 !== $result['code'] || ! str_contains( $result['stdout'], 'PC_OPS_BOOT_OK' ) ) { return self::error( 'health', '新版本未通过独立启动检查。' ); }
		$response = wp_remote_get( add_query_arg( 'pc_ops_health', wp_generate_uuid4(), home_url( '/' ) ), array( 'timeout' => 25, 'redirection' => 2, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $response ) ) { return self::error( 'health_http', '站点HTTP健康检查未通过。' ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 || preg_match( '/There has been a critical error|此站点遇到了致命错误|Fatal error:/i', (string) wp_remote_retrieve_body( $response ) ) ) { return self::error( 'health_http', '站点HTTP健康检查未通过。' ); }
		return true;
	}

	private static function record( string $id ) {
		if ( ! self::uuid( $id ) ) { return self::error( 'rollback_id', '回滚点标识无效。' ); }
		$r = Storage::read_json( 'releases/' . $id . '/manifest.json' );
		if ( ! is_array( $r ) || empty( $r['signature'] ) ) { return self::error( 'rollback_missing', '找不到完整的回滚点。' ); }
		$signature = $r['signature']; unset( $r['signature'] );
		if ( ! hash_equals( self::signature( $r ), $signature ) || ( $r['site_uuid'] ?? '' ) !== Storage::site_uuid() || ( $r['id'] ?? '' ) !== $id ) { return self::error( 'rollback_signature', '回滚点不属于当前站点或清单被修改。' ); }
		return $r;
	}
	private static function save( array $record ): void { $record['signature'] = self::signature( $record ); Storage::write_json( 'releases/' . $record['id'] . '/manifest.json', $record ); }
	private static function signature( array $record ): string { unset( $record['signature'] ); return hash_hmac( 'sha256', (string) wp_json_encode( $record, JSON_UNESCAPED_SLASHES ), hash_hmac( 'sha256', 'peptide-ops-file-release', wp_salt( 'auth' ) ) ); }
	private static function public_record( array $r ): array { return array_intersect_key( $r, array_flip( array( 'id', 'type', 'slug', 'name', 'from_version', 'to_version', 'package_id', 'created', 'finished', 'status', 'backup_id', 'last_rollback', 'restored_at' ) ) ) + array( 'can_rollback' => ! empty( $r['snapshot_sha256'] ), 'file_rollback_only' => true ); }

	private static function files( string $root ): array {
		$real = realpath( $root ); if ( ! $real || ! is_dir( $real ) || is_link( $root ) ) { throw new \RuntimeException( 'unsafe_tree' ); }
		$out = array(); $stack = array( $real );
		while ( $stack ) { $dir = array_pop( $stack ); foreach ( scandir( $dir ) as $name ) { if ( '.' === $name || '..' === $name ) { continue; } $path = $dir . '/' . $name; if ( is_link( $path ) || ! self::same_path( (string) realpath( $path ), $path ) || ! Storage::within( (string) realpath( $path ), $real ) ) { throw new \RuntimeException( 'unsafe_link' ); } if ( is_dir( $path ) ) { $stack[] = $path; } elseif ( is_file( $path ) ) { $out[] = ltrim( substr( str_replace( '\\', '/', $path ), strlen( str_replace( '\\', '/', $real ) ) ), '/' ); } else { throw new \RuntimeException( 'unsafe_file' ); } } }
		sort( $out, SORT_STRING ); return $out;
	}
	private static function tree_manifest( string $root ): array { $out = array(); foreach ( self::files( $root ) as $name ) { $out[ $name ] = array( 'bytes' => filesize( $root . '/' . $name ), 'sha256' => hash_file( 'sha256', $root . '/' . $name ) ); } ksort( $out, SORT_STRING ); return $out; }
	private static function copy_tree( string $source, string $destination ): void {
		if ( file_exists( $destination ) || is_link( $destination ) ) { throw new \RuntimeException( 'stage_exists' ); }
		if ( ! mkdir( $destination, 0755 ) ) { throw new \RuntimeException( 'stage_mkdir' ); }
		foreach ( self::files( $source ) as $relative ) { $to = $destination . '/' . $relative; if ( ! is_dir( dirname( $to ) ) && ! mkdir( dirname( $to ), 0755, true ) ) { throw new \RuntimeException( 'stage_mkdir' ); } if ( ! copy( $source . '/' . $relative, $to ) ) { throw new \RuntimeException( 'stage_copy' ); } chmod( $to, 0644 ); }
	}
	/** Every recursive removal is bounded to a verified, named private/target parent. */
	private static function remove_tree( string $path, string $allowed_root, bool $target = false ): void {
		$real = realpath( $path ); $allowed = realpath( $allowed_root );
		if ( ! $real || ! $allowed || ! self::same_path( $real, $path ) || self::same_path( $real, $allowed ) || ! Storage::within( $real, $allowed ) || is_link( $path ) ) { throw new \RuntimeException( 'remove_boundary' ); }
		if ( ! $target && ! Storage::within( $real, Storage::root() ) && ! str_starts_with( basename( $path ), '.pc-ops-' ) ) { throw new \RuntimeException( 'remove_name' ); }
		$items = scandir( $path ); if ( false === $items ) { throw new \RuntimeException( 'remove_read' ); }
		foreach ( $items as $name ) { if ( '.' === $name || '..' === $name ) { continue; } $item = $path . '/' . $name; if ( is_link( $item ) || ! self::same_path( (string) realpath( $item ), $item ) || ! Storage::within( (string) realpath( $item ), $real ) ) { throw new \RuntimeException( 'remove_link' ); } if ( is_dir( $item ) ) { self::remove_tree( $item, $real, true ); } elseif ( ! unlink( $item ) ) { throw new \RuntimeException( 'remove_file' ); } }
		if ( ! rmdir( $path ) ) { throw new \RuntimeException( 'remove_dir' ); }
	}
	private static function invalidate( string $root ): void { clearstatcache( true ); foreach ( self::files( $root ) as $name ) { if ( preg_match( '/\.php$/i', $name ) && function_exists( 'opcache_invalidate' ) ) { @opcache_invalidate( $root . '/' . $name, true ); } } if ( function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( false ); } if ( function_exists( 'wp_clean_themes_cache' ) ) { wp_clean_themes_cache( false ); } }
	private static function maintenance( string $id ) { $file = ABSPATH . '.maintenance'; if ( file_exists( $file ) ) { return self::error( 'maintenance', '站点正在进行其他维护，请稍后再试。' ); } $text = '<?php $upgrading=' . time() . '; /* peptide-ops:' . $id . ' */'; $h = fopen( $file, 'x' ); if ( ! $h ) { return self::error( 'maintenance', '无法安全进入维护状态。' ); } fwrite( $h, $text ); fclose( $h ); return array( 'file' => $file, 'text' => $text ); }
	private static function end_maintenance( $record ): void { if ( is_array( $record ) && is_file( $record['file'] ) && file_get_contents( $record['file'] ) === $record['text'] ) { unlink( $record['file'] ); } }
	private static function lock() { Storage::mkdir( 'locks' ); $h = fopen( Storage::path( 'locks/releases.lock' ), 'c' ); if ( ! $h || ! flock( $h, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $h ) ) { fclose( $h ); } return self::error( 'busy', '已有插件或主题版本任务正在执行。' ); } return $h; }
	private static function unlock( $h ): void { if ( is_resource( $h ) ) { flock( $h, LOCK_UN ); fclose( $h ); } }
	private static function derived_id( string $id, string $purpose ): string { $h = hash( 'sha256', $purpose . ':' . $id ); return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-4' . substr( $h, 13, 3 ) . '-a' . substr( $h, 17, 3 ) . '-' . substr( $h, 20, 12 ); }
	private static function uuid( string $id ): bool { return (bool) preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id ); }
	private static function slug( string $slug ): bool { return (bool) preg_match( '/^[a-z0-9][a-z0-9._-]{0,149}$/D', $slug ) && ! str_contains( $slug, '..' ); }
	private static function same_path( string $a, string $b ): bool { $a = rtrim( str_replace( '\\', '/', $a ), '/' ); $b = rtrim( str_replace( '\\', '/', $b ), '/' ); return '\\' === DIRECTORY_SEPARATOR ? strtolower( $a ) === strtolower( $b ) : $a === $b; }
	private static function error( string $code, string $message, array $data = array() ): \WP_Error { return new \WP_Error( 'pc_ops_' . $code, $message, $data ); }
}
