<?php
/**
 * 访问的广告参数（M6“按广告拆分”）：落地页地址里的 utm_campaign / utm_term / utm_content，
 * 对应 Facebook 投放链接里的广告系列 / 广告组 / 广告（投手后台生成的链接固定带这三项，见 CONTRACT 里 F5 的 ad-link）。
 *
 * 存在自己的一张表 {prefix}pc_track_ads 里（一次访问最多一行），有自己的版本选项 peptide_core_track_ads_db，
 * 不动访问表的结构和它的版本号——这张表没建好或出了问题，只是广告参数存不上，访客追踪和来源归因照常。
 * 和来源归因一样：一次访问第一次成功记下来的值就是它的值，之后不改。
 *
 * 不含顾客资料：只有访问编号和三个投放名称。隐私抹除、超期清理删访问时这里的行跟着删。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Ad_Params {

	public const OPTION     = 'peptide_core_track_ads_db';
	public const DB_VERSION = '1';

	/** 三个参数 => 报表里的中文名。 */
	public const LABELS = array( 'utm_campaign' => '广告系列', 'utm_term' => '广告组', 'utm_content' => '广告' );

	public static function register_hooks(): void {
		// 自己的升级：只比较一个自动加载的选项，装好之后每个请求零查询。
		add_action( 'init', array( self::class, 'maybe_install' ), 6 );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_track_ads';
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::OPTION );
	}

	/** 访客追踪的表装好之后才建这张表。 */
	public static function maybe_install(): void {
		if ( ! self::installed() && Schema::installed() ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  visit_id bigint(20) unsigned NOT NULL,
  utm_campaign varchar(120) NULL,
  utm_term varchar(120) NULL,
  utm_content varchar(120) NULL,
  captured_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (visit_id)
) {$wpdb->get_charset_collate()};"
		);
		// 表真的在了才记版本号。
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			update_option( self::OPTION, self::DB_VERSION, true );
		}
	}

	/**
	 * 一个值能不能当投放名称存下来：非空字符串、合法 UTF-8、没有控制字符、不超过 120 个字符，
	 * 也不是没被 Facebook 替换掉的占位符（{{campaign.name}} 这样）。纯函数。
	 */
	public static function valid( $value ): bool {
		return is_string( $value ) && '' !== $value && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x1f\x7f]/', $value )
			&& preg_match_all( '/./us', $value ) <= 120 && ! preg_match( '/\{\{.*\}\}/s', $value );
	}

	/**
	 * 从落地页参数里挑出合规的广告参数（纯函数）。不合规的只丢那一项。
	 *
	 * @param array<string,mixed> $query
	 * @return array<string,string>
	 */
	public static function pick( array $query ): array {
		$out = array();
		foreach ( array_keys( self::LABELS ) as $key ) {
			if ( array_key_exists( $key, $query ) && self::valid( $query[ $key ] ) ) {
				$out[ $key ] = $query[ $key ];
			}
		}
		return $out;
	}

	/**
	 * 记下一次访问的广告参数。访问已经有一行时不改（第一次记下的算数）。表没装好或一项都没有时什么都不做。
	 *
	 * @param array<string,mixed> $landing_query Attribution::clean() 之后的落地页参数。
	 */
	public static function store( int $visit_id, array $landing_query, int $ms ): bool {
		global $wpdb;
		$values = self::pick( $landing_query );
		if ( $visit_id <= 0 || ! $values || ! self::installed() ) {
			return false;
		}
		$cells = array();
		foreach ( array_keys( self::LABELS ) as $key ) {
			$cells[] = isset( $values[ $key ] ) ? $wpdb->prepare( '%s', $values[ $key ] ) : 'NULL';
		}
		$sql = 'INSERT IGNORE INTO ' . self::table() . ' (visit_id, utm_campaign, utm_term, utm_content, captured_ms) VALUES (' . $wpdb->prepare( '%d', $visit_id ) . ', ' . implode( ', ', $cells ) . ', ' . $wpdb->prepare( '%d', $ms ) . ')';
		return 1 === $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 这些访问的广告参数：访问编号 => [ utm_campaign, utm_term, utm_content ]（没有的项是 null）。没有记录的访问不在结果里。
	 *
	 * @param int[] $visit_ids
	 * @return array<int,array<string,?string>>
	 */
	public static function for_visits( array $visit_ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $visit_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$out = array();
		if ( ! $ids || ! self::installed() ) {
			return $out;
		}
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in   = implode( ',', $chunk );
			$rows = $wpdb->get_results( 'SELECT visit_id, utm_campaign, utm_term, utm_content FROM ' . self::table() . " WHERE visit_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$out[ (int) $row['visit_id'] ] = array( 'utm_campaign' => $row['utm_campaign'], 'utm_term' => $row['utm_term'], 'utm_content' => $row['utm_content'] );
			}
		}
		return $out;
	}

	/**
	 * 报表用的带状态的值（和来源、媒介同一个格式）：value 有值；missing 这次访问记了来源、但地址里没有这一项；
	 * unobserved 这次访问没有记下来源信息。纯函数。
	 *
	 * @param array<string,?string>|null $row      for_visits() 里这次访问的那一行。
	 * @param bool                       $captured 这次访问有没有记下来源信息。
	 * @return array<string,array{state:string,value:?string}>
	 */
	public static function typed( ?array $row, bool $captured ): array {
		$out = array();
		foreach ( array_keys( self::LABELS ) as $key ) {
			$value       = is_array( $row ) ? ( $row[ $key ] ?? null ) : null;
			$out[ $key ] = null !== $value && '' !== $value
				? array( 'state' => 'value', 'value' => (string) $value )
				: array( 'state' => $captured ? 'missing' : 'unobserved', 'value' => null );
		}
		return $out;
	}

	/**
	 * 删访问时跟着删（隐私抹除、超期清理）。
	 *
	 * @param int[] $visit_ids
	 */
	public static function delete_for_visits( array $visit_ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $visit_ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( ! $ids || ! self::installed() ) {
			return;
		}
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE visit_id IN (' . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * 删这些访客的全部访问对应的行（在访问被删之前调用）。
	 *
	 * @param int[] $visitor_ids
	 */
	public static function delete_for_visitors( array $visitor_ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $visitor_ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( ! $ids || ! self::installed() ) {
			return;
		}
		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE visit_id IN (SELECT id FROM ' . Schema::table( 'visits' ) . ' WHERE visitor_id IN (' . implode( ',', $ids ) . '))' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
