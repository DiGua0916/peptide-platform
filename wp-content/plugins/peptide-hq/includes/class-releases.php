<?php
/**
 * 发布记录、发布锁和代码版本核对（M1-6）。
 *
 * - 表 {prefix}pchq_releases：每次发布一行（版本标签、提交、模块、各组件的清单哈希、各店结果、是否回滚、时间）。
 *   自己的版本选项 pchq_m1_db，只加表，不动 class-db.php 里的定义。
 * - 发布锁：同一时间只有一个发布在跑（选项 pchq_release_lock，2 小时自动过期）。
 * - 巡检 code_drift：店铺在 /hq/status 的 code.copies 里上报每份代码副本各组件的清单哈希；
 *   和最近一次成功发布的清单不一致就告警。发布进行中不报。
 * - 页面“发布记录”：每家店每份副本现在是什么版本、和最新发布是否一致，以及历史记录。
 * - WP-CLI（发布脚本 tools/deployment/release.py 用）：wp pchq-release start|site|finish|status|unlock|stores
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Releases {

	public const DB_OPTION  = 'pchq_m1_db';
	public const DB_VERSION = '1';
	public const LOCK       = 'pchq_release_lock';
	public const LOCK_TTL   = 2 * HOUR_IN_SECONDS;
	public const SLUG       = 'pchq-releases';
	public const STATES     = array( 'running', 'done', 'failed', 'rolled_back', 'aborted' );

	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'maybe_install' ) );
		add_filter( 'pchq_monitor_checks', array( self::class, 'drift_check' ), 10, 4 );
		add_filter( 'pchq_alert_types', array( self::class, 'alert_types' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ), 30 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pchq-release', array( self::class, 'cli' ) );
		}
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pchq_releases';
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 label varchar(60) NOT NULL DEFAULT '',
 git_commit varchar(40) NOT NULL DEFAULT '',
 kind varchar(20) NOT NULL DEFAULT 'normal',
 modules varchar(255) NOT NULL DEFAULT '',
 note text NOT NULL,
 manifest longtext NOT NULL,
 results longtext NOT NULL,
 status varchar(20) NOT NULL DEFAULT 'running',
 operator varchar(60) NOT NULL DEFAULT '',
 started bigint(20) unsigned NOT NULL DEFAULT 0,
 finished bigint(20) unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (id),
 KEY status (status, finished)
) " . $wpdb->get_charset_collate() . ';'
		);
		// 表真的在才记版本号：整库复制只带选项不带表时，下次还会再建（见 2026-11-12 “版本号在、表不在”）。
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	// ---------- 发布锁 ----------

	/** @return array{id:int,by:string,at:int}|null 当前有效的锁 */
	public static function lock(): ?array {
		$lock = get_option( self::LOCK );
		if ( ! is_array( $lock ) || time() - (int) ( $lock['at'] ?? 0 ) > self::LOCK_TTL ) {
			return null;
		}
		return array( 'id' => (int) ( $lock['id'] ?? 0 ), 'by' => (string) ( $lock['by'] ?? '' ), 'at' => (int) $lock['at'] );
	}

	// ---------- 记录 ----------

	/**
	 * 开始一次发布：拿锁、建记录。
	 *
	 * @param array{label?:string,commit?:string,kind?:string,modules?:string,note?:string,operator?:string,manifest?:array} $in
	 * @return int|\WP_Error 发布编号
	 */
	public static function start( array $in ) {
		self::maybe_install();
		$held = self::lock();
		if ( $held ) {
			return new \WP_Error( 'pchq_release_locked', sprintf( '已有发布在进行（编号 %1$d，%2$s，%3$s 开始）。等它结束，或确认它已经中断后用 unlock 解锁。', $held['id'], $held['by'], gmdate( 'Y-m-d H:i', $held['at'] ) . ' UTC' ) );
		}
		$manifest = self::clean_manifest( $in['manifest'] ?? array() );
		if ( ! $manifest ) {
			return new \WP_Error( 'pchq_release_manifest', '发布清单里没有任何组件。' );
		}
		global $wpdb;
		$now = time();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'label'      => mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 60 ),
				'git_commit' => substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $in['commit'] ?? '' ) ) ), 0, 40 ),
				'kind'       => 'emergency' === ( $in['kind'] ?? '' ) ? 'emergency' : 'normal',
				'modules'    => mb_substr( sanitize_text_field( (string) ( $in['modules'] ?? '' ) ), 0, 255 ),
				'note'       => mb_substr( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ), 0, 2000 ),
				'manifest'   => (string) wp_json_encode( array( 'components' => $manifest ) ),
				'results'    => '{}',
				'status'     => 'running',
				'operator'   => mb_substr( sanitize_text_field( (string) ( $in['operator'] ?? '' ) ), 0, 60 ),
				'started'    => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'pchq_release_db', '发布记录写不进去。' );
		}
		update_option( self::LOCK, array( 'id' => $id, 'by' => (string) ( $in['operator'] ?? '' ), 'at' => $now ), false );
		return $id;
	}

	/**
	 * 只保留格式正确的组件：名字 => { version, hash(64 位十六进制), files }。
	 *
	 * @param mixed $raw
	 * @return array<string,array{version:string,hash:string,files:int}>
	 */
	public static function clean_manifest( $raw ): array {
		$raw = is_array( $raw ) && isset( $raw['components'] ) ? $raw['components'] : $raw;
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $name => $c ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9-]{1,40}$/D', $name ) || ! is_array( $c ) || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $c['hash'] ?? '' ) ) ) {
				continue;
			}
			$out[ $name ] = array(
				'version' => substr( preg_replace( '/[^A-Za-z0-9._+-]/', '', (string) ( $c['version'] ?? '' ) ), 0, 32 ),
				'hash'    => (string) $c['hash'],
				'files'   => max( 0, (int) ( $c['files'] ?? 0 ) ),
			);
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** 记一份副本的结果。$key 例如 "s0006/web"、"hq"；$state：ok / failed / rolled_back / skipped。 */
	public static function site_result( int $id, string $key, string $state, string $detail = '' ): bool {
		$row = self::get( $id );
		if ( ! $row || 'running' !== $row['status'] ) {
			return false;
		}
		$results         = $row['results'];
		$results[ $key ] = array(
			'state'  => in_array( $state, array( 'ok', 'failed', 'rolled_back', 'skipped' ), true ) ? $state : 'failed',
			'detail' => mb_substr( sanitize_text_field( $detail ), 0, 300 ),
			'at'     => time(),
		);
		global $wpdb;
		return false !== $wpdb->update( self::table(), array( 'results' => (string) wp_json_encode( $results ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** 结束一次发布并释放锁。 */
	public static function finish( int $id, string $status, string $note = '' ): bool {
		$row = self::get( $id );
		if ( ! $row || ! in_array( $status, array( 'done', 'failed', 'rolled_back', 'aborted' ), true ) ) {
			return false;
		}
		global $wpdb;
		$fields = array( 'status' => $status, 'finished' => time() );
		if ( '' !== $note ) {
			$fields['note'] = mb_substr( trim( $row['note'] . "\n" . sanitize_textarea_field( $note ) ), 0, 2000 );
		}
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lock = self::lock();
		if ( $lock && $lock['id'] === $id ) {
			delete_option( self::LOCK );
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ? self::decode( $row ) : null;
	}

	/** 最近一次成功的发布（代码版本以它为准）。 */
	public static function latest_done(): ?array {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table() . " WHERE status = 'done' ORDER BY finished DESC, id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ? self::decode( $row ) : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function history( int $limit = 30 ): array {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return array();
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 200, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_map( array( self::class, 'decode' ), $rows );
	}

	private static function decode( array $row ): array {
		$manifest          = json_decode( (string) $row['manifest'], true );
		$results           = json_decode( (string) $row['results'], true );
		$row['id']         = (int) $row['id'];
		$row['started']    = (int) $row['started'];
		$row['finished']   = (int) $row['finished'];
		$row['components'] = self::clean_manifest( is_array( $manifest ) ? $manifest : array() );
		$row['results']    = is_array( $results ) ? $results : array();
		return $row;
	}

	// ---------- 代码版本核对 ----------

	/**
	 * 一家店上报的各副本和发布清单比。纯函数。
	 *
	 * @param array $status   店铺 /hq/status 的返回
	 * @param array $expected 组件 => { hash, version }
	 * @param int   $since    发布完成的时间：在这之前登记的副本还没刷新，不比（回答这次请求的那份副本总是比）
	 * @return array<string,array{ok:bool,copies:array<int,array{path:string,have:string,version:string}>,checked:int}> 组件 => 结果
	 */
	public static function compare( array $status, array $expected, int $since = 0 ): array {
		$code   = is_array( $status['code'] ?? null ) ? $status['code'] : array();
		$current = (string) ( $code['this'] ?? '' );
		$copies = is_array( $code['copies'] ?? null ) ? $code['copies'] : array();
		$out    = array();
		foreach ( $copies as $id => $copy ) {
			if ( ! is_array( $copy ) || ! is_array( $copy['components'] ?? null ) ) {
				continue;
			}
			if ( (string) $id !== $current && (int) ( $copy['at'] ?? 0 ) < $since ) {
				continue;
			}
			foreach ( $copy['components'] as $name => $c ) {
				if ( ! isset( $expected[ $name ] ) || ! is_array( $c ) ) {
					continue;
				}
				$out[ $name ] ??= array( 'ok' => true, 'copies' => array(), 'checked' => 0 );
				++$out[ $name ]['checked'];
				if ( ! hash_equals( (string) $expected[ $name ]['hash'], (string) ( $c['hash'] ?? '' ) ) ) {
					$out[ $name ]['ok']       = false;
					$out[ $name ]['copies'][] = array( 'path' => (string) ( $copy['path'] ?? $id ), 'have' => substr( (string) ( $c['hash'] ?? '' ), 0, 12 ), 'version' => (string) ( $c['version'] ?? '' ) );
				}
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** 巡检项 code_drift：每个组件一条，ref = 组件名。不适用（旧版店铺、还没有成功的发布、发布进行中）时不报。 */
	public static function drift_check( $checks, $site_id = 0, $status = array(), $now = 0 ): array {
		$checks = is_array( $checks ) ? $checks : array();
		if ( ! is_array( $status ) || ! is_array( $status['code']['copies'] ?? null ) || self::lock() ) {
			return $checks;
		}
		$release = self::latest_done();
		if ( ! $release || ! $release['components'] ) {
			return $checks;
		}
		foreach ( self::compare( $status, $release['components'], $release['finished'] ) as $name => $r ) {
			$paths    = implode( '；', array_map( static fn( array $c ): string => $c['path'] . '（' . ( '' !== $c['version'] ? $c['version'] . '，' : '' ) . $c['have'] . '…）', array_slice( $r['copies'], 0, 3 ) ) );
			$checks[] = array(
				'type'    => 'code_drift',
				'ref'     => $name,
				'ok'      => $r['ok'],
				'message' => $r['ok'] ? '' : mb_substr( sprintf( '%1$s 的代码和最近一次发布（%2$s）不一致：%3$s。可能有人直接改了服务器上的文件，或者上次发布漏了这份副本。', $name, '' !== $release['label'] ? $release['label'] : '#' . $release['id'], $paths ), 0, 255 ),
			);
		}
		return $checks;
	}

	public static function alert_types( $types ): array {
		return ( is_array( $types ) ? $types : array() ) + array( 'code_drift' => '代码版本不一致' );
	}

	// ---------- 页面 ----------

	public static function menu(): void {
		add_submenu_page( 'pchq', '发布记录', '发布记录', 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'peptide-hq' ), 403 );
		}
		self::maybe_install();
		$latest   = self::latest_done();
		$expected = $latest ? $latest['components'] : array();
		$lock     = self::lock();
		$when     = static fn( int $t ): string => $t ? wp_date( 'Y-m-d H:i', $t ) : '—';
		echo '<div class="wrap"><h1>发布记录</h1>';
		if ( $lock ) {
			echo '<div class="notice notice-warning inline"><p>有一次发布正在进行：编号 ' . (int) $lock['id'] . '，' . esc_html( $lock['by'] ) . '，' . esc_html( $when( $lock['at'] ) ) . ' 开始。发布结束前不核对代码版本。</p></div>';
		}
		if ( $latest ) {
			echo '<p>最近一次成功的发布：<strong>' . esc_html( '' !== $latest['label'] ? $latest['label'] : '#' . $latest['id'] ) . '</strong>，提交 <code>' . esc_html( substr( $latest['git_commit'], 0, 7 ) ) . '</code>，' . esc_html( $when( $latest['finished'] ) ) . ' 完成。各店的代码以它为准。</p>';
		} else {
			echo '<p>还没有通过发布脚本完成的发布，所以现在只显示各店上报的版本，不判断是否一致。</p>';
		}

		echo '<h2>各店现在的代码</h2><table class="widefat striped"><thead><tr><th>店铺</th><th>副本</th><th>组件和版本</th><th>和最近发布比</th><th>上报时间</th></tr></thead><tbody>';
		foreach ( Sites::all() as $id => $site ) {
			$info   = is_array( $site['info'] ?? null ) ? $site['info'] : array();
			$copies = is_array( $info['code']['copies'] ?? null ) ? $info['code']['copies'] : array();
			if ( ! $copies ) {
				echo '<tr><td>' . esc_html( (string) $site['name'] ) . '</td><td colspan="4">这家店的程序还不会上报代码版本（要先升级到带版本上报的 peptide-core）。</td></tr>';
				continue;
			}
			foreach ( $copies as $copy ) {
				if ( ! is_array( $copy ) ) {
					continue;
				}
				$parts = array();
				$bad   = array();
				foreach ( (array) ( $copy['components'] ?? array() ) as $name => $c ) {
					$parts[] = esc_html( $name . ' ' . (string) ( $c['version'] ?? '' ) ) . ' <code>' . esc_html( substr( (string) ( $c['hash'] ?? '' ), 0, 8 ) ) . '</code>';
					if ( isset( $expected[ $name ] ) && ! hash_equals( (string) $expected[ $name ]['hash'], (string) ( $c['hash'] ?? '' ) ) ) {
						$bad[] = $name;
					}
				}
				$verdict = ! $expected ? '—' : ( $bad ? '<strong style="color:#b32d2e">不一致：' . esc_html( implode( '、', $bad ) ) . '</strong>' : '一致' );
				echo '<tr><td>' . esc_html( (string) $site['name'] ) . '</td><td><code>' . esc_html( (string) ( $copy['path'] ?? '' ) ) . '</code><br>' . esc_html( (string) ( $copy['host'] ?? '' ) ) . '</td><td>' . implode( '<br>', $parts ) . '</td><td>' . $verdict . '</td><td>' . esc_html( $when( (int) ( $copy['at'] ?? 0 ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		echo '</tbody></table>';

		echo '<h2>历史</h2><table class="widefat striped"><thead><tr><th>编号</th><th>版本</th><th>提交</th><th>模块</th><th>结果</th><th>各副本</th><th>开始</th><th>结束</th><th>操作人</th></tr></thead><tbody>';
		$labels = array( 'running' => '进行中', 'done' => '成功', 'failed' => '失败', 'rolled_back' => '已回滚', 'aborted' => '中止' );
		$rows   = self::history();
		foreach ( $rows as $r ) {
			$states = array_count_values( array_map( static fn( $x ) => (string) ( $x['state'] ?? '' ), $r['results'] ) );
			$sum    = array();
			foreach ( array( 'ok' => '成功', 'failed' => '失败', 'rolled_back' => '回滚', 'skipped' => '跳过' ) as $k => $label ) {
				if ( ! empty( $states[ $k ] ) ) {
					$sum[] = $label . ' ' . $states[ $k ];
				}
			}
			echo '<tr><td>' . (int) $r['id'] . '</td><td>' . esc_html( $r['label'] ) . ( 'emergency' === $r['kind'] ? '（紧急）' : '' ) . '</td><td><code>' . esc_html( substr( $r['git_commit'], 0, 7 ) ) . '</code></td><td>' . esc_html( $r['modules'] ) . '</td><td>' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</td><td>' . esc_html( implode( '，', $sum ) ) . '</td><td>' . esc_html( $when( $r['started'] ) ) . '</td><td>' . esc_html( $when( $r['finished'] ) ) . '</td><td>' . esc_html( $r['operator'] ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="9">还没有记录。</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ---------- WP-CLI（发布脚本用）----------

	/**
	 * 发布记录和发布锁。
	 *
	 * ## EXAMPLES
	 *
	 *     wp pchq-release start --label=2026.11.13-1 --commit=abc1234 --modules=M1,M4 --operator=F1 --manifest=/path/manifest.json
	 *     wp pchq-release site 12 s0006/web ok
	 *     wp pchq-release finish 12 done
	 *     wp pchq-release status
	 *     wp pchq-release stores
	 *     wp pchq-release unlock --yes
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public static function cli( array $args, array $assoc ): void {
		$cmd = (string) ( $args[0] ?? '' );
		self::maybe_install();
		if ( 'start' === $cmd ) {
			$file     = (string) ( $assoc['manifest'] ?? '' );
			$manifest = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$id       = self::start( array( 'label' => $assoc['label'] ?? '', 'commit' => $assoc['commit'] ?? '', 'kind' => $assoc['kind'] ?? 'normal', 'modules' => $assoc['modules'] ?? '', 'note' => $assoc['note'] ?? '', 'operator' => $assoc['operator'] ?? '', 'manifest' => is_array( $manifest ) ? $manifest : array() ) );
			if ( is_wp_error( $id ) ) {
				\WP_CLI::error( $id->get_error_message() );
			}
			\WP_CLI::line( (string) wp_json_encode( array( 'id' => $id ) ) );
		} elseif ( 'site' === $cmd ) {
			self::site_result( (int) ( $args[1] ?? 0 ), (string) ( $args[2] ?? '' ), (string) ( $args[3] ?? '' ), (string) ( $assoc['detail'] ?? '' ) ) ? \WP_CLI::line( '{"ok":true}' ) : \WP_CLI::error( '没有这条进行中的发布记录。' );
		} elseif ( 'finish' === $cmd ) {
			self::finish( (int) ( $args[1] ?? 0 ), (string) ( $args[2] ?? '' ), (string) ( $assoc['note'] ?? '' ) ) ? \WP_CLI::line( '{"ok":true}' ) : \WP_CLI::error( '结束失败：编号或状态不对（done / failed / rolled_back / aborted）。' );
		} elseif ( 'status' === $cmd ) {
			$latest = self::latest_done();
			\WP_CLI::line( (string) wp_json_encode( array( 'lock' => self::lock(), 'latest' => $latest ? array( 'id' => $latest['id'], 'label' => $latest['label'], 'commit' => $latest['git_commit'], 'finished' => $latest['finished'], 'components' => $latest['components'] ) : null ) ) );
		} elseif ( 'stores' === $cmd ) {
			$out = array();
			foreach ( Sites::all() as $id => $site ) {
				$out[] = array( 'id' => (int) $id, 'name' => (string) $site['name'], 'status' => (string) $site['status'], 'management_url' => (string) ( $site['management_url'] ?? '' ), 'public_url' => (string) ( $site['public_url'] ?? '' ), 'active' => Site_Lifecycle::active( (int) $id ), 'code' => is_array( $site['info']['code'] ?? null ) ? $site['info']['code'] : null );
			}
			\WP_CLI::line( (string) wp_json_encode( $out ) );
		} elseif ( 'unlock' === $cmd ) {
			if ( empty( $assoc['yes'] ) ) {
				\WP_CLI::error( '解锁会让另一个发布可以开始。确认上一个发布已经中断后加 --yes。' );
			}
			$lock = self::lock();
			delete_option( self::LOCK );
			if ( $lock ) {
				self::finish( $lock['id'], 'aborted', '手工解锁' );
			}
			\WP_CLI::line( '{"ok":true}' );
		} else {
			\WP_CLI::error( '用法：wp pchq-release start|site|finish|status|stores|unlock' );
		}
	}
}
