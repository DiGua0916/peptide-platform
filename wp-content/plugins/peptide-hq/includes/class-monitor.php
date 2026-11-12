<?php
/**
 * 定时检查所有店铺（每 10 分钟）：取店铺状态和每天的数字，按规则开 / 关告警。
 *
 * - 连续 2 次连不上 → “店铺连不上”；密钥被拒 → “连接码失效”。
 * - 店铺的域名没连上 / HTTPS 没生效 → 告警（以店铺“域名管理”的检查结果为准）。
 * - 最近 7 天平均每天至少 1 单，但已经 48 小时没有订单 → “很久没有订单”。
 * - 店铺处于“即将开业”（顾客看不到）→ 告警。
 * - 每天的数字：新店铺取 31 天；之后每次取 2 天（今天和昨天），每天再完整刷新一次 31 天。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Monitor {

	public const CRON     = 'pchq_poll';
	public const POLL_ONE = 'pchq_poll_one';
	public const SCHEDULE = 'pchq_ten_minutes';

	/** 新店铺和每天完整刷新时取多少天。 */
	public const FULL_DAYS = 31;

	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_schedule' ) );
		add_action( self::CRON, array( self::class, 'poll_all' ) );
		add_action( self::POLL_ONE, array( self::class, 'poll_new' ) );
		add_filter( 'pchq_monitor_checks', array( self::class, 'pricing_engine_check' ), 10, 4 );
		add_filter( 'pchq_alert_types', array( self::class, 'module_alert_types' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			self::schedule();
		}
	}

	public static function cron_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Every 10 minutes (master admin)',
		);
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function poll_all(): void {
		foreach ( array_keys( Sites::all() ) as $id ) {
			self::poll( (int) $id );
		}
	}

	/** 店铺刚注册（或换了密钥）后的第一次检查：载入最近 31 天。 */
	public static function poll_new( $id ): void {
		self::poll( (int) $id, true );
	}

	/**
	 * 店铺换了密钥来注册时，新密钥先用它请求一次：连得上才换掉旧密钥，连不上就丢掉（旧密钥照常用）。
	 */
	private static function try_pending_key( int $id, array $site ): void {
		$pending = Sites::key( $id, true );
		if ( '' === $pending ) {
			return;
		}
		$url = Site_Urls::management( $site );
		$ok = '' !== $url ? Client::request_raw( $url, $pending, 'GET', '/peptide/v1/hq/status' ) : new \WP_Error( 'pchq_management_url', 'Invalid management address.' );
		$matches = ! is_wp_error( $ok ) && ( '' === $site['uuid'] || hash_equals( $site['uuid'], (string) ( $ok['uuid'] ?? '' ) ) );
		Sites::update(
			$id,
			! $matches ? array( 'key_pending' => null ) : array(
				'key_enc'     => Crypto::encrypt( $pending ),
				'key_pending' => null,
			)
		);
	}

	/**
	 * 检查一个店铺。返回是否连上了。
	 */
	public static function poll( int $id, bool $full = false ): bool {
		if ( ! Site_Lifecycle::active( $id ) ) { return false; }
		$site = Sites::get( $id );
		if ( ! $site ) {
			return false;
		}
		self::try_pending_key( $id, $site );
		$now    = time();
		$status = Client::status( $id );
		if ( is_wp_error( $status ) ) {
			$denied = 'pchq_denied' === $status->get_error_code();
			$fails  = $site['fails'] + 1;
			Sites::update(
				$id,
				array(
					'status'     => $denied ? 'denied' : ( $fails >= 2 ? 'offline' : $site['status'] ),
					'fails'      => $fails,
					'error'      => mb_substr( $status->get_error_message(), 0, 255 ),
					'last_check' => $now,
				)
			);
			if ( $denied ) {
				Alerts::raise( $id, 'denied', '', $status->get_error_message() );
			} elseif ( $fails >= 2 ) {
				/* translators: %s: error */
				Alerts::raise( $id, 'offline', '', sprintf( __( 'The store could not be reached twice in a row: %s', 'peptide-hq' ), $status->get_error_message() ) );
			}
			return false;
		}

		// 不跟随店铺自己的名称：总后台的店铺名称是给平台所有者看的标签（投手名），和店铺的 blogname 含义不同。
		// 2026-11-08 试过“轮询时跟着店铺改名”，把 6 个标签全部覆盖成了域名，当天在服务器上回退，但仓库里没删；
		// 2026-11-10 版本盘点发现后删除（测试 m1-monitor-name-pure.php）。改名只走 总后台 → 店铺（Client::config）。
		Sites::update(
			$id,
			array(
				'status'     => 'online',
				'fails'      => 0,
				'error'      => '',
				'info'       => (string) wp_json_encode( $status ),
				'last_check' => $now,
				'last_ok'    => $now,
			)
		);
		Alerts::resolve( $id, 'offline' );
		Alerts::resolve( $id, 'denied' );

		$full    = $full || $now - $site['last_full'] >= DAY_IN_SECONDS;
		$summary = Client::summary( $id, $full ? self::FULL_DAYS : 2 );
		if ( ! is_wp_error( $summary ) ) {
			Stats::store( $id, (array) ( $summary['days'] ?? array() ), (string) ( $summary['currency'] ?? '' ) );
			// 模块要用每日汇总里自己的字段时挂这个动作（例如 M10 存每天的弃单数），不用再发一次请求。
			try {
				do_action( 'pchq_summary_days', $id, (array) ( $summary['days'] ?? array() ), $summary );
			} catch ( \Throwable $e ) {
				error_log( 'pchq_summary_days: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( $full ) {
				Sites::update( $id, array( 'last_full' => $now ) );
			}
		}
		self::check_rules( $id, $status, $now );
		self::module_checks( $id, $status, $now );
		self::sync_server( $id, $status );
		return true;
	}

	/** 店铺上的服务器 IP 和总后台分配的不一样时（例如服务器换了 IP、下发时店铺没连上），再下发一次。 */
	private static function sync_server( int $id, array $status ): void {
		$site = Sites::get( $id );
		if ( ! $site || ! $site['server_id'] ) {
			return;
		}
		$want = Servers::ip_for_site( $id );
		if ( '' !== $want && $want !== (string) ( $status['config']['server_ip'] ?? '' ) ) {
			$res = Client::config( $id, array( 'server_ip' => $want ) );
			if ( is_array( $res ) ) {
				$status['config'] = $res;
				Sites::update( $id, array( 'info' => (string) wp_json_encode( $status ) ) );
			}
		}
	}

	/** 按店铺状态开 / 关告警。 */
	public static function check_rules( int $id, array $status, int $now ): void {
		$bad_domain = array();
		$bad_https  = array();
		foreach ( (array) ( $status['domains'] ?? array() ) as $d ) {
			$host = (string) ( $d['host'] ?? '' );
			if ( '' === $host || self::is_local( $host ) ) {
				continue; // 本机开发用的地址（.local 等）外面本来就连不上，不告警。
			}
			if ( 'failed' === ( $d['conn'] ?? '' ) ) {
				$bad_domain[] = $host;
				/* translators: %s: domain */
				Alerts::raise( $id, 'domain', $host, sprintf( __( '%s does not reach the store. Check its DNS records.', 'peptide-hq' ), $host ) );
			} elseif ( 'failed' === ( $d['https'] ?? '' ) ) {
				$bad_https[] = $host;
				/* translators: %s: domain */
				Alerts::raise( $id, 'https', $host, sprintf( __( 'HTTPS does not work on %s. Visitors see a security warning.', 'peptide-hq' ), $host ) );
			}
		}
		Alerts::resolve( $id, 'domain', $bad_domain );
		Alerts::resolve( $id, 'https', $bad_https );

		$last = (int) ( $status['last_order'] ?? 0 );
		$avg  = Stats::average_orders( $id, 7 );
		if ( $last && $now - $last > 2 * DAY_IN_SECONDS && $avg >= 1 ) {
			/* translators: 1: hours, 2: average orders per day */
			Alerts::raise( $id, 'no_orders', '', sprintf( __( 'No order for %1$d hours, although the store had %2$s orders a day over the last 7 days.', 'peptide-hq' ), (int) floor( ( $now - $last ) / HOUR_IN_SECONDS ), number_format_i18n( $avg, 1 ) ) );
		} else {
			Alerts::resolve( $id, 'no_orders' );
		}

		if ( ! empty( $status['coming_soon'] ) ) {
			Alerts::raise( $id, 'closed', '', __( 'The store is in “coming soon” mode: customers cannot see it.', 'peptide-hq' ) );
		} else {
			Alerts::resolve( $id, 'closed' );
		}
	}

	/** 模块巡检告警的显示名（各模块自己的类型由各模块用同一个过滤器登记）。 */
	public static function module_alert_types( $types ): array {
		return ( is_array( $types ) ? $types : array() ) + array( 'pricing_mismatch' => '价格引擎核对不一致' );
	}

	/**
	 * M4 价格引擎（店铺端没有总后台文件，所以这一项放在这里）：店铺在 /hq/status 的 modules.pricing_engine 里上报
	 * { mode, today: { compared, matched, mismatched, skipped, errors } }。档位不是 off 且今天有不一致或出错 → 告警。
	 * 没声明 pricing.engine 的店、没带简报的店不报（不适用）。
	 */
	public static function pricing_engine_check( $checks, $site_id = 0, $status = array(), $now = 0 ): array {
		$checks = is_array( $checks ) ? $checks : array();
		$brief  = is_array( $status ) ? ( $status['modules']['pricing_engine'] ?? null ) : null;
		if ( ! is_array( $brief ) || ! isset( $status['capabilities']['pricing.engine'] ) ) {
			return $checks;
		}
		$mode  = (string) ( $brief['mode'] ?? 'off' );
		$today = is_array( $brief['today'] ?? null ) ? $brief['today'] : array();
		$bad   = (int) ( $today['mismatched'] ?? 0 );
		$err   = (int) ( $today['errors'] ?? 0 );
		$ok    = 'off' === $mode || ( 0 === $bad && 0 === $err );
		$checks[] = array(
			'type'    => 'pricing_mismatch',
			'ok'      => $ok,
			'message' => $ok ? '' : sprintf( '价格引擎（%1$s 档）今天核对 %2$d 次：不一致 %3$d 次，出错 %4$d 次。在这家店执行 wp peptide price-engine log 查看明细；需要时把档位改回 off。', $mode, (int) ( $today['compared'] ?? 0 ), $bad, $err ),
		);
		return $checks;
	}

	/** 本类自己管的告警类型；模块巡检不能用这些名字（否则会把核心告警误关掉）。 */
	public const RESERVED_TYPES = array( 'offline', 'denied', 'domain', 'https', 'no_orders', 'closed', 'cost', 'payment' );

	/**
	 * 各模块挂的巡检项。每次轮询成功后调用一次：
	 *   add_filter( 'pchq_monitor_checks', static function ( array $checks, int $site_id, array $status, int $now ): array {
	 *       $checks[] = array( 'type' => 'code_drift', 'ok' => false, 'ref' => 'peptide-core', 'message' => '……' );
	 *       return $checks;
	 *   }, 10, 4 );
	 * ok = false 开告警（同店同类型同对象只开一条）；ok = true 关掉这类告警。同一类型里既有 true 又有 false 时，
	 * 只关掉不在 false 列表里的对象。这次没报的类型不动。告警显示名用过滤器 pchq_alert_types 登记。
	 */
	public static function module_checks( int $id, array $status, int $now ): void {
		try {
			$checks = apply_filters( 'pchq_monitor_checks', array(), $id, $status, $now );
		} catch ( \Throwable $e ) {
			// 一个模块的巡检出错不能拖垮整轮轮询。
			if ( function_exists( 'error_log' ) ) {
				error_log( 'pchq_monitor_checks: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}
		self::apply_checks( $id, is_array( $checks ) ? $checks : array() );
	}

	/**
	 * @param array<int,mixed> $checks
	 * @return array{raised:int,resolved:string[],skipped:int}
	 */
	public static function apply_checks( int $id, array $checks ): array {
		$failing = array();
		$seen    = array();
		$result  = array( 'raised' => 0, 'resolved' => array(), 'skipped' => 0 );
		foreach ( array_slice( $checks, 0, 100 ) as $check ) {
			$type = is_array( $check ) ? (string) ( $check['type'] ?? '' ) : '';
			if ( ! preg_match( '/^[a-z][a-z0-9_]{1,31}$/D', $type ) || in_array( $type, self::RESERVED_TYPES, true ) || ! array_key_exists( 'ok', $check ) ) {
				++$result['skipped'];
				continue;
			}
			$seen[ $type ] = true;
			if ( ! empty( $check['ok'] ) ) {
				continue;
			}
			$ref     = mb_substr( (string) ( $check['ref'] ?? '' ), 0, 190 );
			$message = trim( (string) ( $check['message'] ?? '' ) );
			Alerts::raise( $id, $type, $ref, '' !== $message ? $message : $type );
			$failing[ $type ][] = $ref;
			++$result['raised'];
		}
		foreach ( array_keys( $seen ) as $type ) {
			Alerts::resolve( $id, $type, $failing[ $type ] ?? array() );
			$result['resolved'][] = $type;
		}
		return $result;
	}

	private static function is_local( string $host ): bool {
		$tld = (string) substr( (string) strrchr( '.' . $host, '.' ), 1 );
		return in_array( $tld, array( 'local', 'test', 'localhost', 'lan', 'internal', 'example', 'invalid' ), true );
	}
}
