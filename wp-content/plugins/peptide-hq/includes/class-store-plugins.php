<?php
/**
 * 插件管理（2026-11-08）：总后台的插件库，按店铺分配第三方插件。
 *
 * - 上传：只接受 WordPress 插件 .zip（一个顶层文件夹 = 插件标识，主文件带 Plugin Name）。校验后放到
 *   PEPTIDE_HQ_PROVISION_QUEUE/plugins/packages/<sha256>.zip，库表记名字、版本、指纹。
 * - 安装 / 卸载：只往 plugins/requests/ 写一个有界的请求（店铺 uuid、插件标识、包指纹），不带任何路径或命令。
 *   root 后台任务（tools/deployment/provisioning/plugins.py）把插件同时放到店铺的前台和后台服务器、启用、
 *   检查首页和购物车；打不开就自动停用并回报失败。
 * - 新店自动安装：库里勾了“新店自动安装”的插件，在新店第一次注册到总后台时自动排队安装。
 * - 只有总后台管理员（Menu::CAP）能用；店铺后台没有安装插件的入口（DISALLOW_FILE_MODS）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Store_Plugins {

	public const MAX_SIZE = 33554432; // 32 MB，和 root 任务的上限一致。
	/** 平台自带的插件，第三方包不能用这些标识。 */
	public const RESERVED = array( 'peptide-core', 'peptide-panapay', 'peptide-ops', 'peptide-hq', 'woocommerce', 'fluent-smtp', 'sqlite-database-integration', 'akismet' );
	public const STATES = array( 'installing', 'active', 'removing', 'removed', 'failed' );

	public static function register_hooks(): void {
		add_action( 'pchq_site_registered', array( self::class, 'auto_install' ), 10, 1 );
	}

	public static function root(): string {
		return defined( 'PEPTIDE_HQ_PROVISION_QUEUE' ) ? rtrim( (string) PEPTIDE_HQ_PROVISION_QUEUE, '/\\' ) . '/plugins' : '';
	}

	/** 队列目录由 root 任务创建（每 20 秒一次）；还没创建或不可写时，页面提示稍后再试。 */
	public static function ready(): bool {
		$root = self::root();
		if ( '' === $root || is_link( $root ) ) {
			return false;
		}
		foreach ( array( 'requests', 'packages' ) as $dir ) {
			$path = $root . '/' . $dir;
			if ( is_link( $path ) || ! is_dir( $path ) || ! is_writable( $path ) ) {
				return false;
			}
		}
		return true;
	}

	// ---------- 库 ----------

	/** @return array<int,array<string,mixed>> 每个插件标识一行（最新上传的版本），附带全部版本数。 */
	public static function library(): array {
		global $wpdb;
		$t    = Db::table( 'plugins' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY slug ASC, id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $out[ $r['slug'] ] ) ) {
				$r['versions']       = 0;
				$out[ $r['slug'] ] = $r;
			}
			++$out[ $r['slug'] ]['versions'];
		}
		return array_values( $out );
	}

	public static function package( int $id ): ?array {
		global $wpdb;
		$t   = Db::table( 'plugins' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ?: null;
	}

	public static function latest( string $slug ): ?array {
		global $wpdb;
		$t   = Db::table( 'plugins' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s ORDER BY id DESC LIMIT 1", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ?: null;
	}

	/**
	 * 读压缩包：一个顶层文件夹、主文件带 Plugin Name、没有危险路径。
	 *
	 * @return array{slug:string,name:string,version:string}|\WP_Error
	 */
	public static function inspect( string $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'pchq_plugin_zip', '服务器缺少 zip 扩展，暂时不能读取插件包。' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			return new \WP_Error( 'pchq_plugin_zip', '不是有效的 .zip 文件。' );
		}
		$tops  = array();
		$total = 0;
		$heads = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = (string) ( $stat['name'] ?? '' );
			if ( str_starts_with( $name, '__MACOSX/' ) || '.DS_Store' === basename( $name ) ) {
				continue;
			}
			if ( '' === $name || str_contains( $name, '\\' ) || str_contains( $name, "\0" ) || str_starts_with( $name, '/' ) || in_array( '..', explode( '/', $name ), true ) ) {
				$zip->close();
				return new \WP_Error( 'pchq_plugin_path', '压缩包里有不安全的文件路径。' );
			}
			$total += (int) ( $stat['size'] ?? 0 );
			$parts  = explode( '/', $name );
			$tops[ $parts[0] ] = true;
			if ( 2 === count( $parts ) && str_ends_with( $parts[1], '.php' ) ) {
				$heads[] = $i;
			}
		}
		if ( $zip->numFiles > 20000 || $total > 268435456 ) {
			$zip->close();
			return new \WP_Error( 'pchq_plugin_size', '插件解压后太大。' );
		}
		if ( 1 !== count( $tops ) ) {
			$zip->close();
			return new \WP_Error( 'pchq_plugin_layout', '压缩包里应只有一个插件文件夹。请上传插件作者提供的原始 .zip，不要再套一层文件夹。' );
		}
		$slug = (string) array_key_first( $tops );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $slug ) || in_array( $slug, self::RESERVED, true ) ) {
			$zip->close();
			return new \WP_Error( 'pchq_plugin_slug', '插件文件夹名“' . $slug . '”不能使用（只能是小写字母、数字、- _ .，且不能和平台自带插件同名）。' );
		}
		$name    = '';
		$version = '';
		foreach ( $heads as $i ) {
			$head = (string) $zip->getFromIndex( $i, 8192 );
			if ( preg_match( '/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $head, $m ) ) {
				$name = trim( wp_strip_all_tags( $m[1] ) );
				if ( preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $head, $v ) ) {
					$version = trim( wp_strip_all_tags( $v[1] ) );
				}
				break;
			}
		}
		$zip->close();
		if ( '' === $name ) {
			return new \WP_Error( 'pchq_plugin_header', '没找到插件主文件（插件文件夹里缺少带 Plugin Name 的 .php）。' );
		}
		return array( 'slug' => $slug, 'name' => mb_substr( $name, 0, 120 ), 'version' => mb_substr( $version, 0, 40 ) );
	}

	/**
	 * 保存上传的插件包。
	 *
	 * @param array<string,mixed> $file $_FILES 里的一项。
	 * @return array<string,mixed>|\WP_Error 库里的这一行。
	 */
	public static function upload( array $file ) {
		if ( ! current_user_can( Admin\Menu::CAP ) ) {
			return new \WP_Error( 'pchq_plugin_permission', '无权上传插件。' );
		}
		if ( ! self::ready() ) {
			return new \WP_Error( 'pchq_plugin_queue', '插件服务还没准备好，请一分钟后再试。' );
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'pchq_plugin_upload', UPLOAD_ERR_INI_SIZE === (int) ( $file['error'] ?? 0 ) || UPLOAD_ERR_FORM_SIZE === (int) ( $file['error'] ?? 0 ) ? '文件超过 32 MB。' : '没有收到文件，请重新选择。' );
		}
		$size = (int) filesize( $tmp );
		if ( $size < 1 || $size > self::MAX_SIZE ) {
			return new \WP_Error( 'pchq_plugin_size', '插件包要小于 32 MB。' );
		}
		$meta = self::inspect( $tmp );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$sha = hash_file( 'sha256', $tmp );
		global $wpdb;
		$t     = Db::table( 'plugins' );
		$exist = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE sha256 = %s", $sha ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $exist ) {
			return $exist;
		}
		$dest = self::root() . '/packages/' . $sha . '.zip';
		if ( ! is_file( $dest ) ) {
			$part = self::root() . '/packages/.' . $sha . '.' . wp_generate_password( 8, false ) . '.part';
			if ( ! @copy( $tmp, $part ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'pchq_plugin_store', '插件包保存失败，请重试。' );
			}
			chmod( $part, 0640 );
			if ( ! hash_equals( $sha, (string) hash_file( 'sha256', $part ) ) || ! @rename( $part, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'pchq_plugin_store', '插件包保存失败，请重试。' );
			}
		}
		$auto = self::latest( $meta['slug'] );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'slug'        => $meta['slug'],
				'name'        => $meta['name'],
				'version'     => $meta['version'],
				'sha256'      => $sha,
				'size'        => $size,
				'auto_new'    => $auto ? (int) $auto['auto_new'] : 0,
				'uploaded_by' => get_current_user_id(),
				'uploaded_at' => time(),
			)
		);
		return self::package( (int) $wpdb->insert_id ) ?? new \WP_Error( 'pchq_plugin_store', '插件记录保存失败。' );
	}

	public static function set_auto( string $slug, bool $on ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'plugins' ), array( 'auto_new' => $on ? 1 : 0 ), array( 'slug' => $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** 只有没装在任何店铺上的插件才能从库里删除（删的是全部版本）。 */
	public static function delete( string $slug ) {
		global $wpdb;
		$s    = Db::table( 'site_plugins' );
		$busy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE slug = %s AND state <> 'removed'", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $busy ) {
			return new \WP_Error( 'pchq_plugin_in_use', '这个插件还装在店铺上，请先在所有店铺卸载。' );
		}
		$t    = Db::table( 'plugins' );
		$shas = $wpdb->get_col( $wpdb->prepare( "SELECT sha256 FROM {$t} WHERE slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $shas as $sha ) {
			$path = self::root() . '/packages/' . $sha . '.zip';
			if ( preg_match( '/^[0-9a-f]{64}$/D', (string) $sha ) && is_file( $path ) && ! is_link( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		$wpdb->delete( $t, array( 'slug' => $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $s, array( 'slug' => $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	// ---------- 分配到店铺 ----------

	/** @return array<string,array<int,array<string,mixed>>> slug => site_id => 状态行 */
	public static function assignments(): array {
		global $wpdb;
		$s    = Db::table( 'site_plugins' );
		$rows = $wpdb->get_results( "SELECT * FROM {$s}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['slug'] ][ (int) $r['site_id'] ] = $r;
		}
		return $out;
	}

	/**
	 * 给一个店铺安装（用这个插件最新的版本）或卸载。只排队，不在 PHP 里动服务器。
	 *
	 * @return true|\WP_Error
	 */
	public static function request( int $site_id, string $slug, string $action, int $actor = 0 ) {
		if ( ! in_array( $action, array( 'install', 'uninstall' ), true ) ) {
			return new \WP_Error( 'pchq_plugin_action', '操作无效。' );
		}
		if ( ! self::ready() ) {
			return new \WP_Error( 'pchq_plugin_queue', '插件服务还没准备好，请一分钟后再试。' );
		}
		$site = Sites::get( $site_id );
		if ( ! $site || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', (string) $site['uuid'] ) ) {
			return new \WP_Error( 'pchq_plugin_site', '店铺身份不完整，不能安装插件。' );
		}
		if ( 'retired' === ( $site['status'] ?? '' ) ) {
			return new \WP_Error( 'pchq_plugin_site', '店铺已停用。' );
		}
		$package = self::latest( $slug );
		if ( ! $package ) {
			return new \WP_Error( 'pchq_plugin_missing', '插件库里没有这个插件。' );
		}
		global $wpdb;
		$s       = Db::table( 'site_plugins' );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$s} WHERE site_id = %d AND slug = %s", $site_id, $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $current && in_array( $current['state'], array( 'installing', 'removing' ), true ) && (int) $current['updated'] > time() - 1800 ) {
			return new \WP_Error( 'pchq_plugin_busy', '这个店铺上的这个插件正在处理中，请稍等。' );
		}
		$op   = wp_generate_uuid4();
		$body = wp_json_encode(
			array(
				'version'      => 1,
				'operation_id' => $op,
				'actor_id'     => max( 1, $actor ?: get_current_user_id() ),
				'site_id'      => $site_id,
				'uuid'         => strtolower( (string) $site['uuid'] ),
				'action'       => $action,
				'plugin'       => $slug,
				'package'      => 'install' === $action ? (string) $package['sha256'] : '',
			),
			JSON_UNESCAPED_SLASHES
		);
		$path   = self::root() . '/requests/' . $op . '.json';
		$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new \WP_Error( 'pchq_plugin_queue', '任务没能提交，请重试。' );
		}
		chmod( $path, 0600 );
		$written = fwrite( $handle, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( strlen( $body ) !== $written ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'pchq_plugin_queue', '任务没能完整提交，请重试。' );
		}
		$row = array(
			'site_id'      => $site_id,
			'slug'         => $slug,
			'plugin_id'    => 'install' === $action ? (int) $package['id'] : (int) ( $current['plugin_id'] ?? 0 ),
			'state'        => 'install' === $action ? 'installing' : 'removing',
			'operation_id' => $op,
			'error'        => '',
			'updated'      => time(),
		);
		if ( $current ) {
			$wpdb->update( $s, $row, array( 'id' => (int) $current['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( $s, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return true;
	}

	/** 读 root 任务写的回执（root 所有、普通文件、有界），更新店铺上的插件状态。 */
	public static function sync(): void {
		$root = self::root();
		if ( '' === $root || is_link( $root ) || is_link( $root . '/results' ) ) {
			return;
		}
		global $wpdb;
		$s    = Db::table( 'site_plugins' );
		$rows = $wpdb->get_results( "SELECT * FROM {$s} WHERE state IN ('installing','removing')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $r ) {
			if ( ! preg_match( '/^[0-9a-f-]{36}$/D', (string) $r['operation_id'] ) ) {
				continue;
			}
			$receipt = self::receipt( $root . '/results/' . $r['operation_id'] . '.json' );
			if ( ! $receipt || (int) $receipt['site_id'] !== (int) $r['site_id'] || $receipt['plugin'] !== $r['slug'] || $receipt['operation_id'] !== $r['operation_id'] ) {
				if ( (int) $r['updated'] < time() - 3600 ) {
					$wpdb->update( $s, array( 'state' => 'failed', 'error' => '超过 1 小时没有结果，请检查后台任务。' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				continue;
			}
			if ( 'done' === $receipt['state'] ) {
				$next = 'install' === $receipt['action'] ? 'active' : 'removed';
				$wpdb->update( $s, array( 'state' => $next, 'error' => '', 'installed_version' => 'install' === $receipt['action'] ? (string) $receipt['version'] : '', 'updated' => time() ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$msg = sanitize_text_field( (string) $receipt['message'] ) . ' （' . sanitize_key( (string) $receipt['error_code'] ) . '）';
				$wpdb->update( $s, array( 'state' => 'failed', 'error' => mb_substr( $msg, 0, 250 ), 'updated' => time() ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	private static function receipt( string $path ): ?array {
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$st = lstat( $path );
		if ( ! $st || 0 !== $st['uid'] || 1 !== $st['nlink'] || $st['size'] > 8192 || ( $st['mode'] & 0022 ) ) {
			return null;
		}
		$p = json_decode( (string) file_get_contents( $path, false, null, 0, 8193 ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$keys = array( 'operation_id', 'site_id', 'uuid', 'action', 'plugin', 'state', 'version', 'name', 'error_code', 'message', 'finished_at' );
		if ( ! is_array( $p ) || array_diff( array_keys( $p ), $keys ) || ! in_array( $p['state'] ?? '', array( 'done', 'failed' ), true ) || ! in_array( $p['action'] ?? '', array( 'install', 'uninstall' ), true ) ) {
			return null;
		}
		return $p;
	}

	/** 新店第一次注册时，自动安装库里勾了“新店自动安装”的插件。 */
	public static function auto_install( int $site_id ): void {
		foreach ( self::library() as $p ) {
			if ( (int) $p['auto_new'] ) {
				self::request( $site_id, (string) $p['slug'], 'install', (int) $p['uploaded_by'] );
			}
		}
	}
}
