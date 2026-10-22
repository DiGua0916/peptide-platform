<?php
/**
 * 邮件发送记录（EMAIL-CENTER-01），用于“邮件通知 → 邮件详情数据”。
 *
 * 所有 WooCommerce 邮件（包括本插件的）发送后都会触发 woocommerce_email_sent，这里记下邮件类型、收件人、标题、是否成功和时间。
 * 只保留 180 天。表 {prefix}pc_email_log。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

final class Log {

	public const DB_OPTION  = 'peptide_core_email_log_db';
	public const DB_VERSION = '1';
	public const KEEP_DAYS  = 180;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_email_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email_id varchar(64) NOT NULL,
  recipient varchar(255) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  ok tinyint(1) NOT NULL DEFAULT 1,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sent_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY email_time (email_id,sent_ms),
  KEY sent_ms (sent_ms)
) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	/**
	 * @param bool      $ok
	 * @param string    $id
	 * @param \WC_Email $email
	 */
	public static function record( $ok, $id, $email = null ): void {
		global $wpdb;
		if ( ! self::installed() ) {
			self::install();
		}
		$object = $email instanceof \WC_Email ? $email->object : null;
		$oid    = $object instanceof \WC_Order ? $object->get_id() : ( $object instanceof \WP_User ? $object->ID : 0 );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'email_id'  => substr( (string) $id, 0, 64 ),
				'recipient' => substr( $email instanceof \WC_Email ? (string) $email->get_recipient() : '', 0, 255 ),
				'subject'   => substr( $email instanceof \WC_Email ? wp_strip_all_tags( (string) $email->get_subject() ) : '', 0, 255 ),
				'ok'        => $ok ? 1 : 0,
				'object_id' => (int) $oid,
				'sent_ms'   => (int) floor( microtime( true ) * 1000 ),
			)
		);
		if ( 1 === wp_rand( 1, 200 ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE sent_ms < %d', (int) ( ( time() - self::KEEP_DAYS * DAY_IN_SECONDS ) * 1000 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/** @return array<string,array{sent:int,failed:int,last_ms:int}> 某时间之后按邮件类型统计。 */
	public static function stats( int $since_ms ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT email_id, SUM(ok = 1) AS sent, SUM(ok = 0) AS failed, MAX(sent_ms) AS last_ms FROM ' . self::table() . ' WHERE sent_ms >= %d GROUP BY email_id', $since_ms ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ $r['email_id'] ] = array( 'sent' => (int) $r['sent'], 'failed' => (int) $r['failed'], 'last_ms' => (int) $r['last_ms'] );
		}
		return $out;
	}

	/** 最近的记录（新的在前）。 */
	public static function recent( int $page = 1, int $per = 50, string $email_id = '' ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$where = '' !== $email_id ? $wpdb->prepare( 'WHERE email_id = %s', $email_id ) : '';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY sent_ms DESC, id DESC LIMIT %d OFFSET %d", $per, max( 0, ( $page - 1 ) * $per ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'items' => $rows, 'total' => $total );
	}
}
