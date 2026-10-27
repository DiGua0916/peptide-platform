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
		$ok = Client::request_raw( $site['url'], $pending, 'GET', '/peptide/v1/hq/status' );
		Sites::update(
			$id,
			is_wp_error( $ok ) ? array( 'key_pending' => null ) : array(
				'key_enc'     => Crypto::encrypt( $pending ),
				'key_pending' => null,
			)
		);
	}

	/**
	 * 检查一个店铺。返回是否连上了。
	 */
	public static function poll( int $id, bool $full = false ): bool {
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
			if ( $full ) {
				Sites::update( $id, array( 'last_full' => $now ) );
			}
		}
		self::check_rules( $id, $status, $now );
		return true;
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

	private static function is_local( string $host ): bool {
		$tld = (string) substr( (string) strrchr( '.' . $host, '.' ), 1 );
		return in_array( $tld, array( 'local', 'test', 'localhost', 'lan', 'internal', 'example', 'invalid' ), true );
	}
}
