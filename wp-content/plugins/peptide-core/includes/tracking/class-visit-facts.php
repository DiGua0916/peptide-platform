<?php
/**
 * 访问的入口事实（CR 素材归因线 1，2026-11-11）：访问表 v5 新列怎么算、访客级来源、广告首次出现。
 *
 * - columns()：纯函数。由入口快照（Attribution::clean() 的结果）、这次请求的 User-Agent、IP 和 Ip_Network 的结果
 *   算出 v5 新列，交给 Attribution::capture() 和原来的快照列一起写（每次访问第一页记一次、之后不改）。
 * - ad_id()：入口快照里有效的广告 ID（utm_content），Tracker 用它判断“换广告开新访问”。
 * - after_capture()：快照写成功后，source_class = paid 时更新访客的 last_paid_visit_id；广告 ID 有效时写 / 更新 pc_ad_ads。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

use PeptideCore\Creatives\Contract;

defined( 'ABSPATH' ) || exit;

final class Visit_Facts {

	/** 本次请求里已经查过的 IP（同一请求最多查一次库）。 */
	private static array $network = array();

	/** 入口快照里的广告 ID；没有或无效（例如广告预览带来的 {{ad.id}}）时为空字符串。 */
	public static function ad_id( array $metadata ): string {
		$id = $metadata['landing_query']['utm_content'] ?? '';
		return is_string( $id ) && Contract::valid_meta_id( $id ) ? $id : '';
	}

	/**
	 * v5 新列（纯函数）。utm_source / utm_medium / referrer_host 仍由 Attribution::capture() 原样写，这里不重复。
	 *
	 * @param array<string,mixed> $metadata Attribution::clean() 的 value
	 * @param array<string,mixed> $network  Ip_Network::lookup() 的结果（可以是 Ip_Network::EMPTY）
	 * @return array<string,mixed>
	 */
	public static function columns( array $metadata, string $user_agent, string $ip, array $network ): array {
		$query  = is_array( $metadata['landing_query'] ?? null ) ? $metadata['landing_query'] : array();
		$params = array();
		foreach ( Contract::PARAMS as $key ) {
			$params[ $key ] = is_string( $query[ $key ] ?? null ) ? $query[ $key ] : '';
		}
		$fbclid   = true === ( $metadata['has_fbclid'] ?? null );
		$referrer = is_string( $metadata['referrer_host'] ?? null ) ? $metadata['referrer_host'] : '';
		$out      = array();
		foreach ( array( 'utm_campaign', 'utm_term', 'utm_content', 'placement', 'ad_name' ) as $key ) {
			$out[ $key ] = array_key_exists( $key, $query ) && is_string( $query[ $key ] ) ? $query[ $key ] : null;
		}
		$out['creative_code'] = '' !== $params['ad_name'] ? Contract::parse_ad_name( $params['ad_name'] )['code'] : '';
		$out['has_fbclid']    = $fbclid ? 1 : 0;
		$out['source_class']  = Contract::source_class( $params, $fbclid, $referrer, $user_agent );
		$out['params_valid']  = Contract::params_valid( $params ) ? 1 : 0;
		$out['ip']            = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? substr( $ip, 0, 45 ) : '';
		foreach ( Ip_Network::EMPTY as $key => $empty ) {
			$value       = $network[ $key ] ?? $empty;
			$out[ $key ] = is_int( $empty ) ? ( is_int( $value ) && $value >= 0 ? $value : 0 ) : ( is_string( $value ) ? $value : '' );
		}
		$out['timezone'] = is_string( $metadata['timezone'] ?? null ) ? $metadata['timezone'] : '';
		$out['language'] = is_string( $metadata['language'] ?? null ) ? $metadata['language'] : '';
		$out['in_app']   = Contract::in_app( $user_agent );
		return $out;
	}

	/**
	 * pc_ad_ads 的一行（纯函数）。广告 ID 无效时返回 null；广告组 / 广告系列 ID 无效时留空。
	 *
	 * @param array<string,mixed> $facts columns() 的结果
	 */
	public static function ad_row( array $facts, int $now_ms ): ?array {
		$ad = is_string( $facts['utm_content'] ?? null ) ? $facts['utm_content'] : '';
		if ( ! Contract::valid_meta_id( $ad ) ) {
			return null;
		}
		$id = static fn( $value ): string => is_string( $value ) && Contract::valid_meta_id( $value ) ? $value : '';
		return array(
			'ad_id'         => $ad,
			'code'          => (string) ( $facts['creative_code'] ?? '' ),
			'ad_name'       => function_exists( 'mb_substr' ) ? mb_substr( (string) ( $facts['ad_name'] ?? '' ), 0, 255 ) : substr( (string) ( $facts['ad_name'] ?? '' ), 0, 255 ),
			'adset_id'      => $id( $facts['utm_term'] ?? '' ),
			'campaign_id'   => $id( $facts['utm_campaign'] ?? '' ),
			'first_seen_ms' => $now_ms,
			'last_seen_ms'  => $now_ms,
		);
	}

	/** 这次请求的 v5 新列：IP 和地理网络在同一请求里只查一次。 */
	public static function for_request( array $metadata ): array {
		$ip = self::client_ip();
		if ( ! array_key_exists( $ip, self::$network ) ) {
			self::$network[ $ip ] = '' === $ip ? Ip_Network::EMPTY : Ip_Network::lookup( $ip );
		}
		return self::columns( $metadata, self::user_agent(), $ip, self::$network[ $ip ] );
	}

	/**
	 * 访客的真实 IP（入库用，用户已同意保存）。只有 REMOTE_ADDR 属于 PEPTIDE_CORE_TRUSTED_PROXIES 时才采用
	 * CF-Connecting-IP / X-Forwarded-For（和 Rest_Controller::client_ip() 同一规则，但不按 /64 合并 IPv6）。
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip     = $remote;
		$list   = defined( 'PEPTIDE_CORE_TRUSTED_PROXIES' ) ? array_filter( array_map( 'trim', explode( ',', (string) constant( 'PEPTIDE_CORE_TRUSTED_PROXIES' ) ) ) ) : array();
		if ( $list && class_exists( \PeptideCore\Api\Rest_Controller::class ) && \PeptideCore\Api\Rest_Controller::ip_in_list( $remote, $list ) ) {
			$forwarded = '';
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$forwarded = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$forwarded = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
			if ( false !== filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
				$ip = $forwarded;
			}
		}
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 400 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * 入口快照写成功之后：付费访问更新访客的 last_paid_visit_id；有效广告 ID 写 / 更新 pc_ad_ads。
	 * 这里的失败不影响事件确认（只是少记一笔）。
	 */
	public static function after_capture( int $visitor_id, int $visit_id, array $facts, int $now_ms ): void {
		global $wpdb;
		if ( Contract::SOURCE_PAID === ( $facts['source_class'] ?? '' ) && $visitor_id > 0 && $visit_id > 0 ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visitors' ) . ' SET last_paid_visit_id = GREATEST(last_paid_visit_id, %d) WHERE id = %d', $visit_id, $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$row = self::ad_row( $facts, $now_ms );
		if ( null !== $row && class_exists( \PeptideCore\Creatives\Schema::class ) && \PeptideCore\Creatives\Schema::installed() ) {
			$wpdb->query( self::ad_upsert_sql( \PeptideCore\Creatives\Schema::table( 'ads' ), $row ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * 广告第一次出现时插入；之后只在这次更晚时更新 ad_name（新名字非空）、编号（名字里有编号）、广告组 / 系列 ID（原来为空），
	 * 最后推进 last_seen_ms（MySQL 按顺序计算 SET，last_seen_ms 必须放最后）。first_seen_ms 不改。
	 */
	public static function ad_upsert_sql( string $table, array $row ): string {
		global $wpdb;
		$newer = 'VALUES(last_seen_ms) >= last_seen_ms';
		return $wpdb->prepare(
			"INSERT INTO {$table} (ad_id, code, ad_name, adset_id, campaign_id, first_seen_ms, last_seen_ms) VALUES (%s, %s, %s, %s, %s, %d, %d)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. " ON DUPLICATE KEY UPDATE ad_name = CASE WHEN VALUES(ad_name) <> '' AND {$newer} THEN VALUES(ad_name) ELSE ad_name END,"
			. " code = CASE WHEN VALUES(code) <> '' AND {$newer} THEN VALUES(code) ELSE code END,"
			. " adset_id = CASE WHEN adset_id = '' THEN VALUES(adset_id) ELSE adset_id END,"
			. " campaign_id = CASE WHEN campaign_id = '' THEN VALUES(campaign_id) ELSE campaign_id END,"
			. ' last_seen_ms = GREATEST(last_seen_ms, VALUES(last_seen_ms))',
			$row['ad_id'],
			$row['code'],
			$row['ad_name'],
			$row['adset_id'],
			$row['campaign_id'],
			$row['first_seen_ms'],
			$row['last_seen_ms']
		);
	}
}
