<?php
/**
 * 素材日报上报总后台（线 4）：每天把店铺时区前一天的按素材汇总签名 POST 到 /peptide-hq/v1/creatives/report。
 *
 * - 请求体用 Contract::report_payload() 生成，签名同订单上报（Hq_Connector::sign，X-PC-HQ-Site / Time / Sign），带 nonce。
 * - 口径：当天按“最近来源”归因（每个付费访客记到当天最后一次带的广告），行为只算当天；花费是当天导入的花费。
 * - 待报的日期放在队列里（选项 pc_creatives_report_queue）。每小时检查一次：
 *   200 = 完成；501（总后台还没实现写入）、5xx、429、401/403、网络失败 = 稍后重试（1、2、4、8、16 小时，之后每天一次，
 *   最多 MAX_ATTEMPTS 次）；400 / 422 等其他回应 = 请求体有问题，重试也没用，记下错误不再试。
 * - 总后台没配置（Order_Report::enabled() 为 false）时整次跳过，队列保留，接上后再报。
 * - 补登记素材或补导入花费后，线 2 调用 Daily_Report::rereport( $days ) 重报受影响的日期（总后台按天整体覆盖）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Hq_Connector;
use PeptideCore\Order_Report;

defined( 'ABSPATH' ) || exit;

final class Daily_Report {

	public const ROUTE        = '/peptide-hq/v1/creatives/report';
	public const HOOK         = 'pc_creatives_report_tick';
	public const HOOK_NOW     = 'pc_creatives_report_now';
	public const OPTION_QUEUE = 'pc_creatives_report_queue';
	public const OPTION_LAST  = 'pc_creatives_report_last';
	public const OPTION_LOG   = 'pc_creatives_report_log';

	/** 最多重试几次。 */
	public const MAX_ATTEMPTS = 20;

	/** 一次检查最多报几天（补报很多天时分几次）。 */
	public const PER_TICK = 7;

	/** 重报一次最多几天、最远几天前。 */
	public const MAX_DAYS = 62;

	// ---------- 纯函数 ----------

	/**
	 * 看板（按素材、最近来源）的行 → 上报行（Contract::report_row() 的输入）。
	 *
	 * @param list<array> $rows      Board::aggregate( dim = creative ) 的 rows
	 * @param array       $creatives 素材库（编号 => attrs, custom, thumb）
	 */
	public static function report_rows( array $rows, array $creatives ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$code  = (string) $r['key'];
			$c     = (array) ( $creatives[ $code ] ?? array() );
			$out[] = array(
				'code'           => $code,
				'attrs'          => (array) ( $c['attrs'] ?? array() ),
				'custom'         => (array) ( $c['custom'] ?? array() ),
				'thumb'          => (string) ( $c['thumb'] ?? ( $r['meta']['thumb'] ?? '' ) ),
				'intent'         => (array) ( $r['intent'] ?? array() ),
				'visitors'       => (int) $r['visitors'],
				'valid_visitors' => (int) $r['valid'],
				'add_to_cart'    => (int) $r['carted'],
				'checkout'       => (int) $r['checkout'],
				'cashier'        => (int) $r['cashier'],
				'orders'         => (int) $r['orders'],
				'sales_cents'    => (int) $r['sales_cents'],
				'spend_cents'    => (int) $r['spend_cents'],
				'impressions'    => (int) $r['impressions'],
				'link_clicks'    => (int) $r['link_clicks'],
			);
		}
		return $out;
	}

	/**
	 * 签名请求。
	 *
	 * @return array{url:string,headers:array<string,string>,body:string}
	 */
	public static function request( array $payload, string $uuid, string $key, string $hq_url, int $time, string $nonce ): array {
		$body = (string) json_encode( array( 'nonce' => $nonce ) + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'url'     => rtrim( $hq_url, '/' ) . '/?rest_route=' . rawurlencode( self::ROUTE ),
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-PC-HQ-Site' => $uuid,
				'X-PC-HQ-Time' => (string) $time,
				'X-PC-HQ-Sign' => Hq_Connector::sign( $key, 'POST', self::ROUTE, '', $time, $body ),
			),
			'body'    => $body,
		);
	}

	/**
	 * 回应 → done / retry / drop。
	 *
	 * @param int|null $code HTTP 状态码；null = 网络失败
	 */
	public static function outcome( ?int $code ): string {
		if ( null === $code || 0 === $code ) {
			return 'retry';
		}
		if ( 200 === $code ) {
			return 'done';
		}
		if ( $code >= 500 || in_array( $code, array( 401, 403, 404, 408, 409, 425, 429 ), true ) ) {
			return 'retry'; // 501 = 总后台还没实现写入；401/403 多半是连接密钥刚换、时间不同步；404 = 总后台还没装这个接口。
		}
		return 'drop';
	}

	/** 第 n 次失败后等多久（秒）：1、2、4、8、16 小时，之后每天一次。 */
	public static function backoff( int $attempts ): int {
		return (int) min( 86400, 3600 * ( 2 ** max( 0, min( 10, $attempts - 1 ) ) ) );
	}

	/**
	 * 把日期加进队列（已在队列里的改成立即重报、尝试次数清零）。
	 *
	 * @param array<string,array{attempts:int,next:int,error:string}> $queue
	 * @param string[] $days
	 */
	public static function enqueue( array $queue, array $days, int $now ): array {
		foreach ( $days as $day ) {
			if ( is_string( $day ) && Board::valid_day( $day ) ) {
				$queue[ $day ] = array( 'attempts' => 0, 'next' => $now, 'error' => '' );
			}
		}
		ksort( $queue );
		return $queue;
	}

	/** 到时间该报的日期（最早的先报，最多 PER_TICK 天）。 */
	public static function due( array $queue, int $now ): array {
		$out = array();
		foreach ( $queue as $day => $item ) {
			if ( (int) ( $item['next'] ?? 0 ) <= $now ) {
				$out[] = (string) $day;
			}
		}
		sort( $out );
		return array_slice( $out, 0, self::PER_TICK );
	}

	/**
	 * 一次上报之后更新队列。
	 *
	 * @return array{queue:array,dropped:bool}
	 */
	public static function after( array $queue, string $day, string $outcome, int $now, string $error ): array {
		if ( 'done' === $outcome ) {
			unset( $queue[ $day ] );
			return array( 'queue' => $queue, 'dropped' => false );
		}
		$attempts = (int) ( $queue[ $day ]['attempts'] ?? 0 ) + 1;
		if ( 'drop' === $outcome || $attempts >= self::MAX_ATTEMPTS ) {
			unset( $queue[ $day ] );
			return array( 'queue' => $queue, 'dropped' => true );
		}
		$queue[ $day ] = array( 'attempts' => $attempts, 'next' => $now + self::backoff( $attempts ), 'error' => substr( $error, 0, 200 ) );
		return array( 'queue' => $queue, 'dropped' => false );
	}

	/** 店铺时区“昨天”。 */
	public static function yesterday( int $now, string $tz ): string {
		return Board::day( ( $now - 86400 ) * 1000, $tz );
	}

	// ---------- WordPress ----------

	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'tick' ) );
		add_action( self::HOOK_NOW, array( self::class, 'tick' ) );
		add_action( 'init', array( self::class, 'schedule' ), 41 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * 给线 2 调用：素材登记、改属性或花费导入之后，重报这些日期（店铺时区 YYYY-MM-DD）。
	 * 只收最近 MAX_DAYS 天内、不晚于昨天的合法日期；一分钟后在后台报，不阻塞当前请求。
	 *
	 * @param string[] $days
	 * @return int 进队列的天数
	 */
	public static function rereport( array $days ): int {
		$tz    = Board::timezone();
		$now   = time();
		$max   = self::yesterday( $now, $tz );
		$min   = Board::day( ( $now - self::MAX_DAYS * 86400 ) * 1000, $tz );
		$clean = array();
		foreach ( $days as $day ) {
			if ( is_string( $day ) && Board::valid_day( $day ) && $day <= $max && $day >= $min ) {
				$clean[ $day ] = $day;
			}
		}
		if ( ! $clean ) {
			return 0;
		}
		update_option( self::OPTION_QUEUE, self::enqueue( self::queue(), array_values( $clean ), $now ), false );
		if ( ! wp_next_scheduled( self::HOOK_NOW ) ) {
			wp_schedule_single_event( $now + 60, self::HOOK_NOW );
		}
		return count( $clean );
	}

	/** @return array<string,array{attempts:int,next:int,error:string}> */
	public static function queue(): array {
		$q = get_option( self::OPTION_QUEUE, array() );
		return is_array( $q ) ? $q : array();
	}

	/**
	 * 每小时：把昨天加进队列（每天一次），再报到时间的日期。
	 *
	 * @return array{status:string,sent:array<string,string>}
	 */
	public static function tick(): array {
		$now = time();
		$tz  = Board::timezone();
		$y   = self::yesterday( $now, $tz );
		if ( (string) get_option( self::OPTION_LAST, '' ) < $y ) {
			update_option( self::OPTION_QUEUE, self::enqueue( self::queue(), array( $y ), $now ), false );
			update_option( self::OPTION_LAST, $y, false );
		}
		if ( ! Order_Report::enabled() || ! defined( 'PEPTIDE_HQ_URL' ) ) {
			return array( 'status' => 'not_connected', 'sent' => array() );
		}
		$sent = array();
		foreach ( self::due( self::queue(), $now ) as $day ) {
			$res   = self::send( $day, $tz );
			$after = self::after( self::queue(), $day, $res['outcome'], time(), $res['error'] );
			update_option( self::OPTION_QUEUE, $after['queue'], false );
			self::log( $day, $res, $after['dropped'] );
			$sent[ $day ] = $res['outcome'];
		}
		return array( 'status' => 'ok', 'sent' => $sent );
	}

	/** 生成并发送一天。 */
	public static function send( string $day, string $tz ): array {
		$payload = self::payload( $day, $tz );
		if ( null === $payload ) {
			return array( 'outcome' => 'drop', 'code' => null, 'error' => 'payload_invalid' );
		}
		$s   = Hq_Connector::settings();
		$req = self::request( $payload, $s['uuid'], $s['key'], (string) PEPTIDE_HQ_URL, time(), wp_generate_uuid4() );
		$res = wp_remote_post( $req['url'], array( 'timeout' => 20, 'headers' => $req['headers'], 'body' => $req['body'] ) );
		if ( is_wp_error( $res ) ) {
			return array( 'outcome' => 'retry', 'code' => null, 'error' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$msg  = is_array( $json ) && is_string( $json['message'] ?? null ) ? ' ' . $json['message'] : '';
		return array( 'outcome' => self::outcome( $code ), 'code' => $code, 'error' => 200 === $code ? '' : 'HTTP ' . $code . $msg );
	}

	/** 一天的请求体（不含 nonce）。 */
	public static function payload( string $day, string $tz ): ?array {
		$ms = Board::range_ms( $day, $day, $tz );
		if ( null === $ms ) {
			return null;
		}
		$data = Board::load( $ms, $day, $day );
		$agg  = Board::aggregate( $data, array( 'mode' => 'last', 'dim' => 'creative', 'from_ms' => $ms[0], 'to_ms' => $ms[1], 'tz' => $tz ) );
		$rows = self::report_rows( $agg['rows'], $data['creatives'] );
		if ( count( $rows ) > Contract::MAX_ROWS ) {
			$rows = array_slice( $rows, 0, Contract::MAX_ROWS ); // 按花费排在前面的先报；一天 500 个素材不现实，只是防御。
		}
		return Contract::report_payload( $day, $tz, $rows, (int) $agg['untagged'] );
	}

	/** 最近 30 条上报记录（看板底部显示）。 */
	private static function log( string $day, array $res, bool $dropped ): void {
		$log = (array) get_option( self::OPTION_LOG, array() );
		array_unshift( $log, array( 'day' => $day, 'at' => time(), 'outcome' => $dropped ? 'dropped' : $res['outcome'], 'code' => $res['code'], 'error' => substr( (string) $res['error'], 0, 200 ) ) );
		update_option( self::OPTION_LOG, array_slice( $log, 0, 30 ), false );
	}
}
