<?php
/** Private, site-bound file/database snapshots and guarded recovery. */
namespace PeptideOps;

defined( 'ABSPATH' ) || exit;

final class Backups {
	private const FORMAT = 'vialcove-backup-v1';
	private const MAX_FILES = 100000;
	private const MAX_BYTES = 21474836480;

	/** @return array|\WP_Error */
	public static function create( array $input, callable $progress ) {
		$id = self::operation_id( $input );
		if ( is_wp_error( $id ) ) { return $id; }
		$lock = self::lock( 'backup-' . $id );
		if ( is_wp_error( $lock ) ) { return $lock; }
		$partial = 'backups/.' . $id . '.partial';
		try {
			$existing = self::manifest( $id, true );
			if ( ! is_wp_error( $existing ) ) { return self::export_summary( $existing ); }
			if ( file_exists( Storage::path( 'backups/' . $id ) ) ) { return self::error( 'id_occupied', '该备份编号已经存在但记录无效，请使用新的任务编号。' ); }
			if ( ! class_exists( 'ZipArchive' ) ) { return self::error( 'zip_missing', '服务器尚未安装 ZIP 扩展，无法创建完整备份。' ); }
			$tools = self::tools();
			if ( is_wp_error( $tools ) ) { return $tools; }
			$roots = self::source_roots();
			$files = array(); $bytes = 0;
			foreach ( $roots as $key => $root ) {
				$scan = self::scan( $root, $key, '', $files, $bytes, array(), true );
				if ( is_wp_error( $scan ) ) { return $scan; }
			}
			if ( ! $files ) { return self::error( 'files_empty', '没有找到可备份的站点文件。' ); }
			$space = self::space( $bytes + self::database_size() + 67108864 );
			if ( is_wp_error( $space ) ) { return $space; }
			Storage::mkdir( 'backups' );
			$stage = Storage::path( $partial );
			if ( file_exists( $stage ) ) {
				$pending = Storage::read_json( $partial . '/pending.json', array() );
				if ( ( $pending['site_uuid'] ?? '' ) !== Storage::site_uuid() || ( $pending['id'] ?? '' ) !== $id ) {
					return self::error( 'stale_unowned', '存在未确认归属的临时备份，请先由管理员检查。' );
				}
				$removed = self::remove_tree( $stage, Storage::path( 'backups' ) );
				if ( is_wp_error( $removed ) ) { return $removed; }
			}
			Storage::mkdir( $partial );
			Storage::write_json( $partial . '/pending.json', array( 'id' => $id, 'site_uuid' => Storage::site_uuid() ) );
			$progress( 8, 'database', '正在导出站点数据库。' );
			$tables = self::tables();
			if ( is_wp_error( $tables ) ) { return $tables; }
			$dump = self::database( 'export', $stage . '/database.sql', $tools, $tables );
			if ( is_wp_error( $dump ) ) { return $dump; }
			$progress( 25, 'files', '正在归档站点文件和私有资料。' );
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $stage . '/files.zip', \ZipArchive::CREATE | \ZipArchive::EXCL ) ) {
				return self::error( 'zip_create', '无法创建备份文件，请检查私有备份目录的空间和写入权限。' );
			}
			$count = count( $files ); $done = 0;
			foreach ( $files as $name => &$entry ) {
				$source = $entry['_source'];
				$entry['sha256'] = hash_file( 'sha256', $source );
				if ( false === $entry['sha256'] || ! $zip->addFile( $source, $name ) ) {
					$zip->close(); unset( $entry );
					return self::error( 'zip_read', '备份期间有文件无法读取，本次备份没有发布为可恢复记录。' );
				}
				unset( $entry['_source'] );
				++$done;
				if ( 0 === $done % 500 ) { $progress( 25 + (int) floor( 45 * $done / $count ), 'files', '正在归档站点文件。' ); }
			}
			unset( $entry );
			if ( ! $zip->close() ) { return self::error( 'zip_close', '文件归档未完整写入，本次备份不可用于恢复。' ); }
			$reason = 'pre_release' === ( $input['reason'] ?? '' ) ? 'pre_upgrade' : ( $input['reason'] ?? 'manual' );
			$manifest = array(
				'format' => self::FORMAT, 'state' => 'complete', 'id' => $id, 'site_uuid' => Storage::site_uuid(),
				'created_at' => gmdate( 'c' ), 'label' => mb_substr( sanitize_text_field( (string) ( $input['label'] ?? '' ) ), 0, 100 ),
				'reason' => in_array( $reason, array( 'manual', 'scheduled', 'pre_restore', 'pre_upgrade', 'rollback' ), true ) ? $reason : 'manual',
				'source_url' => home_url( '/' ), 'source_database' => (string) DB_NAME,
				'table_prefix' => $GLOBALS['wpdb']->prefix, 'tables' => $tables, 'roots' => array_keys( $roots ),
				'files' => $files, 'source_bytes' => $bytes,
				'database' => array( 'file' => 'database.sql', 'bytes' => filesize( $stage . '/database.sql' ), 'sha256' => hash_file( 'sha256', $stage . '/database.sql' ) ),
				'archive' => array( 'file' => 'files.zip', 'bytes' => filesize( $stage . '/files.zip' ), 'sha256' => hash_file( 'sha256', $stage . '/files.zip' ) ),
				'versions' => array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'core' => defined( 'PEPTIDE_CORE_VERSION' ) ? PEPTIDE_CORE_VERSION : '' ),
			);
			$progress( 78, 'verify', '正在校验归档、数据库及逐文件校验值。' );
			$valid = self::verify_archive( $stage . '/files.zip', $manifest, null );
			if ( is_wp_error( $valid ) ) { return $valid; }
			Storage::write_json( $partial . '/manifest.json', $manifest );
			@unlink( $stage . '/pending.json' );
			if ( ! rename( $stage, Storage::path( 'backups/' . $id ) ) ) {
				return self::error( 'publish', '备份校验完成，但无法发布备份记录。' );
			}
			$progress( 90, 'export', '正在后台准备完整备份下载包。' );
			$result = self::export_summary( $manifest );
			$progress( 100, 'complete', '完整备份已保存并校验。' );
			return $result;
		} catch ( \Throwable $e ) {
			return self::error( 'create_failed', '备份未完成，请检查服务器工具和私有存储配置。' );
		} finally { self::unlock( $lock ); }
	}

	/** Prepare a download bundle in a CLI worker. Never call this from a download HTTP request. */
	public static function export( string $id ) {
		if ( ! self::valid_id( $id ) ) { return self::error( 'id', '备份编号无效。' ); }
		$lock = self::lock( 'export-' . $id ); if ( is_wp_error( $lock ) ) { return $lock; }
		$tmp = '';
		try {
			$manifest = self::manifest( $id, true ); if ( is_wp_error( $manifest ) ) { return $manifest; }
			if ( ! class_exists( 'ZipArchive' ) ) { return self::error( 'zip_missing', '服务器尚未安装 ZIP 扩展，无法准备备份下载包。' ); }
			$dir = Storage::mkdir( 'exports' ); $target = Storage::path( 'exports/' . $id . '.zip' );
			if ( ! self::target_safe( $target, Storage::root() ) ) { return self::error( 'export_path', '备份下载包目录包含不安全的目录链接。' ); }
			$source = Storage::path( 'backups/' . $id );
			$expected = array(
				'files.zip' => array( 'bytes' => $manifest['archive']['bytes'], 'sha256' => $manifest['archive']['sha256'] ),
				'database.sql' => array( 'bytes' => $manifest['database']['bytes'], 'sha256' => $manifest['database']['sha256'] ),
				'manifest.json' => array( 'bytes' => filesize( $source . '/manifest.json' ), 'sha256' => hash_file( 'sha256', $source . '/manifest.json' ) ),
			);
			$marker = 'exports/' . $id . '.json'; $old = Storage::read_json( $marker, array() );
			if ( is_array( $old ) && true === ( $old['complete'] ?? false ) && $id === ( $old['backup_id'] ?? '' ) && ( $old['source'] ?? null ) === $expected && is_file( $target ) && (int) filesize( $target ) === (int) ( $old['bytes'] ?? -1 ) && hash_equals( (string) ( $old['sha256'] ?? '' ), (string) hash_file( 'sha256', $target ) ) ) { return self::export_result( $old ); }
			// Remove the completion marker first: HTTP must never issue a ticket while a damaged bundle is rebuilt.
			if ( is_file( Storage::path( $marker ) ) && ! unlink( Storage::path( $marker ) ) ) { return self::error( 'export_marker', '无法更新备份下载包的完成记录。' ); }
			$space = self::space( array_sum( array_column( $expected, 'bytes' ) ) + 67108864 ); if ( is_wp_error( $space ) ) { return $space; }
			$tmp = $dir . '/.' . $id . '.' . bin2hex( random_bytes( 6 ) ) . '.partial';
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) { return self::error( 'export_create', '无法准备完整备份下载包。' ); }
			foreach ( $expected as $name => $entry ) {
				if ( ! $zip->addFile( $source . '/' . $name, $name ) || ( 'files.zip' === $name && ! $zip->setCompressionName( $name, \ZipArchive::CM_STORE ) ) ) { $zip->close(); return self::error( 'export_add', '备份下载包内容未能完整写入。' ); }
			}
			if ( ! $zip->close() ) { return self::error( 'export_close', '备份下载包尚未完整生成。' ); }
			$valid = self::verify_export( $tmp, $expected ); if ( is_wp_error( $valid ) ) { return $valid; }
			if ( is_file( $target ) && ! unlink( $target ) ) { return self::error( 'export_replace', '无法替换旧的备份下载包。' ); }
			if ( ! rename( $tmp, $target ) ) { return self::error( 'export_publish', '备份下载包未能发布。' ); }
			$tmp = '';
			$meta = array( 'backup_id' => $id, 'bytes' => filesize( $target ), 'sha256' => hash_file( 'sha256', $target ), 'complete' => true, 'source' => $expected );
			Storage::write_json( $marker, $meta );
			return self::export_result( $meta );
		} catch ( \Throwable $e ) { return self::error( 'export_failed', '备份已保留，但下载包尚未准备完成，请稍后在后台重试。' ); }
		finally { if ( $tmp && is_file( $tmp ) ) { @unlink( $tmp ); } self::unlock( $lock ); }
	}

	private static function export_result( array $meta ): array { return array( 'backup_id' => $meta['backup_id'], 'export_ready' => true, 'bytes' => $meta['bytes'], 'sha256' => $meta['sha256'] ); }

	private static function export_summary( array $manifest ): array {
		$result = self::summary( $manifest );
		try { $export = self::export( $manifest['id'] ); } catch ( \Throwable $e ) { $export = self::error( 'export_failed', '备份下载包尚未准备完成。' ); }
		$result['export_ready'] = ! is_wp_error( $export );
		if ( is_wp_error( $export ) ) { $result['warnings'] = array( '完整备份已经保存；下载包暂未准备好，可稍后在后台准备下载。' ); }
		return $result;
	}

	private static function verify_export( string $file, array $expected ) {
		$zip = new \ZipArchive(); if ( true !== $zip->open( $file, \ZipArchive::CHECKCONS | \ZipArchive::RDONLY ) ) { return self::error( 'export_integrity', '备份下载包完整性检查失败。' ); }
		try {
			if ( $zip->numFiles !== count( $expected ) ) { return self::error( 'export_integrity', '备份下载包内容数量不正确。' ); }
			$seen = array();
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i ); $name = $stat['name'] ?? '';
				if ( ! isset( $expected[ $name ] ) || isset( $seen[ $name ] ) || (int) ( $stat['size'] ?? -1 ) !== (int) $expected[ $name ]['bytes'] || ( 'files.zip' === $name && \ZipArchive::CM_STORE !== (int) $stat['comp_method'] ) ) { return self::error( 'export_integrity', '备份下载包内容与完整备份不一致。' ); }
				$seen[ $name ] = true; $input = $zip->getStream( $name );
				if ( ! is_resource( $input ) ) { return self::error( 'export_integrity', '备份下载包内容无法读取。' ); }
				$hash = hash_init( 'sha256' ); $crc = hash_init( 'crc32b' ); $bytes = 0;
				try {
					while ( ! feof( $input ) ) { $chunk = fread( $input, 1048576 ); if ( false === $chunk ) { return self::error( 'export_integrity', '备份下载包内容无法完整读取。' ); } $bytes += strlen( $chunk ); if ( $bytes > $expected[ $name ]['bytes'] ) { return self::error( 'export_integrity', '备份下载包内容大小不正确。' ); } hash_update( $hash, $chunk ); hash_update( $crc, $chunk ); }
				} finally { fclose( $input ); }
				if ( $bytes !== (int) $expected[ $name ]['bytes'] || ! hash_equals( $expected[ $name ]['sha256'], hash_final( $hash ) ) || sprintf( '%08x', (int) $stat['crc'] ) !== hash_final( $crc ) ) { return self::error( 'export_integrity', '备份下载包校验值与完整备份不一致。' ); }
			}
			return true;
		} finally { $zip->close(); }
	}

	/** Restore only a server-created snapshot belonging to this exact site. */
	public static function restore( string $id, array $input, callable $progress ) {
		if ( ( $input['confirm_uuid'] ?? '' ) !== Storage::site_uuid() ) {
			return self::error( 'confirmation', '恢复需要明确确认当前站点。' );
		}
		$operation = self::operation_id( $input );
		if ( is_wp_error( $operation ) ) { return $operation; }
		$manifest = self::manifest( $id, true );
		if ( is_wp_error( $manifest ) ) { return $manifest; }
		$lock = self::lock( 'restore' );
		if ( is_wp_error( $lock ) ) { return $lock; }
		$relative = 'restores/' . $operation;
		$maintenance = ABSPATH . '.maintenance'; $owns_maintenance = false;
		$rollback = ''; $preserved = array(); $mutated = false;
		try {
			$old = (array) Storage::read_json( $relative . '/state.json', array() );
			if ( ! empty( $old ) && ( $old['backup_id'] ?? '' ) !== $id ) { return self::error( 'operation_reused', '该任务编号已经用于另一份备份。' ); }
			if ( 'complete' === ( $old['phase'] ?? '' ) ) { return $old['result']; }
			if ( in_array( $old['phase'] ?? '', array( 'applying', 'rollback_failed' ), true ) ) {
				return self::error( 'restore_interrupted', '先前恢复曾中断，已保留回退备份；请检查后重新发起恢复。', array( 'rollback_id' => $old['rollback_id'] ?? '', 'manual_recovery_required' => true ) );
			}
			if ( file_exists( $maintenance ) ) { return self::error( 'maintenance_busy', '站点正在执行其他维护操作，请稍后恢复。' ); }
			$tools = self::tools(); if ( is_wp_error( $tools ) ) { return $tools; }
			$preflight = self::restore_preflight( $manifest ); if ( is_wp_error( $preflight ) ) { return $preflight; }
			$progress( 5, 'verify', '正在校验恢复点和服务器条件。' );
			$stage = self::stage( $id, $manifest, $relative . '/staged' );
			if ( is_wp_error( $stage ) ) { return $stage; }
			$rollback = wp_generate_uuid4();
			$progress( 18, 'rollback_backup', '正在创建恢复前的回退备份。' );
			$backup = self::create( array( 'operation_id' => $rollback, 'label' => '恢复前自动备份', 'reason' => 'pre_restore' ), static function ( $percent, $phase, $message ) use ( $progress ) { $progress( 18 + (int) floor( 32 * $percent / 100 ), 'rollback_backup', '正在保存恢复前的完整状态。' ); } );
			if ( is_wp_error( $backup ) ) { return $backup; }
			$preserved = self::preserved_options();
			if ( false === file_put_contents( $maintenance, '<?php $upgrading = ' . time() . ';', LOCK_EX ) ) { return self::error( 'maintenance_write', '无法启用短时维护保护，本次没有执行恢复。' ); }
			$owns_maintenance = true;
			Storage::write_json( $relative . '/state.json', array( 'phase' => 'applying', 'backup_id' => $id, 'rollback_id' => $rollback, 'site_uuid' => Storage::site_uuid() ) );
			$progress( 52, 'restore_files', '正在恢复站点文件。' );
			$mutated = true;
			$applied = self::apply_files( $stage, $manifest );
			if ( is_wp_error( $applied ) ) { throw new \RuntimeException( 'restore-files' ); }
			$progress( 76, 'restore_database', '正在恢复数据库。' );
			$import = self::database( 'import', Storage::path( 'backups/' . $id . '/database.sql' ), $tools, $manifest['tables'] );
			if ( is_wp_error( $import ) ) { throw new \RuntimeException( 'restore-database' ); }
			self::restore_options( $preserved );
			$result = array( 'id' => $operation, 'backup_id' => $id, 'rollback_id' => $rollback, 'restored' => true, 'completed_at' => gmdate( 'c' ) );
			Storage::write_json( $relative . '/state.json', array( 'phase' => 'complete', 'backup_id' => $id, 'rollback_id' => $rollback, 'result' => $result ) );
			$progress( 100, 'complete', '恢复完成，管理连接和当前服务器配置已保留。' );
			return $result;
		} catch ( \Throwable $e ) {
			if ( ! $mutated ) { return self::error( 'restore_preparation', '恢复准备未完成，站点内容尚未改动。', array( 'rollback_id' => $rollback, 'rolled_back' => false, 'manual_recovery_required' => false ) ); }
			$recovered = false;
			if ( $mutated && $rollback ) {
				try {
					$progress( 84, 'recovery', '恢复未完成，正在恢复操作前状态。' );
					$back = self::manifest( $rollback, true );
					if ( ! is_wp_error( $back ) ) {
						$back_stage = self::stage( $rollback, $back, $relative . '/rollback-staged' );
						if ( ! is_wp_error( $back_stage ) && ! is_wp_error( self::apply_files( $back_stage, $back ) ) && ! is_wp_error( self::database( 'import', Storage::path( 'backups/' . $rollback . '/database.sql' ), self::tools(), $back['tables'] ) ) ) {
							self::restore_options( $preserved ); $recovered = true;
						}
					}
				} catch ( \Throwable $ignored ) { $recovered = false; }
			}
			Storage::write_json( $relative . '/state.json', array( 'phase' => $recovered ? 'rolled_back' : 'rollback_failed', 'backup_id' => $id, 'rollback_id' => $rollback ) );
			return self::error( 'restore_failed', $recovered ? '恢复未完成，已回退到操作前状态。' : '恢复未完成，请使用保留的回退备份进行恢复。', array( 'rollback_id' => $rollback, 'rolled_back' => $recovered, 'manual_recovery_required' => ! $recovered ) );
		} finally {
			if ( $owns_maintenance && is_file( $maintenance ) ) { @unlink( $maintenance ); }
			self::unlock( $lock );
		}
	}

	public static function listing(): array {
		$out = array();
		$root = Storage::path( 'backups' );
		if ( ! is_dir( $root ) ) { return $out; }
		foreach ( scandir( $root ) as $id ) {
			if ( ! self::valid_id( $id ) ) { continue; }
			$manifest = self::manifest( $id, false );
			if ( ! is_wp_error( $manifest ) ) { $out[] = self::summary( $manifest ); }
		}
		usort( $out, static fn( $a, $b ) => strcmp( $b['created_at'], $a['created_at'] ) );
		return $out;
	}

	/** @return true|\WP_Error */
	public static function delete( string $id ) {
		$manifest = self::manifest( $id, false );
		if ( is_wp_error( $manifest ) ) { return $manifest; }
		$lock = self::lock( 'restore' );
		if ( is_wp_error( $lock ) ) { return $lock; }
		$item_lock = self::lock( 'backup-' . $id );
		if ( is_wp_error( $item_lock ) ) { self::unlock( $lock ); return $item_lock; }
		$export_lock = self::lock( 'export-' . $id );
		if ( is_wp_error( $export_lock ) ) { self::unlock( $item_lock ); self::unlock( $lock ); return $export_lock; }
		try {
			foreach ( glob( Storage::path( 'restores/*/state.json' ) ) ?: array() as $file ) {
				$state = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $state ) && in_array( $state['phase'] ?? '', array( 'applying', 'rollback_failed' ), true ) && in_array( $id, array( $state['backup_id'] ?? '', $state['rollback_id'] ?? '' ), true ) ) { return self::error( 'recovery_required', '该备份被未完成的恢复任务引用，请先完成恢复检查。' ); }
			}
			foreach ( array( 'exports/' . $id . '.json', 'exports/' . $id . '.zip' ) as $relative ) {
				$file = Storage::path( $relative );
				if ( file_exists( $file ) && ( ! is_file( $file ) || ! self::target_safe( $file, Storage::root() ) || ! unlink( $file ) ) ) { return self::error( 'delete_export', '备份下载包未能清理，完整备份仍保留。' ); }
			}
			return self::remove_tree( Storage::path( 'backups/' . $id ), Storage::path( 'backups' ) );
		}
		finally { self::unlock( $export_lock ); self::unlock( $item_lock ); self::unlock( $lock ); }
	}

	/** Safe relative paths, including file names that Windows would otherwise normalize. */
	private static function safe_name( string $name ): bool {
		if ( '' === $name || str_contains( $name, '\\' ) || str_contains( $name, ':' ) || str_starts_with( $name, '/' ) || preg_match( '/[\x00-\x1f\x7f]/', $name ) ) { return false; }
		foreach ( explode( '/', $name ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || preg_match( '/[. ]$/', $part ) || preg_match( '/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part ) ) { return false; }
		}
		return true;
	}

	private static function valid_id( string $id ): bool { return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id ); }
	private static function operation_id( array $input ) { $id = strtolower( (string) ( $input['operation_id'] ?? '' ) ); return self::valid_id( $id ) ? $id : self::error( 'operation_id', '缺少有效的任务编号。' ); }
	private static function error( string $code, string $message, array $data = array() ): \WP_Error { return new \WP_Error( 'ops_backup_' . $code, $message, $data ); }

	private static function source_roots(): array {
		$roots = array( 'public' => untrailingslashit( ABSPATH ) );
		if ( defined( 'PEPTIDE_CORE_PRIVATE_DIR' ) && is_dir( (string) PEPTIDE_CORE_PRIVATE_DIR ) ) {
			$private = realpath( (string) PEPTIDE_CORE_PRIVATE_DIR );
			if ( $private && ! Storage::within( $private, ABSPATH ) && ! Storage::within( $private, Storage::root() ) ) { $roots['reports'] = $private; }
		}
		return $roots;
	}

	private static function scan( string $root, string $label, string $relative, array &$files, int &$bytes, array $ancestors, bool $allow_links ) {
		$logical = $root . ( '' === $relative ? '' : '/' . $relative );
		$actual = realpath( $logical );
		if ( false === $actual ) { return self::error( 'file_missing', '文件清单发生变化，请重新创建备份。' ); }
		if ( Storage::within( $actual, Storage::root() ) ) { return true; }
		$allowed = Storage::within( $actual, $root );
		foreach ( (array) ( Storage::config()['allowed_source_roots'] ?? array() ) as $path ) { $allowed = $allowed || Storage::within( $actual, (string) $path ); }
		if ( ! $allowed ) { return self::error( 'link_root', '站点包含未在服务器白名单中的目录链接，备份已停止。' ); }
		if ( ! $allow_links && ( self::case_key( wp_normalize_path( $actual ) ) !== self::case_key( rtrim( wp_normalize_path( $logical ), '/' ) ) || is_link( $logical ) ) ) { return self::error( 'restore_link', '恢复目标包含目录链接，请使用独立站点目录恢复。' ); }
		if ( is_dir( $logical ) ) {
			$canonical = strtolower( wp_normalize_path( $actual ) );
			if ( isset( $ancestors[ $canonical ] ) ) { return self::error( 'link_cycle', '站点包含循环目录链接，无法备份。' ); }
			$ancestors[ $canonical ] = true;
			$children = scandir( $logical ); if ( false === $children ) { return self::error( 'directory_read', '站点目录无法读取。' ); }
			foreach ( $children as $child ) {
				if ( '.' === $child || '..' === $child ) { continue; }
				$next = '' === $relative ? $child : $relative . '/' . $child;
				if ( self::skip_backup( $label . '/' . $next ) ) { continue; }
				if ( ! $allow_links && ( self::protected_restore( $label . '/' . $next ) || self::protected_restore( $label . '/' . $next . '/' ) ) ) { continue; }
				$result = self::scan( $root, $label, $next, $files, $bytes, $ancestors, $allow_links );
				if ( is_wp_error( $result ) ) { return $result; }
			}
			return true;
		}
		$name = $label . '/' . $relative;
		if ( ! is_file( $logical ) || ! is_readable( $logical ) || ! self::safe_name( $name ) ) { return self::error( 'file_read', '站点有无法安全读取的文件。' ); }
		$size = filesize( $logical ); if ( false === $size ) { return self::error( 'file_size', '无法核对文件大小。' ); }
		$bytes += $size;
		$config = Storage::config();
		if ( count( $files ) >= (int) ( $config['backup_max_files'] ?? self::MAX_FILES ) || $bytes > (int) ( $config['backup_max_bytes'] ?? self::MAX_BYTES ) ) { return self::error( 'limit', '站点文件超出本机备份容量上限。' ); }
		$files[ $name ] = array( 'bytes' => $size, 'mtime' => (int) filemtime( $logical ), 'mode' => (int) fileperms( $logical ) & 0777, '_source' => $logical );
		return true;
	}

	private static function skip_backup( string $name ): bool {
		return (bool) preg_match( '~(?:^|/)(?:\.git|node_modules|\.svn)(?:/|$)~', $name ) || in_array( $name, array( 'public/.maintenance', 'public/wp-content/debug.log', 'public/local-xdebuginfo.php' ), true ) || str_starts_with( $name, 'public/wp-content/cache/' ) || str_starts_with( $name, 'public/wp-content/upgrade/' );
	}

	private static function protected_restore( string $name ): bool {
		return in_array( strtolower( $name ), array( 'public/wp-config.php', 'public/.env', 'public/.maintenance' ), true ) || str_starts_with( strtolower( $name ), 'public/wp-content/plugins/peptide-ops/' ) || str_starts_with( strtolower( $name ), 'public/wp-content/mu-plugins/' );
	}

	private static function tools() {
		$tools = (array) ( Storage::config()['tools'] ?? array() );
		foreach ( array( 'mysqldump', 'mysql' ) as $name ) {
			if ( empty( $tools[ $name ] ) || ! is_file( $tools[ $name ] ) || ! is_readable( $tools[ $name ] ) ) { return self::error( 'database_tool', '数据库备份工具未配置或不可用，请在服务器运维配置中设置。' ); }
		}
		if ( ! function_exists( 'proc_open' ) ) { return self::error( 'process_disabled', '服务器未允许后台工具进程，无法执行完整备份或恢复。' ); }
		return $tools;
	}

	private static function tables() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ); $tables = array();
		if ( ! is_array( $rows ) || $wpdb->last_error ) { return self::error( 'table_list', '无法读取当前站点的数据表清单。' ); }
		foreach ( $rows as $row ) {
			if ( str_starts_with( $row[0], $wpdb->prefix ) ) {
				if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $row[0] ) ) { return self::error( 'table_name', '数据库中存在不支持的站点表名。' ); }
				$tables[] = $row[0];
			}
		}
		sort( $tables );
		return $tables ? $tables : self::error( 'tables_missing', '没有找到当前站点的数据表。' );
	}

	private static function database_size(): int {
		global $wpdb;
		$sum = 0;
		foreach ( (array) $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ) as $row ) {
			if ( str_starts_with( (string) $row['Name'], $wpdb->prefix ) ) { $sum += (int) $row['Data_length'] + (int) $row['Index_length']; }
		}
		return $sum;
	}

	private static function space( int $needed ) {
		$free = disk_free_space( Storage::root() );
		return false !== $free && $free < $needed ? self::error( 'disk_space', '私有备份磁盘空间不足，请先释放空间。' ) : true;
	}

	private static function database( string $mode, string $file, array $tools, array $tables ) {
		global $wpdb;
		$temp = 'work/db-' . wp_generate_uuid4(); Storage::mkdir( $temp );
		$config = Storage::path( $temp . '/client.cnf' );
		$host = (string) DB_HOST; $port = (int) $wpdb->get_var( 'SELECT @@port' ); $socket = '';
		if ( preg_match( '/^([^:]+):(\d+)$/D', $host, $m ) ) { $host = $m[1]; $port = (int) $m[2]; }
		elseif ( preg_match( '/^([^:]+):(.+\.sock)$/D', $host, $m ) ) { $host = $m[1]; $socket = $m[2]; }
		elseif ( preg_match( '/^\[([^\]]+)\](?::(\d+))?$/D', $host, $m ) ) { $host = $m[1]; if ( ! empty( $m[2] ) ) { $port = (int) $m[2]; } }
		if ( 'localhost' === $host && ! $socket ) { $host = '127.0.0.1'; }
		$quote = static fn( $value ) => '"' . str_replace( array( '\\', '"', "\n", "\r" ), array( '\\\\', '\\"', '\\n', '\\r' ), (string) $value ) . '"';
		$ini = "[client]\nhost=" . $quote( $host ) . "\nport=" . $port . "\nuser=" . $quote( DB_USER ) . "\npassword=" . $quote( DB_PASSWORD ) . "\ndefault-character-set=utf8mb4\n";
		if ( $socket ) { $ini .= 'socket=' . $quote( $socket ) . "\n"; }
		try {
			if ( false === file_put_contents( $config, $ini, LOCK_EX ) ) { return self::error( 'database_config', '无法准备私有数据库工具配置。' ); }
			@chmod( $config, 0600 );
			if ( 'export' === $mode ) {
				$args = array_merge( array( $tools['mysqldump'], '--defaults-extra-file=' . $config, '--single-transaction', '--quick', '--skip-lock-tables', '--add-drop-table', '--hex-blob', '--no-tablespaces', (string) DB_NAME ), $tables );
				$result = Storage::run( $args, array( 'stdout_file' => $file, 'stderr_file' => Storage::path( $temp . '/stderr' ), 'timeout' => (int) ( Storage::config()['backup_database_timeout'] ?? 300 ) ) );
				if ( 0 !== (int) $result['code'] || ! empty( $result['timed_out'] ) || ! is_file( $file ) || filesize( $file ) < 100 ) { return self::error( 'database_export', '数据库导出未完成，本次备份不可用于恢复。' ); }
				$handle = fopen( $file, 'rb' ); fseek( $handle, -min( 4096, filesize( $file ) ), SEEK_END ); $tail = stream_get_contents( $handle ); fclose( $handle );
				if ( ! str_contains( $tail, 'Dump completed on' ) ) { return self::error( 'database_footer', '数据库导出缺少完整结束标记。' ); }
			} else {
				$args = array( $tools['mysql'], '--defaults-extra-file=' . $config, '--binary-mode=1', '--skip-reconnect', '--database=' . DB_NAME );
				$result = Storage::run( $args, array( 'stdin_file' => $file, 'stdout_file' => Storage::path( $temp . '/stdout' ), 'stderr_file' => Storage::path( $temp . '/stderr' ), 'timeout' => (int) ( Storage::config()['backup_database_timeout'] ?? 300 ) ) );
				if ( 0 !== (int) $result['code'] || ! empty( $result['timed_out'] ) ) { return self::error( 'database_import', '数据库恢复工具未能完成导入。' ); }
				$extras = self::remove_extra_tables( $tables );
				if ( is_wp_error( $extras ) ) { return $extras; }
			}
			return true;
		} finally { self::remove_tree( Storage::path( $temp ), Storage::path( 'work' ) ); }
	}

	private static function manifest( string $id, bool $verify ) {
		if ( ! self::valid_id( $id ) ) { return self::error( 'id', '备份编号无效。' ); }
		$m = Storage::read_json( 'backups/' . $id . '/manifest.json', array() );
		if ( ! is_array( $m ) ) { return self::error( 'manifest', '备份清单无效，禁止恢复。' ); }
		if ( self::FORMAT !== ( $m['format'] ?? '' ) || 'complete' !== ( $m['state'] ?? '' ) || $id !== ( $m['id'] ?? '' ) || Storage::site_uuid() !== ( $m['site_uuid'] ?? '' ) || ! is_array( $m['files'] ?? null ) || ! is_array( $m['tables'] ?? null ) ) { return self::error( 'missing', '没有找到属于当前站点的完整备份。' ); }
		if ( ! self::manifest_shape( $m ) ) { return self::error( 'manifest', '备份清单不完整或含有无效数据，禁止恢复。' ); }
		if ( $verify ) {
			foreach ( array( 'archive' => 'files.zip', 'database' => 'database.sql' ) as $key => $name ) {
				$path = Storage::path( 'backups/' . $id . '/' . $name );
				if ( ! is_file( $path ) || (int) filesize( $path ) !== (int) ( $m[ $key ]['bytes'] ?? -1 ) || ! hash_equals( (string) ( $m[ $key ]['sha256'] ?? '' ), (string) hash_file( 'sha256', $path ) ) ) { return self::error( 'checksum', '备份文件校验失败，禁止恢复。' ); }
			}
		}
		return $m;
	}

	/** A manifest is server-owned, but still must never turn a corrupt snapshot into arbitrary paths. */
	private static function manifest_shape( array $m ): bool {
		if ( ! is_array( $m['roots'] ?? null ) || ! in_array( 'public', $m['roots'], true ) || empty( $m['files'] ) || count( $m['files'] ) > self::MAX_FILES || empty( $m['tables'] ) ) { return false; }
		foreach ( $m['roots'] as $root ) { if ( ! is_string( $root ) || ! in_array( $root, array( 'public', 'reports' ), true ) ) { return false; } }
		if ( count( $m['roots'] ) !== count( array_unique( $m['roots'] ) ) || ! is_string( $m['source_database'] ?? null ) || '' === $m['source_database'] || ! is_string( $m['table_prefix'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $m['table_prefix'] ) ) { return false; }
		foreach ( $m['tables'] as $table ) { if ( ! is_string( $table ) || ! str_starts_with( $table, $m['table_prefix'] ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) { return false; } }
		if ( count( $m['tables'] ) !== count( array_unique( $m['tables'] ) ) ) { return false; }
		$bytes = 0; $seen = array();
		foreach ( $m['files'] as $name => $entry ) {
			if ( ! is_string( $name ) || ! self::safe_name( $name ) || ! is_array( $entry ) || ! is_int( $entry['bytes'] ?? null ) || $entry['bytes'] < 0 || ! is_int( $entry['mtime'] ?? null ) || $entry['mtime'] < 0 || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $entry['sha256'] ?? '' ) ) ) { return false; }
			$parts = explode( '/', $name, 2 );
			if ( 2 !== count( $parts ) || ! in_array( $parts[0], $m['roots'], true ) ) { return false; }
			$key = self::case_key( $name );
			if ( isset( $seen[ $key ] ) ) { return false; }
			$seen[ $key ] = true;
			$bytes += $entry['bytes']; if ( $bytes > self::MAX_BYTES ) { return false; }
		}
		foreach ( array_keys( $seen ) as $name ) { $parent = dirname( $name ); while ( '.' !== $parent ) { if ( isset( $seen[ $parent ] ) ) { return false; } $parent = dirname( $parent ); } }
		if ( ( $m['source_bytes'] ?? null ) !== $bytes ) { return false; }
		foreach ( array( 'archive' => 'files.zip', 'database' => 'database.sql' ) as $key => $filename ) {
			$entry = $m[ $key ] ?? array();
			if ( ( $entry['file'] ?? '' ) !== $filename || ! is_int( $entry['bytes'] ?? null ) || $entry['bytes'] < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $entry['sha256'] ?? '' ) ) ) { return false; }
		}
		return is_string( $m['created_at'] ?? null ) && is_string( $m['label'] ?? null ) && is_string( $m['reason'] ?? null ) && is_array( $m['versions'] ?? null );
	}

	private static function case_key( string $name ): string { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name ); }

	/** Import is followed by removing tables added after the snapshot, limited to the exact bound prefix. */
	private static function remove_extra_tables( array $expected ) {
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			$rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
			if ( ! is_array( $rows ) || $wpdb->last_error ) { return self::error( 'table_verify', '恢复后无法核对站点的数据表清单。' ); }
			$present = array(); $extra = array();
			foreach ( $rows as $row ) {
				if ( ! str_starts_with( (string) $row[0], $wpdb->prefix ) ) { continue; }
				if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $row[0] ) ) { return self::error( 'table_name', '恢复后存在无法安全核对的数据表。' ); }
				$present[] = $row[0]; if ( ! in_array( $row[0], $expected, true ) ) { $extra[] = $row; }
			}
			if ( array_diff( $expected, $present ) ) { return self::error( 'table_missing', '数据库导入后仍缺少备份清单中的数据表。' ); }
			$foreign = (int) $wpdb->get_var( 'SELECT @@FOREIGN_KEY_CHECKS' );
			if ( false === $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ) ) { return self::error( 'table_cleanup', '无法准备站点数据表清理。' ); }
			try {
				foreach ( $extra as $row ) {
					$kind = 'VIEW' === strtoupper( (string) ( $row[1] ?? '' ) ) ? 'VIEW' : 'TABLE';
					if ( false === $wpdb->query( 'DROP ' . $kind . ' `' . $row[0] . '`' ) ) { return self::error( 'table_cleanup', '无法清理恢复点之后新增的站点数据表。' ); }
				}
			} finally { $wpdb->query( 'SET FOREIGN_KEY_CHECKS=' . $foreign ); }
			return true;
		} finally { $wpdb->suppress_errors( $old ); }
	}

	private static function verify_archive( string $file, array $manifest, ?string $destination ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::CHECKCONS ) ) { return self::error( 'archive_open', '备份归档无法校验。' ); }
		$seen = array();
		try {
			if ( $zip->numFiles < 1 || $zip->numFiles !== count( $manifest['files'] ) || $zip->numFiles > self::MAX_FILES ) { return self::error( 'archive_count', '备份归档的文件数量与清单不符。' ); }
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i ); $name = (string) ( $stat['name'] ?? '' ); $lower = self::case_key( $name );
				if ( ! self::safe_name( $name ) || isset( $seen[ $lower ] ) || ! isset( $manifest['files'][ $name ] ) ) { return self::error( 'archive_path', '备份归档包含不安全或重复的路径。' ); }
				$seen[ $lower ] = true;
				$os = 0; $attributes = 0;
				if ( $zip->getExternalAttributesIndex( $i, $os, $attributes ) && 0120000 === ( ( $attributes >> 16 ) & 0170000 ) ) { return self::error( 'archive_link', '备份归档包含符号链接，禁止恢复。' ); }
				$expected = $manifest['files'][ $name ];
				if ( (int) ( $stat['size'] ?? -1 ) !== (int) $expected['bytes'] ) { return self::error( 'archive_size', '备份文件大小与清单不符。' ); }
				$input = $zip->getStream( $name ); if ( ! is_resource( $input ) ) { return self::error( 'archive_stream', '备份文件无法读取。' ); }
				$output = null;
				if ( null !== $destination ) {
					$target = $destination . '/' . $name;
					if ( ! self::target_safe( $target, $destination ) || ! wp_mkdir_p( dirname( $target ) ) ) { fclose( $input ); return self::error( 'stage_path', '无法准备安全恢复目录。' ); }
					$output = fopen( $target, 'xb' ); if ( ! is_resource( $output ) ) { fclose( $input ); return self::error( 'stage_write', '无法暂存恢复文件。' ); }
				}
				$hash = hash_init( 'sha256' ); $read = 0;
				while ( ! feof( $input ) ) {
					$chunk = fread( $input, 1048576 );
					if ( false === $chunk ) { fclose( $input ); if ( $output ) { fclose( $output ); } return self::error( 'archive_read', '读取备份文件时发生错误。' ); }
					$read += strlen( $chunk ); hash_update( $hash, $chunk );
					if ( $read > (int) $expected['bytes'] || ( $output && strlen( $chunk ) !== fwrite( $output, $chunk ) ) ) { fclose( $input ); if ( $output ) { fclose( $output ); } return self::error( 'archive_write', '恢复文件未完整暂存。' ); }
				}
				fclose( $input ); if ( $output ) { fclose( $output ); }
				if ( $read !== (int) $expected['bytes'] || ! hash_equals( (string) $expected['sha256'], hash_final( $hash ) ) ) { return self::error( 'file_checksum', '逐文件校验失败，禁止恢复。' ); }
			}
			return true;
		} finally { $zip->close(); }
	}

	private static function restore_preflight( array $manifest ) {
		global $wpdb;
		if ( ( $manifest['source_database'] ?? '' ) !== DB_NAME || ( $manifest['table_prefix'] ?? '' ) !== $wpdb->prefix ) { return self::error( 'database_binding', '该恢复点不属于当前数据库和站点表前缀。' ); }
		foreach ( $manifest['tables'] as $table ) { if ( ! is_string( $table ) || ! str_starts_with( $table, $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) { return self::error( 'database_tables', '恢复点包含不属于当前站点的数据表。' ); } }
		$roots = self::source_roots(); $current = array(); $bytes = 0;
		foreach ( $manifest['roots'] as $key ) {
			if ( ! isset( $roots[ $key ] ) ) { return self::error( 'private_root', '该备份包含私有资料，但当前服务器没有对应的私有资料目录配置。' ); }
			$scan = self::scan( $roots[ $key ], $key, '', $current, $bytes, array(), false ); if ( is_wp_error( $scan ) ) { return $scan; }
			if ( ! is_writable( $roots[ $key ] ) ) { return self::error( 'restore_permission', '站点恢复目录不可写。' ); }
			$free = disk_free_space( $roots[ $key ] );
			if ( false !== $free && $free < (int) $manifest['source_bytes'] + 67108864 ) { return self::error( 'restore_space', '站点所在磁盘空间不足，无法安全恢复文件。' ); }
		}
		return self::space( (int) $manifest['source_bytes'] + $bytes + self::database_size() + 134217728 );
	}

	private static function stage( string $id, array $manifest, string $relative ) {
		$path = Storage::path( $relative );
		if ( file_exists( $path ) ) { $removed = self::remove_tree( $path, Storage::path( 'restores' ) ); if ( is_wp_error( $removed ) ) { return $removed; } }
		Storage::mkdir( $relative );
		$result = self::verify_archive( Storage::path( 'backups/' . $id . '/files.zip' ), $manifest, $path );
		return is_wp_error( $result ) ? $result : $path;
	}

	private static function target_safe( string $target, string $root ): bool {
		if ( ! Storage::within( $target, $root ) ) { return false; }
		$cursor = $target;
		while ( ! file_exists( $cursor ) && dirname( $cursor ) !== $cursor ) { $cursor = dirname( $cursor ); }
		$actual = realpath( $cursor );
		return false !== $actual && Storage::within( $actual, realpath( $root ) ?: $root ) && self::case_key( wp_normalize_path( $actual ) ) === self::case_key( rtrim( wp_normalize_path( $cursor ), '/' ) ) && ! is_link( $cursor );
	}

	private static function apply_files( string $stage, array $manifest ) {
		$roots = self::source_roots(); $current = array(); $bytes = 0;
		foreach ( $manifest['roots'] as $key ) {
			$scan = self::scan( $roots[ $key ], $key, '', $current, $bytes, array(), false ); if ( is_wp_error( $scan ) ) { return $scan; }
		}
		foreach ( $manifest['files'] as $name => $entry ) {
			if ( self::protected_restore( $name ) ) { continue; }
			$parts = explode( '/', $name, 2 );
			if ( ! isset( $roots[ $parts[0] ] ) || ! self::safe_name( $name ) ) { return self::error( 'apply_path', '恢复清单的目标目录无效。' ); }
			$target = $roots[ $parts[0] ] . '/' . $parts[1];
			if ( ! self::target_safe( $target, $roots[ $parts[0] ] ) || ! wp_mkdir_p( dirname( $target ) ) ) { return self::error( 'apply_link', '恢复目标包含目录链接或不可写目录。' ); }
			$tmp = $target . '.ops-restore-' . wp_generate_uuid4();
			if ( ! copy( $stage . '/' . $name, $tmp ) || ! hash_equals( $entry['sha256'], (string) hash_file( 'sha256', $tmp ) ) ) { @unlink( $tmp ); return self::error( 'apply_copy', '站点文件复制或校验失败。' ); }
			if ( is_file( $target ) && ! unlink( $target ) ) { @unlink( $tmp ); return self::error( 'apply_replace', '站点文件无法替换。' ); }
			if ( ! rename( $tmp, $target ) ) { @unlink( $tmp ); return self::error( 'apply_rename', '站点文件无法完成替换。' ); }
			@touch( $target, (int) $entry['mtime'] );
			if ( isset( $entry['mode'] ) && '\\' !== DIRECTORY_SEPARATOR ) { @chmod( $target, (int) $entry['mode'] & 0777 ); }
		}
		foreach ( $current as $name => $entry ) {
			if ( isset( $manifest['files'][ $name ] ) || self::protected_restore( $name ) ) { continue; }
			$parts = explode( '/', $name, 2 ); $target = $roots[ $parts[0] ] . '/' . $parts[1];
			if ( ! self::target_safe( $target, $roots[ $parts[0] ] ) || ! unlink( $target ) ) { return self::error( 'apply_extra', '无法清理恢复点之后新增的站点文件。' ); }
		}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
		return true;
	}

	private static function preserved_options(): array {
		global $wpdb; $out = array();
		foreach ( array( 'home', 'siteurl', 'peptide_core_hq' ) as $name ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
			$out[ $name ] = $row ?: null;
		}
		return $out;
	}

	private static function restore_options( array $options ): void {
		global $wpdb;
		foreach ( $options as $name => $row ) {
			if ( null === $row ) { $saved = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); }
			else { $saved = $wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ), array( '%s', '%s', '%s' ) ); }
			if ( false === $saved ) { throw new \RuntimeException( 'restore-preserved-options' ); }
		}
		wp_cache_flush();
	}

	private static function summary( array $manifest ): array {
		return array( 'id' => $manifest['id'], 'site_uuid' => $manifest['site_uuid'], 'state' => 'complete', 'created_at' => $manifest['created_at'], 'label' => $manifest['label'], 'reason' => $manifest['reason'], 'files' => count( $manifest['files'] ), 'bytes' => (int) $manifest['archive']['bytes'] + (int) $manifest['database']['bytes'], 'verified' => true, 'verification' => 'at_creation_and_before_restore', 'versions' => $manifest['versions'] );
	}

	private static function lock( string $name ) {
		Storage::mkdir( 'locks' );
		$handle = fopen( Storage::path( 'locks/' . $name . '.lock' ), 'c' );
		if ( ! is_resource( $handle ) || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $handle ) ) { fclose( $handle ); } return self::error( 'busy', '站点正在执行同类运维操作，请稍后再试。' ); }
		return $handle;
	}
	private static function unlock( $handle ): void { if ( is_resource( $handle ) ) { flock( $handle, LOCK_UN ); fclose( $handle ); } }

	/** Delete only an explicitly checked subtree under a private, named parent. */
	private static function remove_tree( string $path, string $parent ) {
		if ( ! file_exists( $path ) ) { return true; }
		$real = realpath( $path ); $base = realpath( $parent );
		if ( ! $real || ! $base || $real === $base || ! Storage::within( $real, $base ) || self::case_key( wp_normalize_path( $real ) ) !== self::case_key( rtrim( wp_normalize_path( $path ), '/' ) ) || is_link( $path ) ) { return self::error( 'delete_path', '私有清理路径校验失败。' ); }
		foreach ( new \DirectoryIterator( $path ) as $entry ) {
			if ( $entry->isDot() ) { continue; }
			$item = $entry->getPathname();
			$item_real = realpath( $item );
			if ( $entry->isLink() || ! $item_real || ! Storage::within( $item_real, $base ) || self::case_key( wp_normalize_path( $item_real ) ) !== self::case_key( wp_normalize_path( $item ) ) ) { return self::error( 'delete_link', '私有清理目录中存在不允许的目录链接。' ); }
			if ( $entry->isDir() ) { $result = self::remove_tree( $item, $parent ); if ( is_wp_error( $result ) ) { return $result; } }
			elseif ( ! unlink( $item ) ) { return self::error( 'delete_file', '无法删除私有临时文件。' ); }
		}
		return rmdir( $path ) ? true : self::error( 'delete_directory', '无法删除私有临时目录。' );
	}
}
