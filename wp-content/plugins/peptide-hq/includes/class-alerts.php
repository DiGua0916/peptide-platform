<?php
/**
 * 告警：店铺连不上、连接码失效、域名或 HTTPS 出问题、很久没有订单、店铺没对外开放。
 * 同一个店铺的同一种问题只开一条；问题消失后自动标记“已恢复”。新告警发邮件给总后台的管理员邮箱。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Alerts {

	public static function types(): array {
		return array(
			'offline'   => __( 'Store unreachable', 'peptide-hq' ),
			'denied'    => __( 'Connection code no longer valid', 'peptide-hq' ),
			'domain'    => __( 'Domain not connected', 'peptide-hq' ),
			'https'     => __( 'HTTPS not working', 'peptide-hq' ),
			'no_orders' => __( 'No orders for a while', 'peptide-hq' ),
			'closed'    => __( 'Store not open to the public', 'peptide-hq' ),
		);
	}

	/** 开一条告警；同一店铺、同一类型、同一对象已经有未处理的就不再开。返回是否新开了。 */
	public static function raise( int $site_id, string $type, string $ref, string $message ): bool {
		global $wpdb;
		$t = Db::table( 'alerts' );
		$open = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE site_id = %d AND type = %s AND ref = %s AND resolved = 0", $site_id, $type, $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $open ) {
			$wpdb->update( $t, array( 'message' => mb_substr( $message, 0, 255 ) ), array( 'id' => (int) $open ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'site_id' => $site_id,
				'type'    => $type,
				'ref'     => mb_substr( $ref, 0, 190 ),
				'message' => mb_substr( $message, 0, 255 ),
				'created' => time(),
			)
		);
		self::notify( $site_id, $type, $message );
		return true;
	}

	/**
	 * 问题消失：把这类未处理的告警标记为已恢复。$keep 里的对象（例如还有问题的域名）不动。
	 *
	 * @param string[] $keep
	 */
	public static function resolve( int $site_id, string $type, array $keep = array() ): void {
		global $wpdb;
		$t = Db::table( 'alerts' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, ref FROM {$t} WHERE site_id = %d AND type = %s AND resolved = 0", $site_id, $type ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! in_array( (string) $r['ref'], $keep, true ) ) {
				$wpdb->update( $t, array( 'resolved' => time() ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/** 手动标记为已处理。 */
	public static function close( int $id ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'alerts' ), array( 'resolved' => time() ), array( 'id' => $id, 'resolved' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function open_count(): int {
		global $wpdb;
		$t = Db::table( 'alerts' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE resolved = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array<int,array>
	 */
	public static function list( bool $open, int $limit = 200 ): array {
		global $wpdb;
		$t   = Db::table( 'alerts' );
		$sql = $open ? "SELECT * FROM {$t} WHERE resolved = 0 ORDER BY created DESC LIMIT %d" : "SELECT * FROM {$t} WHERE resolved > 0 ORDER BY resolved DESC LIMIT %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private static function notify( int $site_id, string $type, string $message ): void {
		$site = Sites::get( $site_id );
		$to   = (string) get_option( 'admin_email' );
		if ( ! $site || ! is_email( $to ) ) {
			return;
		}
		$label = self::types()[ $type ] ?? $type;
		/* translators: 1: store name, 2: alert type */
		$subject = sprintf( __( '[Master admin] %1$s: %2$s', 'peptide-hq' ), $site['name'], $label );
		$body    = $message . "\n\n" . $site['url'] . "\n" . admin_url( 'admin.php?page=pchq-alerts' );
		wp_mail( $to, $subject, $body );
	}
}
