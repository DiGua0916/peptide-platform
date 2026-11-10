<?php
/**
 * 系列内容推送到店铺（HQ-05）：对系列里的每家店调用店铺的 POST /peptide/v1/hq/series/sync。
 *
 * - 每次请求都带系列的基本信息（series_id、slug、name、template_ver），店铺据此写 peptide_core_series。
 * - 商品（catalog）只发给“接受系列同步”并且差异报告已经确认过的店；活动（campaigns）、运费（shipping）
 *   只发给“接受系列同步”的店。店铺已经同步到的版本不重复发。
 * - 定时任务 pchq_series_push（单次）：失败按 1 / 5 / 30 分钟重试；之后每 10 分钟的巡检发现还落后会再排一次，
 *   所以推送中断后能自己补齐。店铺程序太旧（没有这个接口）时 6 小时后再试。
 * - 差异报告（dry_run）和恢复快照也从这里调用店铺。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Series_Push {

	public const HOOK    = 'pchq_series_push';
	public const STATE   = 'pchq_series_push_state';
	public const ROUTE   = '/peptide/v1/hq/series/sync';
	public const RESTORE = '/peptide/v1/hq/series/restore';

	/** 失败后第 1、2、3 次重试前等多少秒。 */
	public const RETRY = array( 60, 300, 1800 );

	/** 店铺没有这个接口时，多久之后再试。 */
	private const UNSUPPORTED_WAIT = 21600;

	/** 一次同步没做完（例如图片太多）时最多接着跑几轮。 */
	private const MAX_ROUNDS = 20;

	/** 店铺声明支持系列同步用的功能键（钩子 pc_hq_capabilities）。 */
	public const CAPABILITY = 'series.sync';

	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 3 );
		add_action( Monitor::CRON, array( self::class, 'reconcile' ), 30 );
		add_filter( 'pchq_monitor_checks', array( self::class, 'monitor_check' ), 10, 4 );
		add_filter( 'pchq_alert_types', array( self::class, 'alert_types' ) );
	}

	/**
	 * 店铺是否声明了支持系列同步。总后台还没有功能声明（Client::supports 不存在）时返回 null：照常尝试，
	 * 靠“没有这个接口”的回应来判断。
	 */
	public static function supported( int $site_id ): ?bool {
		return is_callable( array( Client::class, 'supports' ) ) ? (bool) Client::supports( $site_id, self::CAPABILITY ) : null;
	}

	public static function alert_types( $types ): array {
		return ( is_array( $types ) ? $types : array() ) + array( 'series_out_of_sync' => '系列内容未同步' );
	}

	/**
	 * 巡检项 series_out_of_sync：同步失败，或者落后超过 30 分钟还没补上。
	 * 店铺不支持系列同步、没有归入系列、或者还没尝试过同步时不报（不适用）。
	 */
	public static function monitor_check( $checks, $site_id, $status = array(), $now = 0 ): array {
		$checks  = is_array( $checks ) ? $checks : array();
		$site_id = (int) $site_id;
		$now     = (int) $now > 0 ? (int) $now : time();
		if ( ! Series::ready() || true !== self::supported( $site_id ) ) {
			return $checks;
		}
		$site   = Series::site( $site_id ) + array( 'site_id' => $site_id );
		$series = Series::get( $site['series_id'] );
		$state  = self::site_state( $site_id );
		if ( ! $series || $state['at'] < 1 ) {
			return $checks;
		}
		$need   = array_keys( array_filter( self::plan( $series, $site, $state['synced'], self::confirmed( $series['id'], $site_id ) ) ) );
		$late   = $need && ( ! $state['ok'] || $now - $state['at'] > 1800 );
		$labels = array( 'header' => '系列信息', 'catalog' => '商品和价格', 'campaigns' => '营销活动', 'shipping' => '运费' );
		$parts  = implode( '、', array_map( static fn( string $k ): string => $labels[ $k ] ?? $k, $need ) );
		$checks[] = array(
			'type'    => 'series_out_of_sync',
			'ok'      => ! $late,
			'message' => $late ? mb_substr( sprintf( '系列“%1$s”的内容还没同步到这家店（%2$s）。%3$s', $series['name'], $parts, '' !== $state['message'] ? '原因：' . $state['message'] : '系统会继续自动重试。' ), 0, 255 ) : '系列内容已同步。',
		);
		return $checks;
	}

	// ---------- 每家店的同步状态 ----------

	/** @return array{series_id:int,master:bool,catalog_ver:int,template_ver:int,campaign_ver:int,shipping_ver:int} */
	public static function versions( array $v ): array {
		return array(
			'series_id'    => max( 0, (int) ( $v['series_id'] ?? 0 ) ),
			'master'       => ! empty( $v['master'] ),
			'catalog_ver'  => max( 0, (int) ( $v['catalog_ver'] ?? 0 ) ),
			'template_ver' => max( 0, (int) ( $v['template_ver'] ?? 0 ) ),
			'campaign_ver' => max( 0, (int) ( $v['campaign_ver'] ?? 0 ) ),
			'shipping_ver' => max( 0, (int) ( $v['shipping_ver'] ?? 0 ) ),
		);
	}

	private static function all_states(): array {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	/** 一家店最近一次同步的情况，以及店铺回报的已同步版本。 */
	public static function site_state( int $site_id ): array {
		$s = self::all_states()[ $site_id ] ?? array();
		$s = is_array( $s ) ? $s : array();
		return array(
			'at'        => (int) ( $s['at'] ?? 0 ),
			'ok'        => ! empty( $s['ok'] ),
			'message'   => (string) ( $s['message'] ?? '' ),
			'next'      => (int) ( $s['next'] ?? 0 ),
			'supported' => ! array_key_exists( 'supported', $s ) || ! empty( $s['supported'] ),
			'synced'    => self::versions( (array) ( $s['synced'] ?? array() ) ),
		);
	}

	private static function save_state( int $site_id, array $fields ): void {
		$all             = self::all_states();
		$all[ $site_id ] = array_merge( is_array( $all[ $site_id ] ?? null ) ? $all[ $site_id ] : array(), $fields );
		update_option( self::STATE, $all, false );
	}

	/** 忘掉一家店的同步状态（换系列、恢复快照、店铺下线时），下次按“什么都没同步过”处理。 */
	public static function forget( int $site_id ): void {
		$all = self::all_states();
		if ( isset( $all[ $site_id ] ) ) {
			unset( $all[ $site_id ] );
			update_option( self::STATE, $all, false );
		}
	}

	// ---------- 决定发什么 ----------

	/** 这家店是不是这个系列的母版店（纯函数）。$site 里要有 site_id。 */
	public static function is_master( array $series, array $site ): bool {
		$site_id = (int) ( $site['site_id'] ?? 0 );
		return $site_id > 0 && $site_id === (int) ( $series['master_site_id'] ?? 0 );
	}

	/**
	 * 这次该给这家店发哪些部分（纯函数）。
	 *
	 * - 普通店铺：商品要“接受系列同步”并且差异报告已确认；活动、运费要“接受系列同步”。
	 * - 母版店：商品内容在母版店编辑，所以只把价格发回去（店铺端只改价格），不需要确认；不发活动（活动就是从它导入的）。
	 *
	 * @param array $series    系列（Series::get）
	 * @param array $site      店铺归属（Series::site）加 site_id，用到 series_sync
	 * @param array $synced    店铺已同步到的版本（versions()）
	 * @param bool  $confirmed 这家店的商品差异报告是否已确认
	 * @return array{header:bool,catalog:bool,campaigns:bool,shipping:bool}
	 */
	public static function plan( array $series, array $site, array $synced, bool $confirmed ): array {
		$accept = ! empty( $site['series_sync'] );
		$moved  = (int) $synced['series_id'] !== (int) $series['id'];
		$master = self::is_master( $series, $site );
		return array(
			'header'    => $moved || (int) $synced['template_ver'] < (int) $series['template_ver'] || $master !== ! empty( $synced['master'] ),
			'catalog'   => ( $master || ( $accept && $confirmed ) ) && (int) $series['catalog_ver'] > 0 && ( $moved || (int) $synced['catalog_ver'] < (int) $series['catalog_ver'] ),
			'campaigns' => ! $master && $accept && (int) $series['campaign_ver'] > 0 && ( $moved || (int) $synced['campaign_ver'] < (int) $series['campaign_ver'] ),
			'shipping'  => $accept && (int) $series['shipping_ver'] > 0 && ( $moved || (int) $synced['shipping_ver'] < (int) $series['shipping_ver'] ),
		);
	}

	private static function confirmed( int $series_id, int $site_id ): bool {
		return class_exists( Series_Catalog::class ) && Series_Catalog::confirmed( $series_id, $site_id );
	}

	/** 请求体（格式见 docs/CONTRACT.md “M3 系列与商品目录”）。 */
	public static function body( array $series, array $plan, bool $dry_run = false, bool $master = false ): array {
		$body = array(
			'series_id'    => (int) $series['id'],
			'slug'         => (string) $series['slug'],
			'name'         => (string) $series['name'],
			'template_ver' => (int) $series['template_ver'],
			'master'       => $master,
			'dry_run'      => $dry_run,
		);
		if ( ! empty( $plan['catalog'] ) && class_exists( Series_Catalog::class ) ) {
			$body['catalog'] = Series_Catalog::payload( (int) $series['id'] );
		}
		if ( ! empty( $plan['campaigns'] ) ) {
			$body['campaigns'] = Series_Campaigns::payload( (int) $series['id'] );
		}
		if ( ! empty( $plan['shipping'] ) && $series['shipping'] ) {
			$body['shipping'] = array(
				'ver'   => (int) $series['shipping_ver'],
				'plans' => (array) ( $series['shipping']['plans'] ?? array() ),
				'rules' => (array) ( $series['shipping']['rules'] ?? array() ),
			);
		}
		return $body;
	}

	// ---------- 推送 ----------

	/**
	 * 立即给一家店同步一次。
	 *
	 * @return array{synced:array,complete:bool,applied:array}|\WP_Error
	 */
	public static function push( int $site_id ) {
		$site   = Series::site( $site_id ) + array( 'site_id' => $site_id );
		$series = Series::get( $site['series_id'] );
		if ( ! $series ) {
			return new \WP_Error( 'pchq_series_none', '这家店还没有归入系列。' );
		}
		$known = self::site_state( $site_id );
		$plan  = self::plan( $series, $site, $known['synced'], self::confirmed( $series['id'], $site_id ) );
		$res   = Client::request( $site_id, 'POST', self::ROUTE, array(), self::body( $series, $plan, false, self::is_master( $series, $site ) ), 280 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$synced = self::versions( (array) ( $res['series'] ?? array() ) );
		if ( $synced['series_id'] !== $series['id'] ) {
			return new \WP_Error( 'pchq_series_reply', '店铺返回的系列信息不对，可能需要先更新店铺程序。' );
		}
		return array(
			'synced'   => $synced,
			'complete' => ! array_key_exists( 'complete', $res ) || ! empty( $res['complete'] ),
			'applied'  => (array) ( $res['applied'] ?? array() ),
		);
	}

	/** 店铺是不是还没有这个接口（程序太旧）。 */
	public static function is_unsupported( \WP_Error $e ): bool {
		return 1 === preg_match( '/No route was found|rest_no_route|HTTP status 404/i', $e->get_error_message() );
	}

	/** 定时任务：同步一家店；失败按 RETRY 重试，没做完就接着跑。 */
	public static function run( $site_id, $attempt = 0, $round = 0 ): void {
		$site_id = (int) $site_id;
		$attempt = (int) $attempt;
		$round   = (int) $round;
		if ( ! Series::ready() ) {
			return;
		}
		if ( ! Site_Lifecycle::active( $site_id ) || ! Sites::get( $site_id ) ) {
			self::forget( $site_id );
			return;
		}
		$now = time();
		if ( false === self::supported( $site_id ) ) {
			// 店铺还没声明支持：不发请求，等店铺程序更新后的下一次巡检再试。
			self::save_state(
				$site_id,
				array(
					'at'        => $now,
					'ok'        => false,
					'message'   => '这家店的程序还不支持系列同步，请先更新店铺程序。',
					'supported' => false,
					'next'      => $now + 600,
				)
			);
			return;
		}
		$res = self::push( $site_id );
		if ( is_wp_error( $res ) ) {
			$unsupported = self::is_unsupported( $res );
			$delay       = $unsupported ? null : ( self::RETRY[ $attempt ] ?? null );
			self::save_state(
				$site_id,
				array(
					'at'        => $now,
					'ok'        => false,
					'message'   => mb_substr( $unsupported ? '这家店的程序还不支持系列同步，请先更新店铺程序。' : Design_Push::friendly( $res ), 0, 250 ),
					'supported' => ! $unsupported,
					'next'      => $now + ( $unsupported ? self::UNSUPPORTED_WAIT : ( $delay ?? 1800 ) ),
				)
			);
			if ( null !== $delay ) {
				wp_schedule_single_event( $now + $delay, self::HOOK, array( $site_id, $attempt + 1, $round ) );
			}
			return;
		}
		// 店铺报告的个别商品或图片没成功：同步算完成，但把原因显示出来（下一个版本同步时会再试）。
		$errors  = array_values( array_filter( (array) ( $res['applied']['catalog']['errors'] ?? array() ), 'is_string' ) );
		$message = $res['complete'] ? '' : '内容较多，正在分批同步…';
		if ( $errors ) {
			$message = mb_substr( sprintf( '有 %1$d 个商品或图片没同步成功：%2$s', count( $errors ), $errors[0] ), 0, 250 );
		}
		// 活动发过去了但店铺里还没有营销模块接手：不用每 10 分钟重发，6 小时后再试。
		$unhandled = 'unhandled' === ( $res['applied']['campaigns'] ?? null );
		if ( $unhandled && '' === $message ) {
			$message = '活动还没同步：这家店的程序里还没有接收系列活动的模块。';
		}
		self::save_state(
			$site_id,
			array(
				'at'        => $now,
				'ok'        => true,
				'message'   => $message,
				'supported' => true,
				'next'      => $unhandled ? $now + self::UNSUPPORTED_WAIT : 0,
				'synced'    => $res['synced'],
			)
		);
		if ( ! $res['complete'] && $round < self::MAX_ROUNDS ) {
			wp_schedule_single_event( $now + 30, self::HOOK, array( $site_id, 0, $round + 1 ) );
		}
	}

	/** 排一次同步（$delay 秒后）。 */
	public static function queue_site( int $site_id, int $delay = 0 ): void {
		if ( $site_id > 0 ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK, array( $site_id, 0, 0 ) );
		}
	}

	/** 马上排一次同步，并清掉“下次再试”的等待时间（页面上点“立即同步”时用）。 */
	public static function queue_now( int $site_id ): void {
		self::save_state( $site_id, array( 'next' => 0 ) );
		self::queue_site( $site_id );
	}

	/** 系列内容改了：给系列里每家店排一次同步，错开几秒。返回排了几家。 */
	public static function queue_series( int $series_id ): int {
		$n = 0;
		foreach ( array_keys( Series::sites_of( $series_id ) ) as $site_id ) {
			self::save_state( (int) $site_id, array( 'next' => 0 ) );
			self::queue_site( (int) $site_id, $n * 5 );
			++$n;
		}
		return $n;
	}

	/**
	 * 落后的店铺：店铺 ID => 落后的部分（header / catalog / campaigns / shipping）。
	 * 巡检和告警（series_out_of_sync）可以直接用这个结果。
	 *
	 * @return array<int,string[]>
	 */
	public static function lagging(): array {
		if ( ! Series::ready() ) {
			return array();
		}
		$series = Series::all();
		$out    = array();
		foreach ( Series::site_map() as $site_id => $site ) {
			$s = $series[ $site['series_id'] ] ?? null;
			if ( ! $s || ! Site_Lifecycle::active( (int) $site_id ) ) {
				continue;
			}
			$plan = self::plan( $s, $site + array( 'site_id' => (int) $site_id ), self::site_state( (int) $site_id )['synced'], self::confirmed( $s['id'], (int) $site_id ) );
			$need = array_keys( array_filter( $plan ) );
			if ( $need ) {
				$out[ (int) $site_id ] = $need;
			}
		}
		return $out;
	}

	/** 每 10 分钟的巡检之后：给落后而且到了重试时间的店排同步，一次最多 10 家。 */
	public static function reconcile(): void {
		$now = time();
		$n   = 0;
		// 最久没同步过的排在前面，免得总是同几家店占着名额。
		$ids = array_keys( self::lagging() );
		usort( $ids, static fn( int $a, int $b ): int => self::site_state( $a )['at'] <=> self::site_state( $b )['at'] );
		foreach ( $ids as $site_id ) {
			if ( $n >= 10 ) {
				break;
			}
			if ( self::site_state( $site_id )['next'] > $now || wp_next_scheduled( self::HOOK, array( $site_id, 0, 0 ) ) ) {
				continue;
			}
			self::queue_site( $site_id, $n * 5 );
			++$n;
		}
	}

	// ---------- 差异报告、恢复 ----------

	/**
	 * 首次同步前的差异报告：店铺把系列主商品库和自己现有的商品比一遍，只返回差异，不改任何东西。
	 *
	 * @return array|\WP_Error 保存下来的报告
	 */
	public static function diff( int $site_id ) {
		$site   = Series::site( $site_id );
		$series = Series::get( $site['series_id'] );
		if ( ! $series ) {
			return new \WP_Error( 'pchq_series_none', '这家店还没有归入系列。' );
		}
		if ( $series['catalog_ver'] < 1 || ! class_exists( Series_Catalog::class ) ) {
			return new \WP_Error( 'pchq_series_catalog', '这个系列还没有主商品库，请先从主店导入。' );
		}
		if ( false === self::supported( $site_id ) ) {
			return new \WP_Error( 'pchq_series_old', '这家店的程序还不支持系列同步，请先更新店铺程序。' );
		}
		$master = self::is_master( $series, $site + array( 'site_id' => $site_id ) );
		$plan   = array( 'catalog' => true, 'campaigns' => false, 'shipping' => $series['shipping_ver'] > 0 );
		$res    = Client::request( $site_id, 'POST', self::ROUTE, array(), self::body( $series, $plan, true, $master ), 80 );
		if ( is_wp_error( $res ) ) {
			return self::is_unsupported( $res ) ? new \WP_Error( 'pchq_series_old', '这家店的程序还不支持系列同步，请先更新店铺程序。' ) : new \WP_Error( $res->get_error_code(), $res->get_error_message() . '（只是比较，店铺里没有改动；可以再试一次。）' );
		}
		if ( ! is_array( $res['diff'] ?? null ) ) {
			return new \WP_Error( 'pchq_series_reply', '店铺没有返回差异报告，可能需要先更新店铺程序。' );
		}
		return Series_Catalog::save_diff( $series['id'], $site_id, $series['catalog_ver'], $res['diff'] );
	}

	/**
	 * 让店铺恢复到某次同步前的快照（$ver 为 0 时用最近一份）。恢复后关掉这家店的系列同步，避免马上又被覆盖。
	 *
	 * @return array|\WP_Error 店铺的恢复结果
	 */
	public static function restore( int $site_id, int $ver = 0 ) {
		if ( false === self::supported( $site_id ) ) {
			return new \WP_Error( 'pchq_series_old', '这家店的程序还不支持系列同步，没有可恢复的快照。' );
		}
		// 先关掉这家店的系列同步：即使店铺已经恢复、但回应在路上丢了，也不会被下一次推送再覆盖。
		Series::set_sync( $site_id, false );
		$res = Client::request( $site_id, 'POST', self::RESTORE, array(), array( 'ver' => $ver ), 80 );
		if ( is_wp_error( $res ) ) {
			self::forget( $site_id );
			if ( self::is_unsupported( $res ) ) {
				return new \WP_Error( 'pchq_series_old', '这家店的程序还不支持系列同步，没有可恢复的快照。' );
			}
			return new \WP_Error( $res->get_error_code(), '没有收到店铺的确认（' . $res->get_error_message() . '）。店铺可能还在恢复，也可能没有恢复；这家店的系列同步已经关闭，请稍后到店铺里核对商品。' );
		}
		self::save_state(
			$site_id,
			array(
				'at'      => time(),
				'ok'      => true,
				'message' => '已恢复到同步前的快照；这家店的系列同步已关闭。',
				'synced'  => self::versions( (array) ( $res['series'] ?? array() ) ),
			)
		);
		return $res;
	}
}
