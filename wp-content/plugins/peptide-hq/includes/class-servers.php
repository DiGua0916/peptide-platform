<?php
/**
 * 服务器（用户 2026-10-27：服务器由总后台分配，像 YY 一样；销售在店铺“域名管理”里添加域名时，看到的就是这里分配的 IP，
 * 自己去域名服务商解析）。总后台把分配的 IP 下发给店铺（Hq_Connector 的 config 接口）。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Servers {

	/** @return array<int,array{id:int,name:string,ip:string,note:string,created:int}> */
	public static function all(): array {
		global $wpdb;
		$t   = Db::table( 'servers' );
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name, id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $r['id'] ] = array(
				'id'      => (int) $r['id'],
				'name'    => (string) $r['name'],
				'ip'      => (string) $r['ip'],
				'note'    => (string) $r['note'],
				'created' => (int) $r['created'],
			);
		}
		return $out;
	}

	public static function get( int $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * 新建或修改服务器。改 IP 后，用这台服务器的店铺在下次检查时收到新 IP。
	 *
	 * @return int|\WP_Error 服务器 ID
	 */
	public static function save( int $id, string $name, string $ip, string $note ) {
		$ip = trim( $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error( 'pchq_ip', __( 'Enter the server’s IP address, for example 203.0.113.10.', 'peptide-hq' ) );
		}
		$data = array(
			'name' => mb_substr( '' !== trim( $name ) ? sanitize_text_field( $name ) : $ip, 0, 190 ),
			'ip'   => $ip,
			'note' => mb_substr( sanitize_text_field( $note ), 0, 255 ),
		);
		global $wpdb;
		$t = Db::table( 'servers' );
		if ( $id && self::get( $id ) ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$wpdb->insert( $t, $data + array( 'created' => time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * 删除服务器：只能删没有店铺在用的。
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( int $id ) {
		if ( self::store_count( $id ) ) {
			return new \WP_Error( 'pchq_in_use', __( 'Stores still use this server. Move them to another server first.', 'peptide-hq' ) );
		}
		global $wpdb;
		$wpdb->delete( Db::table( 'servers' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	public static function store_count( int $id ): int {
		global $wpdb;
		$t = Db::table( 'sites' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE server_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 店铺分配到的服务器 IP；没分配时为空。 */
	public static function ip_for_site( int $site_id ): string {
		global $wpdb;
		$s = Db::table( 'sites' );
		$v = Db::table( 'servers' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT v.ip FROM {$s} s INNER JOIN {$v} v ON v.id = s.server_id WHERE s.id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 给店铺分配服务器（0 = 不分配），并马上把 IP 下发给店铺。
	 *
	 * @return true|\WP_Error 下发失败时返回错误（分配已经保存，下次检查会再下发）
	 */
	public static function assign( int $site_id, int $server_id ) {
		if ( $server_id && ! self::get( $server_id ) ) {
			return new \WP_Error( 'pchq_server_missing', __( 'Server not found.', 'peptide-hq' ) );
		}
		Sites::update( $site_id, array( 'server_id' => $server_id ) );
		$res = Client::config( $site_id, array( 'server_ip' => self::ip_for_site( $site_id ) ) );
		return is_wp_error( $res ) ? $res : true;
	}
}
