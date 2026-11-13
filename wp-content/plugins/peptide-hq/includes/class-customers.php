<?php
/**
 * 客户编号（店主 2026-11-12）：店铺把“订单 → 客户编号”报给总后台，支付中心按客户合并同一个人的多次付款。
 *
 * - 编号由店铺按顾客邮箱（没有邮箱用手机号）算出，同一个邮箱在所有店铺都是同一个编号；总后台只存编号、打码邮箱和设备摘要，不存邮箱原文。
 * - 店铺签名请求 POST /peptide-hq/v1/customers/link（和邮件、物流同一套签名，Mail::verify）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Customers {

	private const DB_OPTION  = 'pchq_customer_links_db';
	private const DB_VERSION = '1';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_init', array( self::class, 'maybe_install' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pchq_customer_links';
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
 site_id bigint(20) unsigned NOT NULL,
 order_id bigint(20) unsigned NOT NULL,
 customer_id varchar(12) NOT NULL,
 device varchar(32) NOT NULL DEFAULT '',
 email_mask varchar(80) NOT NULL DEFAULT '',
 country varchar(4) NOT NULL DEFAULT '',
 updated_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (site_id,order_id),
 KEY customer_id (customer_id)
) " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	public static function routes(): void {
		register_rest_route(
			Register::NS,
			'/customers/link',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_link' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
	}

	public static function rest_link( \WP_REST_Request $r ) {
		self::maybe_install();
		if ( ! self::installed() ) {
			return new \WP_Error( 'pchq_customers_db', 'Customer table is not ready.', array( 'status' => 503 ) );
		}
		$site  = (int) $r->get_param( '_pchq_site' );
		$json  = json_decode( (string) $r->get_body(), true );
		$links = is_array( $json['links'] ?? null ) ? array_slice( $json['links'], 0, 200 ) : array();
		global $wpdb;
		$t = self::table();
		$n = 0;
		foreach ( $links as $l ) {
			$order = (int) ( $l['order_id'] ?? 0 );
			$cid   = strtoupper( (string) ( $l['customer_id'] ?? '' ) );
			if ( $order <= 0 || ! preg_match( '/\AC[0-9A-Z]{7}\z/', $cid ) ) {
				continue;
			}
			$device = preg_match( '/\A[a-f0-9]{16}\z/', (string) ( $l['device'] ?? '' ) ) ? (string) $l['device'] : '';
			$mask   = mb_substr( sanitize_text_field( (string) ( $l['email_mask'] ?? '' ) ), 0, 80 );
			$cc     = preg_match( '/\A[A-Z]{2}\z/', (string) ( $l['country'] ?? '' ) ) ? (string) $l['country'] : '';
			$ok     = $wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (site_id,order_id,customer_id,device,email_mask,country,updated_at) VALUES (%d,%d,%s,%s,%s,%s,%d) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),device=IF(VALUES(device)='',device,VALUES(device)),email_mask=VALUES(email_mask),country=VALUES(country),updated_at=VALUES(updated_at)", $site, $order, $cid, $device, $mask, $cc, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false !== $ok ) {
				++$n;
			}
		}
		return rest_ensure_response( array( 'saved' => $n ) );
	}

	/**
	 * 订单对应的客户。
	 *
	 * @param array<int,array{0:int,1:int}> $pairs [site_id, order_id]
	 * @return array<string,array{customer_id:string,device:string,email_mask:string,country:string}> 键 "site:order"
	 */
	public static function for_orders( array $pairs ): array {
		if ( ! $pairs || ! self::installed() ) {
			return array();
		}
		global $wpdb;
		$t    = self::table();
		$out  = array();
		$cond = array();
		foreach ( $pairs as $p ) {
			$cond[] = $wpdb->prepare( '(site_id=%d AND order_id=%d)', (int) $p[0], (int) $p[1] );
		}
		foreach ( array_chunk( $cond, 300 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT site_id,order_id,customer_id,device,email_mask,country FROM {$t} WHERE " . implode( ' OR ', $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['site_id'] . ':' . (int) $row['order_id'] ] = array(
					'customer_id' => (string) $row['customer_id'],
					'device'      => (string) $row['device'],
					'email_mask'  => (string) $row['email_mask'],
					'country'     => (string) $row['country'],
				);
			}
		}
		return $out;
	}
}
