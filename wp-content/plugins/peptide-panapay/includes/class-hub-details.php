<?php
/**
 * 支付方返回参数记录（2026-11-08）。
 *
 * 记录每笔付款里支付方实际返回的内容：创建付款的响应（失败时）、付款结果通知、顾客跳回时带的参数。
 * 只用于总后台“支付中心”查看失败原因，绝不参与付款判断。任何读写失败都静默忽略，不影响收款。
 * 签名、密钥、口令等字段打码；内容加密保存；每笔付款最多保留 20 条。
 *
 * @package VialCove\Panapay
 */

namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Hub_Details {
	private const VERSION  = '1';
	private const OPTION   = 'pc_panapay_hub_details_db';
	private const PER_REF  = 20;
	private const MAX_KEYS = 60;
	private const MASK     = '/(sign|secret|password|passwd|pwd|token|api_?key|private|cvv|cvc|card_?number|card_?no|pan)$/i';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_pay_hub_details';
	}

	public static function ready(): bool {
		if ( self::VERSION === get_option( self::OPTION ) ) { return true; }
		try {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$t = self::table();
			dbDelta( "CREATE TABLE {$t} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 ref char(34) NOT NULL,
 kind varchar(12) NOT NULL,
 status varchar(32) NOT NULL DEFAULT '',
 params_box mediumtext NOT NULL,
 created_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (id),
 KEY ref_time (ref,created_at)
) {$wpdb->get_charset_collate()};" );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return false; }
			update_option( self::OPTION, self::VERSION, false );
			return true;
		} catch ( \Throwable $e ) { return false; }
	}

	/**
	 * @param string $kind create | notify | return
	 * @param mixed  $params 支付方返回的原始内容（数组或文本）
	 */
	public static function record( string $ref, string $kind, string $status, $params ): void {
		try {
			if ( ! preg_match( '/\A(?:PH[a-f0-9]{32}|hang-[0-9]{1,13})\z/D', $ref ) || ! in_array( $kind, array( 'create', 'notify', 'return' ), true ) || ! self::ready() ) { return; }
			$flat = is_array( $params ) ? self::flatten( $params ) : array( 'body' => self::text( (string) $params, 2000 ) );
			global $wpdb;
			$t = self::table();
			$wpdb->insert( $t, array(
				'ref'        => $ref,
				'kind'       => $kind,
				'status'     => self::text( $status, 32 ),
				'params_box' => Crypto::encrypt( (string) wp_json_encode( $flat ), 'hub-details' ),
				'created_at' => time(),
			), array( '%s', '%s', '%s', '%s', '%d' ) );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE ref=%s ORDER BY id DESC LIMIT 1000 OFFSET %d", $ref, self::PER_REF ) );
			if ( $ids ) { $wpdb->query( "DELETE FROM {$t} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); }
		} catch ( \Throwable $e ) { /* 记录失败不影响收款。 */ }
	}

	/** @return array<int, array{kind:string,status:string,at:int,params:array}> 新的在前 */
	public static function for_ref( string $ref ): array {
		try {
			if ( ! preg_match( '/\A(?:PH[a-f0-9]{32}|hang-[0-9]{1,13})\z/D', $ref ) || self::VERSION !== get_option( self::OPTION ) ) { return array(); }
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind,status,params_box,created_at FROM ' . self::table() . ' WHERE ref=%s ORDER BY id DESC LIMIT %d', $ref, self::PER_REF ), ARRAY_A );
			$out = array();
			foreach ( (array) $rows as $r ) {
				try { $params = json_decode( Crypto::decrypt( (string) $r['params_box'], 'hub-details' ), true ); }
				catch ( \Throwable $e ) { $params = array( '（无法读取）' => '' ); }
				$out[] = array( 'kind' => (string) $r['kind'], 'status' => (string) $r['status'], 'at' => (int) $r['created_at'], 'params' => is_array( $params ) ? $params : array() );
			}
			return $out;
		} catch ( \Throwable $e ) { return array(); }
	}

	/** @param string[] $refs @return array<string,bool> 有记录的付款 */
	public static function refs_with_details( array $refs ): array {
		try {
			$refs = array_values( array_filter( $refs, static fn( $r ): bool => is_string( $r ) && 1 === preg_match( '/\A(?:PH[a-f0-9]{32}|hang-[0-9]{1,13})\z/D', $r ) ) );
			if ( ! $refs || self::VERSION !== get_option( self::OPTION ) ) { return array(); }
			global $wpdb;
			$in  = implode( ',', array_fill( 0, count( $refs ), '%s' ) );
			$hit = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT ref FROM ' . self::table() . " WHERE ref IN ({$in})", $refs ) );
			return array_fill_keys( (array) $hit, true );
		} catch ( \Throwable $e ) { return array(); }
	}

	private static function flatten( array $data, string $prefix = '', int $depth = 0, array &$out = array() ): array {
		foreach ( $data as $key => $value ) {
			if ( count( $out ) >= self::MAX_KEYS ) { $out['…'] = '（字段过多，已截断）'; break; }
			$name = self::text( '' === $prefix ? (string) $key : $prefix . '.' . $key, 120 );
			if ( is_array( $value ) && $depth < 3 ) { self::flatten( $value, $name, $depth + 1, $out ); continue; }
			if ( is_array( $value ) ) { $value = '（嵌套过深）'; }
			elseif ( is_bool( $value ) ) { $value = $value ? 'true' : 'false'; }
			elseif ( null === $value ) { $value = 'null'; }
			$value = self::text( (string) $value, 1000 );
			$out[ $name ] = preg_match( self::MASK, (string) $key ) && '' !== $value ? '***' : $value;
		}
		return $out;
	}

	private static function text( string $value, int $limit ): string {
		$value = wp_check_invalid_utf8( $value, true );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}
}
