<?php
/**
 * 系统模板下发（2026-11-08 第二版）。
 *
 * 模板在“模板工作室”店铺里制作（总后台“模板库”里直接装修）；勾选“下发给店铺”的就是系统模板。
 * 下发只更新各店铺里对应的系统模板（只读），店铺自己的修订版和店铺选用哪个模板都不变。
 * 浏览器请求只做导出和排队（总后台 PHP 90 秒超时）；真正下发由每分钟的后台任务逐个店铺执行。
 * 每 5 分钟检查一次系统模板的修改时间，变了自动下发；新开的店铺注册后自动下发。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Design_Push {

	public const OPTION   = 'pchq_design';
	public const JOB      = 'pchq_design_job';
	public const RESULTS  = 'pchq_design_results';
	public const RUN      = 'pchq_design_run';
	public const NEW_SITE = 'pchq_design_new_site';
	public const WATCH    = 'pchq_design_watch';
	private const BUDGET  = 240;

	public static function register_hooks(): void {
		add_filter(
			'cron_schedules',
			static function ( array $s ): array {
				$s['pchq_design_5min'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (design)' );
				return $s;
			}
		);
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::WATCH ) ) {
					wp_schedule_event( time() + 120, 'pchq_design_5min', self::WATCH );
				}
			}
		);
		add_action( self::WATCH, array( self::class, 'watch' ) );
		add_action( self::RUN, array( self::class, 'run' ) );
		add_action( self::NEW_SITE, array( self::class, 'new_site' ), 10, 1 );
		add_action(
			'pchq_site_registered',
			static function ( $site_id ): void {
				wp_schedule_single_event( time() + 300, self::NEW_SITE, array( (int) $site_id ) );
			},
			20,
			1
		);
	}

	public static function settings(): array {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array( 'master' => (int) ( $o['master'] ?? 0 ), 'auto' => ! array_key_exists( 'auto', $o ) || ! empty( $o['auto'] ) );
	}

	public static function save_settings( int $master, ?bool $auto = null ): void {
		$auto = null === $auto ? self::settings()['auto'] : $auto;
		update_option( self::OPTION, array( 'master' => $master, 'auto' => $auto ), false );
	}

	public static function sites(): array {
		return array_values( array_filter( Sites::all(), static fn( array $s ): bool => 'retired' !== ( $s['status'] ?? '' ) && '' !== (string) $s['uuid'] ) );
	}

	/** 模板工作室店铺：设置里选的；没选时用最早的店铺（主店）。 */
	public static function master_id(): int {
		$want  = self::settings()['master'];
		$first = 0;
		foreach ( self::sites() as $s ) {
			if ( (int) $s['id'] === $want ) {
				return $want;
			}
			$first = $first ?: (int) $s['id'];
		}
		return $first;
	}

	public static function job(): ?array {
		$j = get_option( self::JOB, null );
		return is_array( $j ) ? $j : null;
	}

	public static function results(): array {
		$r = get_option( self::RESULTS, array() );
		return is_array( $r ) ? $r : array();
	}

	private static function result( int $site, array $fields ): void {
		$all          = self::results();
		$all[ $site ] = array_merge( $all[ $site ] ?? array(), $fields, array( 'at' => time() ) );
		update_option( self::RESULTS, $all, false );
	}

	public static function latest(): array {
		$l = get_option( self::OPTION . '_latest', array() );
		return is_array( $l ) ? $l : array();
	}

	public static function unreachable( array $site ): bool {
		return 'online' !== (string) ( $site['status'] ?? '' ) || '' !== (string) ( $site['error'] ?? '' );
	}

	public static function friendly( \WP_Error $e ): string {
		$code = $e->get_error_code();
		$msg  = $e->get_error_message();
		if ( 'pchq_offline' === $code || preg_match( '/cURL error|Could not resolve|timed out|Connection refused|HTTP status 5/i', $msg ) ) {
			return '连不上这个店铺（可能暂时离线或网址有变），这次没有改动。';
		}
		if ( 'pchq_denied' === $code ) {
			return '店铺拒绝了总后台的连接密钥，请到“店铺”页重新连接。';
		}
		if ( preg_match( '/No route was found|rest_no_route|HTTP status 404/i', $msg ) ) {
			return '这个店铺的程序版本较旧，还不支持模板下发，请先更新店铺程序。';
		}
		return $msg;
	}

	/** 工作室的模板清单。@return array|\WP_Error */
	public static function templates() {
		$master = self::master_id();
		if ( ! $master ) {
			return new \WP_Error( 'pchq_design_master', '还没有模板工作室店铺。' );
		}
		$res = Client::design_templates( $master );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_design_list', '读取模板失败：' . self::friendly( $res ) );
		}
		return array_values( array_filter( (array) ( $res['templates'] ?? array() ), 'is_array' ) );
	}

	/** 工作室操作（新建 / 改名 / 设为下发 / 删除）。@return array|\WP_Error */
	public static function studio( array $payload ) {
		$master = self::master_id();
		if ( ! $master ) {
			return new \WP_Error( 'pchq_design_master', '还没有模板工作室店铺。' );
		}
		$res = Client::design_template( $master, $payload );
		return is_wp_error( $res ) ? new \WP_Error( 'pchq_design_studio', self::friendly( $res ) ) : $res;
	}

	/**
	 * 导出所有系统模板并排队下发。$targets 为空 = 除工作室外的所有店铺。
	 *
	 * @return array|\WP_Error 排队的店铺 ID
	 */
	public static function start( array $targets = array() ) {
		$master = self::master_id();
		$list   = self::templates();
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$system = array_values( array_filter( $list, static fn( array $t ): bool => ! empty( $t['system'] ) ) );
		if ( ! $system ) {
			return new \WP_Error( 'pchq_design_none', '还没有勾选“下发给店铺”的模板。' );
		}
		$bundles = array();
		$latest  = array();
		foreach ( $system as $t ) {
			$b = Client::design_export( $master, (int) $t['id'] );
			if ( is_wp_error( $b ) ) {
				return new \WP_Error( 'pchq_design_export', '读取模板“' . $t['name'] . '”失败：' . self::friendly( $b ) );
			}
			if ( 1 !== ( $b['version'] ?? 0 ) || ! preg_match( '/\Am[0-9]+\z/D', (string) ( $b['key'] ?? '' ) ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', (string) ( $b['digest'] ?? '' ) ) ) {
				return new \WP_Error( 'pchq_design_export', '模板工作室返回的内容无效（工作室店铺程序可能需要更新）。' );
			}
			$bundles[]              = $b;
			$latest[ $b['key'] ]    = array( 'digest' => (string) $b['digest'], 'name' => (string) $b['name'] );
		}
		update_option( self::OPTION . '_latest', array( 'templates' => $latest, 'at' => time() ), false );
		$all = array();
		foreach ( self::sites() as $s ) {
			if ( (int) $s['id'] !== $master ) {
				$all[] = (int) $s['id'];
			}
		}
		$targets = $targets ? array_values( array_intersect( array_map( 'intval', $targets ), $all ) ) : $all;
		if ( ! $targets ) {
			return new \WP_Error( 'pchq_design_targets', '除模板工作室外还没有其他店铺；新开的店会自动收到系统模板。' );
		}
		$old = self::job();
		if ( $old ) {
			$targets = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $old['targets'] ?? array() ) ), $targets ) ) );
		}
		update_option( self::JOB, array( 'bundles' => $bundles, 'source_host' => (string) ( $bundles[0]['source'] ?? '' ), 'targets' => $targets, 'started_at' => time() ), false );
		foreach ( $targets as $id ) {
			self::result( $id, array( 'state' => 'queued', 'message' => '排队中，1 分钟内开始。' ) );
		}
		wp_schedule_single_event( time(), self::RUN );
		return $targets;
	}

	/** 后台任务：逐个店铺下发所有系统模板，限时；没做完的留到下一分钟。 */
	public static function run(): void {
		$job = self::job();
		if ( ! $job ) {
			return;
		}
		$start   = time();
		$targets = array_map( 'intval', (array) ( $job['targets'] ?? array() ) );
		while ( $targets && time() - $start < self::BUDGET ) {
			$id   = array_shift( $targets );
			$site = Sites::get( $id );
			if ( ! $site || ! Site_Lifecycle::active( $id ) ) {
				self::result( $id, array( 'state' => 'failed', 'message' => '店铺已下线，跳过。' ) );
			} elseif ( self::unreachable( $site ) ) {
				self::result( $id, array( 'state' => 'failed', 'message' => '店铺连接异常，跳过；恢复后点“单独下发”。' ) );
			} else {
				self::result( $id, array( 'state' => 'running', 'message' => '正在下发（首次可能要下载视频）…' ) );
				$last = null;
				$err  = '';
				foreach ( (array) $job['bundles'] as $bundle ) {
					$res = Client::design_apply( $id, (array) $bundle, false, (string) $job['source_host'] );
					if ( is_wp_error( $res ) ) {
						$err = '“' . ( $bundle['name'] ?? '' ) . '”：' . self::friendly( $res );
						break;
					}
					$last = $res;
				}
				if ( is_array( $last ) ) {
					$info           = is_array( $site['info'] ?? null ) ? $site['info'] : array();
					$info['design'] = $last;
					Sites::update( $id, array( 'info' => (string) wp_json_encode( $info ) ) );
				}
				self::result( $id, '' === $err ? array( 'state' => 'done', 'message' => '' ) : array( 'state' => 'failed', 'message' => $err ) );
			}
			$job['targets'] = $targets;
			update_option( self::JOB, $job, false );
		}
		if ( $targets ) {
			wp_schedule_single_event( time() + 30, self::RUN );
		} else {
			delete_option( self::JOB );
		}
	}

	/** 自动下发：系统模板有新增或修改（含改名）就下发。第一次只记录基准。 */
	public static function watch(): void {
		if ( ! self::settings()['auto'] || self::job() ) {
			return;
		}
		$list = self::templates();
		if ( is_wp_error( $list ) ) {
			return;
		}
		$now = array();
		foreach ( $list as $t ) {
			if ( ! empty( $t['system'] ) ) {
				$now[ (int) $t['id'] ] = (string) $t['modified'] . '|' . (string) $t['name'];
			}
		}
		ksort( $now );
		$seen = get_option( self::OPTION . '_seen', null );
		update_option( self::OPTION . '_seen', $now, false );
		if ( ! is_array( $seen ) || $seen === $now || ! $now ) {
			return;
		}
		$res = self::start();
		update_option( self::OPTION . '_auto_last', array( 'at' => time(), 'ok' => ! is_wp_error( $res ), 'message' => is_wp_error( $res ) ? $res->get_error_message() : '' ), false );
	}

	public static function new_site( $site_id ): void {
		$site_id = (int) $site_id;
		if ( $site_id > 0 && $site_id !== self::master_id() ) {
			self::start( array( $site_id ) );
		}
	}
}
