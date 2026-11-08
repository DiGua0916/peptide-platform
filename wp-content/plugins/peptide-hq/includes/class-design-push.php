<?php
/**
 * 统一装修下发（2026-11-08）：总后台从母版店导出已发布的装修，排队下发给其他店铺。
 *
 * 浏览器请求只做导出和排队（总后台 PHP 90 秒超时）；真正下发由每分钟的后台任务逐个店铺执行
 * （店铺可能要下载视频），每次最多约 4 分钟，没做完的下一分钟继续。新开的店铺注册后自动下发。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Design_Push {

	public const OPTION  = 'pchq_design';
	public const JOB     = 'pchq_design_job';
	public const RESULTS = 'pchq_design_results';
	public const RUN     = 'pchq_design_run';
	public const NEW_SITE = 'pchq_design_new_site';
	private const BUDGET = 240;

	public static function register_hooks(): void {
		add_action( self::RUN, array( self::class, 'run' ) );
		add_action( self::NEW_SITE, array( self::class, 'new_site' ), 10, 1 );
		add_action(
			'pchq_site_registered',
			static function ( $site_id ): void {
				// 新店刚注册时可能还在收尾，5 分钟后再下发。
				wp_schedule_single_event( time() + 300, self::NEW_SITE, array( (int) $site_id ) );
			},
			20,
			1
		);
	}

	public static function settings(): array {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array( 'master' => (int) ( $o['master'] ?? 0 ), 'lock' => ! array_key_exists( 'lock', $o ) || ! empty( $o['lock'] ) );
	}

	public static function save_settings( int $master, bool $lock ): void {
		update_option( self::OPTION, array( 'master' => $master, 'lock' => $lock ), false );
	}

	/** 在管的店铺（不含下线的）。 */
	public static function sites(): array {
		return array_values( array_filter( Sites::all(), static fn( array $s ): bool => 'retired' !== ( $s['status'] ?? '' ) && '' !== (string) $s['uuid'] ) );
	}

	/** 母版店：设置里选的；没选时用最早的店铺。 */
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
			return '这个店铺的程序版本较旧，还不支持统一装修，请先更新店铺程序。';
		}
		return $msg;
	}

	/**
	 * 从母版店导出并排队下发。$targets 为空 = 除母版店外的所有店铺。
	 *
	 * @return array|\WP_Error 排队的店铺 ID
	 */
	public static function start( array $targets = array() ) {
		$master = self::master_id();
		if ( ! $master ) {
			return new \WP_Error( 'pchq_design_master', '还没有母版店。' );
		}
		$bundle = Client::design_export( $master );
		if ( is_wp_error( $bundle ) ) {
			return new \WP_Error( 'pchq_design_export', '从母版店读取装修失败：' . self::friendly( $bundle ) );
		}
		if ( 1 !== ( $bundle['version'] ?? 0 ) || ! is_array( $bundle['documents'] ?? null ) || ! is_array( $bundle['media'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', (string) ( $bundle['digest'] ?? '' ) ) ) {
			return new \WP_Error( 'pchq_design_export', '母版店返回的装修内容无效（母版店程序可能需要更新）。' );
		}
		$all = array();
		foreach ( self::sites() as $s ) {
			if ( (int) $s['id'] !== $master ) {
				$all[] = (int) $s['id'];
			}
		}
		$targets = $targets ? array_values( array_intersect( array_map( 'intval', $targets ), $all ) ) : $all;
		if ( ! $targets ) {
			return new \WP_Error( 'pchq_design_targets', '除母版店外还没有其他店铺；新开的店会自动收到统一装修。' );
		}
		$old = self::job();
		if ( $old ) {
			$targets = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $old['targets'] ?? array() ) ), $targets ) ) );
		}
		$master_site = Sites::get( $master );
		update_option(
			self::JOB,
			array(
				'bundle'      => $bundle,
				'digest'      => (string) $bundle['digest'],
				'source_host' => (string) ( $bundle['source'] ?? wp_parse_url( Site_Urls::public_url( (array) $master_site ), PHP_URL_HOST ) ),
				'lock'        => self::settings()['lock'],
				'targets'     => $targets,
				'started_at'  => time(),
			),
			false
		);
		update_option( self::OPTION . '_latest', array( 'digest' => (string) $bundle['digest'], 'modified' => (string) ( $bundle['modified'] ?? '' ), 'at' => time(), 'media' => count( $bundle['media'] ) ), false );
		foreach ( $targets as $id ) {
			self::result( $id, array( 'state' => 'queued', 'message' => '排队中，1 分钟内开始。' ) );
		}
		wp_schedule_single_event( time(), self::RUN );
		return $targets;
	}

	public static function latest(): array {
		$l = get_option( self::OPTION . '_latest', array() );
		return is_array( $l ) ? $l : array();
	}

	/** 后台任务：逐个店铺下发，限时；没做完的留到下一分钟。 */
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
				$res = Client::design_apply( $id, (array) $job['bundle'], (bool) $job['lock'], (string) $job['source_host'] );
				if ( is_wp_error( $res ) ) {
					self::result( $id, array( 'state' => 'failed', 'message' => self::friendly( $res ) ) );
				} else {
					$info           = is_array( $site['info'] ?? null ) ? $site['info'] : array();
					$info['design'] = $res;
					Sites::update( $id, array( 'info' => (string) wp_json_encode( $info ) ) );
					self::result( $id, array( 'state' => 'done', 'message' => '', 'version' => (string) ( $res['version'] ?? '' ), 'locked' => ! empty( $res['locked'] ) ) );
				}
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

	public static function new_site( $site_id ): void {
		$site_id = (int) $site_id;
		if ( $site_id > 0 && $site_id !== self::master_id() ) {
			self::start( array( $site_id ) );
		}
	}

	/** 只改锁定（不重新下发）。@return array{ok:int,failed:array} */
	public static function lock_all( bool $lock ): array {
		$master = self::master_id();
		$ok     = 0;
		$failed = array();
		foreach ( self::sites() as $s ) {
			if ( (int) $s['id'] === $master ) {
				continue;
			}
			if ( self::unreachable( $s ) ) {
				$failed[] = ( '' !== (string) $s['name'] ? $s['name'] : $s['url'] ) . '（连接异常）';
				continue;
			}
			$r = Client::design_lock( (int) $s['id'], $lock );
			if ( is_wp_error( $r ) ) {
				$failed[] = ( '' !== (string) $s['name'] ? $s['name'] : $s['url'] ) . '：' . self::friendly( $r );
				continue;
			}
			$info           = is_array( $s['info'] ?? null ) ? $s['info'] : array();
			$info['design'] = $r;
			Sites::update( (int) $s['id'], array( 'info' => (string) wp_json_encode( $info ) ) );
			++$ok;
		}
		return array( 'ok' => $ok, 'failed' => $failed );
	}
}
