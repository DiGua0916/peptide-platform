<?php
/**
 * 系列营销活动的存放（HQ-07 的数据底座）。活动的内容（config）由 M4 营销与价格引擎定义和校验，
 * 这里只负责：按系列保存、整体替换时把 campaign_ver 加一、拼成 /hq/series/sync 的 campaigns 部分。
 *
 * 每条活动：kind（类型）、key（同一类型内不重复的代号）、active（是否在跑）、config（M4 定义的内容，原样保存）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Series_Campaigns {

	/** 认识的活动类型；不认识的类型不保存。 */
	public const KINDS = array( 'promotion', 'coupon', 'ship_discount', 'first_order', 'exit_intent', 'wheel', 'upsell', 'progress_bar' );

	/** 一条活动的 config 最多多少字节（JSON）。 */
	public const MAX_CONFIG_BYTES = 200000;

	public static function table(): string {
		return Db::table( 'series_campaigns' );
	}

	/**
	 * 整理一条活动（纯函数）。无效时返回 null。
	 *
	 * @return array{kind:string,key:string,active:bool,config:array}|null
	 */
	public static function clean_item( $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$kind = (string) ( $item['kind'] ?? '' );
		$key  = strtolower( (string) ( $item['key'] ?? '' ) );
		if ( ! in_array( $kind, self::KINDS, true ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key ) || ! is_array( $item['config'] ?? null ) ) {
			return null;
		}
		$json = json_encode( $item['config'] );
		if ( false === $json || strlen( $json ) > self::MAX_CONFIG_BYTES ) {
			return null;
		}
		return array(
			'kind'   => $kind,
			'key'    => $key,
			'active' => ! array_key_exists( 'active', $item ) || ! empty( $item['active'] ),
			'config' => $item['config'],
		);
	}

	/** @return array<int,array{kind:string,key:string,active:bool,config:array,ver:int}> */
	public static function items( int $series_id ): array {
		global $wpdb;
		if ( ! Series::ready() ) {
			return array();
		}
		$t   = self::table();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, item_key, config, active, ver FROM {$t} WHERE series_id = %d ORDER BY kind ASC, item_key ASC", $series_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$config = json_decode( (string) $r['config'], true );
			$out[]  = array(
				'kind'   => (string) $r['kind'],
				'key'    => (string) $r['item_key'],
				'active' => 1 === (int) $r['active'],
				'config' => is_array( $config ) ? $config : array(),
				'ver'    => (int) $r['ver'],
			);
		}
		return $out;
	}

	/**
	 * 用一组活动整体替换这个系列的活动，并把 campaign_ver 加一。有一条无效就整体拒绝，不保存。
	 *
	 * @return int|\WP_Error 新的活动版本号
	 */
	public static function replace( int $series_id, array $items ) {
		global $wpdb;
		if ( ! Series::get( $series_id ) ) {
			return new \WP_Error( 'pchq_series_missing', '系列不存在。' );
		}
		$clean = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$c = self::clean_item( $item );
			if ( null === $c ) {
				return new \WP_Error( 'pchq_campaign_item', sprintf( '第 %d 条活动无效（类型、代号或内容不对），没有保存。', $i + 1 ) );
			}
			$id = $c['kind'] . '/' . $c['key'];
			if ( isset( $clean[ $id ] ) ) {
				return new \WP_Error( 'pchq_campaign_item', sprintf( '活动 %s 重复，没有保存。', $id ) );
			}
			$clean[ $id ] = $c;
		}
		// 内容没变（或者本来就没有活动、这次也是空的）不加版本号，避免各店白白重新同步。
		$series  = Series::get( $series_id );
		$current = array();
		foreach ( self::items( $series_id ) as $it ) {
			unset( $it['ver'] );
			$current[ $it['kind'] . '/' . $it['key'] ] = $it;
		}
		ksort( $current );
		$wanted = $clean;
		ksort( $wanted );
		if ( (string) wp_json_encode( $current ) === (string) wp_json_encode( $wanted ) ) {
			return (int) $series['campaign_ver'];
		}
		$ver = Series::bump( $series_id, 'campaign' );
		if ( $ver < 1 ) {
			return new \WP_Error( 'pchq_series_save', '活动版本号没能更新，请重试。' );
		}
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE series_id = %d", $series_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $clean as $c ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'series_id'  => $series_id,
					'kind'       => $c['kind'],
					'item_key'   => $c['key'],
					'config'     => (string) wp_json_encode( $c['config'] ),
					'active'     => $c['active'] ? 1 : 0,
					'ver'        => $ver,
					'updated_at' => time(),
				)
			);
		}
		return $ver;
	}

	/**
	 * /hq/series/sync 的 campaigns 部分。
	 *
	 * @return array{ver:int,items:array}
	 */
	public static function payload( int $series_id ): array {
		$series = Series::get( $series_id );
		$items  = array();
		foreach ( self::items( $series_id ) as $it ) {
			unset( $it['ver'] );
			$items[] = $it;
		}
		return array( 'ver' => $series ? $series['campaign_ver'] : 0, 'items' => $items );
	}
}
