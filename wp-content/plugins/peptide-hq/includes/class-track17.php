<?php
/**
 * 17TRACK 物流追踪（店主 2026-11-11：没有自己的 FedEx 账号，拿不到 FedEx 正式密钥，改用 17TRACK）。
 *
 * - 总后台 → 物流追踪 填 17TRACK 的 Security Key（API v2.4，头 17token）。填了就只用它；没填 17TRACK 时才用 FedEx 官方接口（17TRACK 出错时不会自动改查 FedEx）。
 * - 额度：新账号一次性送 200 个单号，每个单号注册一次扣一次，之后自动跟踪不重复扣。
 *   为了省额度，单号第一次被查（顾客点物流球 / 后台测试）时才注册；注册后 17TRACK 几分钟内开始有数据。
 * - 结果缓存 30 分钟；“刚注册、还没数据”缓存 10 分钟；出错 5 分钟。密钥加密保存。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Track17 {

	public const OPTION = 'pchq_track17';
	private const BASE  = 'https://api.17track.net/track/v2.4/';
	private const FEDEX = 100003; // 17TRACK 的 FedEx 承运商编号。

	private const STATUS = array(
		'NotFound'           => 'Not found yet',
		'InfoReceived'       => 'Label created',
		'InTransit'          => 'In transit',
		'Expired'            => 'Tracking expired',
		'AvailableForPickup' => 'Ready for pickup',
		'OutForDelivery'     => 'Out for delivery',
		'DeliveryFailure'    => 'Delivery attempt failed',
		'Delivered'          => 'Delivered',
		'Exception'          => 'Delivery exception',
	);

	public const CRON = 'pchq_track17_quota';
	public const LOW  = 20; // 剩余额度低于这个数就在总后台告警（店主 2026-11-11：先用免费额度，快用完再充值）。
	public const ALERT_REF = '17track'; // 告警归在“费用”类（店主 2026-11-11：在费用里加预警）。

	public static function register_hooks(): void {
		add_action( self::CRON, array( self::class, 'check_quota' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 120, 'hourly', self::CRON );
				}
			}
		);
	}

	/** 每小时看一次剩余额度：不够了在“费用”开告警并发邮件，充值后自动恢复。 */
	public static function check_quota(): void {
		if ( ! self::configured() ) {
			Alerts::resolve_ref( 0, 'cost', self::ALERT_REF );
			return;
		}
		$q = self::quota( true );
		if ( is_wp_error( $q ) ) {
			return; // 连不上或密钥问题不在这里告警，费用页和测试查询会直接显示。
		}
		$remain = (int) ( $q['quota_remain'] ?? 0 );
		if ( $remain < self::LOW ) {
			$msg = sprintf( '17TRACK 物流追踪额度只剩 %d 个单号。用完后新发货的单号查不到物流记录，请到 17TRACK 后台充值（最小套餐 $119 / 5,000 个，有效期 12 个月）。', $remain );
			if ( Alerts::raise( 0, 'cost', self::ALERT_REF, $msg ) ) {
				Costs::mail( '17TRACK 物流追踪额度快用完了', $msg );
			}
		} else {
			Alerts::resolve_ref( 0, 'cost', self::ALERT_REF );
		}
	}

	public static function key(): string {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) || '' === (string) ( $s['key_box'] ?? '' ) ) {
			return '';
		}
		try {
			return (string) Crypto::decrypt( (string) $s['key_box'] );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	public static function configured(): bool {
		return '' !== self::key();
	}

	public static function save( array $in ): void {
		if ( ! empty( $in['clear17'] ) ) {
			delete_option( self::OPTION );
			return;
		}
		$k = trim( sanitize_text_field( (string) ( $in['track17_key'] ?? '' ) ) );
		if ( '' !== $k ) {
			update_option( self::OPTION, array( 'key_box' => Crypto::encrypt( $k ) ), false );
		}
	}

	/** @return array|\WP_Error */
	private static function call( string $path, array $body ) {
		$res = wp_remote_post(
			self::BASE . $path,
			array(
				'timeout' => 15,
				'headers' => array( '17token' => self::key(), 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_17_net', '17TRACK 连接失败：' . $res->get_error_message() );
		}
		$http = (int) wp_remote_retrieve_response_code( $res );
		$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 401 === $http ) {
			return new \WP_Error( 'pchq_17_auth', '17TRACK 密钥无效（或账号停用 / IP 不在白名单）。' );
		}
		if ( 429 === $http ) {
			return new \WP_Error( 'pchq_17_rate', '17TRACK 请求太频繁，请稍后再试。' );
		}
		if ( 200 !== $http || ! is_array( $j ) || 0 !== (int) ( $j['code'] ?? -1 ) ) {
			$msg = $j['data']['errors'][0]['message'] ?? ( 'HTTP ' . $http );
			return new \WP_Error( 'pchq_17_http', '17TRACK：' . $msg );
		}
		return $j;
	}

	/** @return array|\WP_Error */
	public static function track( string $number, bool $fresh = false ) {
		$number = preg_replace( '/\s+/', '', $number );
		if ( ! preg_match( '/\A[0-9A-Za-z-]{5,50}\z/', $number ) ) {
			return new \WP_Error( 'pchq_17_number', 'Invalid tracking number.' );
		}
		if ( ! self::configured() ) {
			return new \WP_Error( 'pchq_17_off', '17TRACK 还没有设置密钥。' );
		}
		$ck = 'pchq_17_t_' . md5( $number );
		if ( ! $fresh ) {
			$hit = get_transient( $ck );
			if ( is_array( $hit ) ) {
				return isset( $hit['error'] ) ? new \WP_Error( 'pchq_17_cached', (string) $hit['error'] ) : $hit;
			}
		}
		$item = array( array( 'number' => $number, 'carrier' => self::FEDEX ) );
		$j    = self::call( 'gettrackinfo', $item );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$acc = $j['data']['accepted'][0]['track_info'] ?? null;
		if ( is_array( $acc ) ) {
			$out = self::parse( $acc );
			set_transient( $ck, $out, 30 * MINUTE_IN_SECONDS );
			return $out;
		}
		$err  = $j['data']['rejected'][0]['error'] ?? array();
		$code = (int) ( $err['code'] ?? 0 );
		if ( -18019902 === $code ) {
			// 还没注册：注册一次（扣 1 个额度），之后 17TRACK 自动跟踪。
			$reg = self::call( 'register', $item );
			if ( is_wp_error( $reg ) ) {
				return $reg;
			}
			$rerr = $reg['data']['rejected'][0]['error'] ?? null;
			if ( is_array( $rerr ) ) {
				$msg = self::error_text( (int) ( $rerr['code'] ?? 0 ), (string) ( $rerr['message'] ?? '' ) );
				set_transient( $ck, array( 'error' => $msg ), 5 * MINUTE_IN_SECONDS );
				return new \WP_Error( 'pchq_17_reg', $msg );
			}
			delete_transient( 'pchq_17_quota' );
			$msg = 'Tracking has just started. Updates will appear here within a few minutes.';
			set_transient( $ck, array( 'error' => $msg ), 3 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'pchq_17_pending', $msg );
		}
		$msg = self::error_text( $code, (string) ( $err['message'] ?? '' ) );
		set_transient( $ck, array( 'error' => $msg ), ( -18019909 === $code ? 10 : 5 ) * MINUTE_IN_SECONDS );
		return new \WP_Error( 'pchq_17_err', $msg );
	}

	private static function error_text( int $code, string $fallback ): string {
		$map = array(
			-18019909 => 'No tracking updates from the carrier yet.',
			-18019908 => '17TRACK 额度已用完，请到 17TRACK 后台充值。',
			-18019907 => '17TRACK 今日查询次数已到上限。',
			-18010012 => 'Invalid tracking number.',
			-18019903 => '17TRACK 识别不出承运商。',
		);
		return $map[ $code ] ?? ( '' !== $fallback ? $fallback : '17TRACK error ' . $code );
	}

	/** 整理成和 FedEx 接口一样的格式（status / code / delivered / eta / events）。 */
	public static function parse( array $t ): array {
		$main   = (string) ( $t['latest_status']['status'] ?? '' );
		$events = array();
		foreach ( (array) ( $t['tracking']['providers'] ?? array() ) as $p ) {
			foreach ( (array) ( $p['events'] ?? array() ) as $e ) {
				$a        = (array) ( $e['address'] ?? array() );
				$place    = (string) ( $e['location'] ?? '' );
				$place    = '' !== $place ? $place : implode( ', ', array_filter( array( $a['city'] ?? '', $a['state'] ?? '', $a['country'] ?? '' ) ) );
				$events[] = array(
					'time'  => (string) ( $e['time_iso'] ?? ( $e['time_utc'] ?? '' ) ),
					'text'  => (string) ( $e['description'] ?? '' ),
					'place' => $place,
				);
			}
		}
		usort( $events, static fn( $x, $y ) => (int) strtotime( $y['time'] ) <=> (int) strtotime( $x['time'] ) );
		$delivered = null;
		foreach ( (array) ( $t['milestone'] ?? array() ) as $m ) {
			if ( 'Delivered' === ( $m['key_stage'] ?? '' ) && ! empty( $m['time_iso'] ) ) {
				$delivered = (string) $m['time_iso'];
			}
		}
		if ( ! $delivered && 'Delivered' === $main && $events ) {
			$delivered = $events[0]['time'];
		}
		$eta = $t['time_metrics']['estimated_delivery_date']['to'] ?? ( $t['time_metrics']['estimated_delivery_date']['from'] ?? null );
		return array(
			'status'    => self::STATUS[ $main ] ?? $main,
			'code'      => $main,
			'delivered' => $delivered,
			'eta'       => $delivered ? null : ( $eta ? (string) $eta : null ),
			'events'    => array_slice( $events, 0, 40 ),
		);
	}

	/** 剩余额度（费用页、物流追踪页显示）；缓存 1 小时，每小时的检查会刷新。 */
	public static function quota( bool $fresh = false ) {
		if ( ! $fresh ) {
			$hit = get_transient( 'pchq_17_quota' );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$j = self::call( 'getquota', array() );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$q = (array) ( $j['data'] ?? array() );
		set_transient( 'pchq_17_quota', $q, HOUR_IN_SECONDS );
		return $q;
	}
}
