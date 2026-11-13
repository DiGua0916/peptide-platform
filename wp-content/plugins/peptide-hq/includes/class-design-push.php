<?php
/**
 * 系统模板下发（2026-11-08 第二版）。
 *
 * 模板在“模板工作室”店铺里制作（总后台“模板库”里直接装修）；勾选“下发给店铺”的就是系统模板。
 * 下发只更新各店铺里对应的系统模板（只读），店铺自己的修订版和店铺选用哪个模板都不变。
 * 浏览器请求只做导出和排队（总后台 PHP 90 秒超时）；真正下发由每分钟的后台任务逐个店铺执行。
 * 每 5 分钟检查一次系统模板的修改时间，变了自动下发；新开的店铺注册后自动下发。
 *
 * 按系列下发（HQ-06，2026-11-10）：每个系列有自己的模板工作室店铺（母版店）、自动下发开关和下发任务，
 * 一个系列下发不影响别的系列。默认系列（“系列 1”）沿用原来的选项名，所以升级前后行为一样；
 * 其他系列的选项名带 _s<系列编号>。“模板库”页面显示的是当前选中的系列（在“系列管理”里切换），
 * 定时任务（自动下发、新店下发、后台逐店下发）对每个系列分别处理。整套模板下发一次，系列的 template_ver 加一。
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
		add_action( 'admin_notices', array( self::class, 'series_notice' ) );
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

	// ---------- 系列 ----------

	/** 系列功能是否可用（表结构升级好了）。不可用时一切照旧：所有店铺算一个整体。 */
	private static function series_ready(): bool {
		return class_exists( Series::class ) && Series::ready();
	}

	/** 当前在哪个系列下操作模板（“模板库”页面按它显示）。每位管理员各记各的；没选过时是默认系列。 */
	public static function current_series(): int {
		if ( ! self::series_ready() ) {
			return 0;
		}
		$want = (int) get_user_meta( get_current_user_id(), 'pchq_design_series', true );
		return $want > 0 && isset( Series::all()[ $want ] ) ? $want : Series::default_id();
	}

	/** 切换“模板库”页面操作的系列。 */
	public static function use_series( int $series_id ): void {
		if ( self::series_ready() && isset( Series::all()[ $series_id ] ) && get_current_user_id() > 0 ) {
			update_user_meta( get_current_user_id(), 'pchq_design_series', $series_id );
		}
	}

	/** null = 当前选中的系列。 */
	private static function sid( ?int $series ): int {
		return null === $series ? self::current_series() : ( self::series_ready() ? max( 0, $series ) : 0 );
	}

	/** 定时任务要处理的系列；系列功能不可用时只有 0（全部店铺）。 */
	private static function series_ids(): array {
		return self::series_ready() ? array_keys( Series::all() ) : array( 0 );
	}

	private static function is_default( int $series ): bool {
		return $series < 1 || ! self::series_ready() || $series === Series::default_id();
	}

	/** 选项名：默认系列沿用原来的名字，其他系列带 _s<编号>。 */
	private static function opt( string $suffix, int $series ): string {
		return self::is_default( $series ) ? self::OPTION . $suffix : self::OPTION . '_s' . $series . $suffix;
	}

	private static function job_opt( int $series ): string {
		return self::is_default( $series ) ? self::JOB : self::JOB . '_s' . $series;
	}

	/** “模板库”页面上提示现在操作的是哪个系列（只有一个系列时不提示）。 */
	public static function series_notice(): void {
		if ( 'pchq-design' !== ( $_GET['page'] ?? '' ) || ! self::series_ready() || count( Series::all() ) < 2 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$series = Series::get( self::current_series() );
		if ( $series ) {
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( '现在显示和下发的是系列“%s”的模板，只会发给这个系列的店铺。要换系列，请到“系列管理”里点“去模板库”。', $series['name'] ) ) . '</p></div>';
		}
	}

	// ---------- 设置 ----------

	public static function settings( ?int $series = null ): array {
		$o = get_option( self::opt( '', self::sid( $series ) ), array() );
		$o = is_array( $o ) ? $o : array();
		return array( 'master' => (int) ( $o['master'] ?? 0 ), 'auto' => ! array_key_exists( 'auto', $o ) || ! empty( $o['auto'] ) );
	}

	public static function save_settings( int $master, ?bool $auto = null, ?int $series = null ): void {
		$sid  = self::sid( $series );
		$auto = null === $auto ? self::settings( $sid )['auto'] : $auto;
		update_option( self::opt( '', $sid ), array( 'master' => $master, 'auto' => $auto ), false );
	}

	/** 这个系列的店铺（系列功能不可用时是全部店铺）。 */
	public static function sites( ?int $series = null ): array {
		$all = array_values( array_filter( Sites::all(), static fn( array $s ): bool => 'retired' !== ( $s['status'] ?? '' ) && '' !== (string) $s['uuid'] ) );
		$sid = self::sid( $series );
		if ( $sid < 1 ) {
			return $all;
		}
		$map = Series::site_map();
		return array_values( array_filter( $all, static fn( array $s ): bool => $sid === ( $map[ (int) $s['id'] ]['series_id'] ?? 0 ) ) );
	}

	/** 模板工作室店铺：设置里选的；没选时用系列的母版店；都没有时用这个系列里排在最前的店铺。 */
	public static function master_id( ?int $series = null ): int {
		$sid   = self::sid( $series );
		$want  = self::settings( $sid )['master'];
		$sites = self::sites( $sid );
		$ids   = array_map( static fn( array $s ): int => (int) $s['id'], $sites );
		if ( in_array( $want, $ids, true ) ) {
			return $want;
		}
		$series_master = $sid > 0 ? (int) ( Series::get( $sid )['master_site_id'] ?? 0 ) : 0;
		if ( $series_master && in_array( $series_master, $ids, true ) ) {
			return $series_master;
		}
		return $ids[0] ?? 0;
	}

	public static function job( ?int $series = null ): ?array {
		$j = get_option( self::job_opt( self::sid( $series ) ), null );
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

	public static function latest( ?int $series = null ): array {
		$l = get_option( self::opt( '_latest', self::sid( $series ) ), array() );
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
	public static function templates( ?int $series = null ) {
		$master = self::master_id( $series );
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
	public static function studio( array $payload, ?int $series = null ) {
		$master = self::master_id( $series );
		if ( ! $master ) {
			return new \WP_Error( 'pchq_design_master', '还没有模板工作室店铺。' );
		}
		$res = Client::design_template( $master, $payload );
		return is_wp_error( $res ) ? new \WP_Error( 'pchq_design_studio', self::friendly( $res ) ) : $res;
	}

	/**
	 * 导出这个系列的所有系统模板并排队下发。$targets 为空 = 这个系列里除工作室外的所有店铺。
	 * 别的系列的店铺不会被选中，即使传了它们的编号。
	 *
	 * @return array|\WP_Error 排队的店铺 ID
	 */
	public static function start( array $targets = array(), ?int $series = null ) {
		$sid    = self::sid( $series );
		$master = self::master_id( $sid );
		$list   = self::templates( $sid );
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
		update_option( self::opt( '_latest', $sid ), array( 'templates' => $latest, 'at' => time() ), false );
		$all = array();
		foreach ( self::sites( $sid ) as $s ) {
			if ( (int) $s['id'] !== $master ) {
				$all[] = (int) $s['id'];
			}
		}
		$full    = ! $targets;
		$targets = $targets ? array_values( array_intersect( array_map( 'intval', $targets ), $all ) ) : $all;
		if ( ! $targets ) {
			return new \WP_Error( 'pchq_design_targets', $full ? '这个系列除模板工作室外还没有其他店铺；新开的店会自动收到系统模板。' : '选的店铺不在这个系列里（或者就是模板工作室），没有下发。' );
		}
		$old = self::job( $sid );
		if ( $old ) {
			$targets = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $old['targets'] ?? array() ) ), $targets ) ) );
		}
		update_option( self::job_opt( $sid ), array( 'bundles' => $bundles, 'source_host' => (string) ( $bundles[0]['source'] ?? '' ), 'targets' => $targets, 'started_at' => time(), 'series_id' => $sid ), false );
		foreach ( $targets as $id ) {
			self::result( $id, array( 'state' => 'queued', 'message' => '排队中，1 分钟内开始。' ) );
		}
		// 整套下发算一个新的模板版本；单独补发给一家店不算。
		if ( $full && $sid > 0 ) {
			Series::bump( $sid, 'template' );
		}
		wp_schedule_single_event( time(), self::RUN );
		return $targets;
	}

	/** 后台任务：每个系列各自的下发任务，逐个店铺下发，限时；没做完的留到下一分钟。 */
	public static function run(): void {
		$start = time();
		$more  = false;
		foreach ( self::series_ids() as $sid ) {
			$more = self::run_job( (int) $sid, $start ) || $more;
		}
		if ( $more ) {
			wp_schedule_single_event( time() + 30, self::RUN );
		}
	}

	/** 处理一个系列的下发任务。返回是否还有店铺没处理完。 */
	private static function run_job( int $sid, int $start ): bool {
		$job = self::job( $sid );
		if ( ! $job ) {
			return false;
		}
		$targets = array_map( 'intval', (array) ( $job['targets'] ?? array() ) );
		while ( $targets && time() - $start < self::BUDGET ) {
			$id   = array_shift( $targets );
			$site = Sites::get( $id );
			if ( ! $site || ! Site_Lifecycle::active( $id ) ) {
				self::result( $id, array( 'state' => 'failed', 'message' => '店铺已下线，跳过。' ) );
			} elseif ( $sid > 0 && Series::site( $id )['series_id'] !== $sid ) {
				self::result( $id, array( 'state' => 'failed', 'message' => '店铺已经不在这个系列里，跳过。' ) );
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
				// 下发成功后把系列的模板版本号告诉店铺（店铺记在 peptide_core_series 里）。
				if ( '' === $err && $sid > 0 && class_exists( Series_Push::class ) ) {
					Series_Push::queue_site( $id, 60 );
				}
			}
			$job['targets'] = $targets;
			update_option( self::job_opt( $sid ), $job, false );
		}
		if ( $targets ) {
			return true;
		}
		delete_option( self::job_opt( $sid ) );
		return false;
	}

	/** 自动下发：每个系列分别看，系统模板有新增或修改（含改名）就下发。第一次只记录基准。 */
	public static function watch(): void {
		foreach ( self::series_ids() as $sid ) {
			self::watch_series( (int) $sid );
		}
	}

	private static function watch_series( int $sid ): void {
		if ( ! self::settings( $sid )['auto'] || self::job( $sid ) || ! self::master_id( $sid ) ) {
			return;
		}
		$list = self::templates( $sid );
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
		$seen = get_option( self::opt( '_seen', $sid ), null );
		update_option( self::opt( '_seen', $sid ), $now, false );
		if ( ! is_array( $seen ) || $seen === $now || ! $now ) {
			return;
		}
		$res = self::start( array(), $sid );
		update_option( self::opt( '_auto_last', $sid ), array( 'at' => time(), 'ok' => ! is_wp_error( $res ), 'message' => is_wp_error( $res ) ? $res->get_error_message() : '' ), false );
	}

	/** 新店注册后：收到它所在系列的系统模板。 */
	public static function new_site( $site_id ): void {
		$site_id = (int) $site_id;
		if ( $site_id < 1 ) {
			return;
		}
		$sid = self::series_ready() ? Series::site( $site_id )['series_id'] : 0;
		if ( self::series_ready() && $sid < 1 ) {
			return; // 还没有归入系列的店不下发。
		}
		if ( $site_id !== self::master_id( $sid ) ) {
			self::start( array( $site_id ), $sid );
		}
	}
}
